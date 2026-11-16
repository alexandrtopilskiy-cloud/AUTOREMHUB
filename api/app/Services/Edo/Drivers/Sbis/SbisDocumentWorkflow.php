<?php

declare(strict_types=1);

namespace App\Services\Edo\Drivers\Sbis;

use App\Enums\Edo\EdoDocumentStatus;
use App\Enums\Edo\EdoProvider;
use App\Exceptions\ApiException;
use App\Models\EdoDocument;
use App\Models\Organization;
use Illuminate\Support\Facades\Log;

/**
 * Документооборот СБИС по документу: подписание УКЕП на стороне браузера
 * (КриптоПро ЭЦП Browser plug-in) и обработка изменений.
 *
 *   1. prepare(): СБИС.ПодготовитьДействие — сервер СБИС формирует вложения
 *      этапа (у отправителя — «Отправка/Отправить», у получателя — этап
 *      утверждения), их содержимое уходит в браузер на подпись.
 *   2. execute(): СБИС.ВыполнитьДействие с подписями вложений — документ
 *      уходит получателю (или утверждается получателем).
 *   3. applyChange(): документ из СБИС.СписокИзменений — обновление статуса,
 *      а у получателя — привязка его идентификатора документа.
 */
final class SbisDocumentWorkflow
{
    public const SENDER = 'sender';
    public const RECEIVER = 'receiver';

    /** Порядок статусов: более поздний этап не откатывается более ранним. */
    private const RANK = [
        'draft' => 0, 'to_sign' => 1, 'prepared' => 1, 'waiting_for_send' => 2,
        'sended' => 3, 'delivered' => 4, 'received' => 5, 'on_agreement' => 5,
        'clarification_requested' => 6, 'unknown' => 0,
        'signed' => 8, 'accepted' => 8, 'rejected' => 8,
        'requested_avoidance' => 9, 'on_revocation' => 9, 'rejected_avoidance' => 9,
        'accepted_avoidance' => 10, 'revoked' => 10,
    ];

    public function __construct(
        private readonly SbisClientFactory $clients,
    ) {}

    /** Подписание отключено заглушкой (EDO_SIGNING=stub). */
    public static function signingStubbed(): bool
    {
        return config('edo.signing', 'stub') !== 'cryptopro';
    }

    /**
     * Заглушка подписания: ПодготовитьДействие в СБИС выполнен (prepare()),
     * подпись и ВыполнитьДействие пропускаются, результат проверки — в meta.
     *
     * @param array{side: string, action: string, stage: string, attachments: array<int, array{id: string, name: string, content: string}>} $prepared
     *
     * @return array<string, mixed>
     */
    public function stub(EdoDocument $document, array $prepared): array
    {
        $check = [
            'at'          => now()->toISOString(),
            'side'        => $prepared['side'],
            'action'      => $prepared['action'],
            'stage'       => $prepared['stage'],
            'attachments' => array_map(
                fn (array $a): array => ['id' => $a['id'], 'name' => $a['name'], 'bytes' => (int) floor(strlen($a['content']) * 3 / 4)],
                $prepared['attachments'],
            ),
        ];

        $meta = $this->meta($document);
        unset($meta['sign']);
        $meta['sign_check'] = $check;
        $document->forceFill(['meta' => $meta])->save();

        Log::channel('edo')->info("SBIS: заглушка подписи {$document->number} — СБИС подготовил «{$check['action']}», подпись и отправка пропущены", ['side' => $check['side'], 'attachments' => count($check['attachments'])]);

        return $check;
    }

    /** Сторона документооборота организации: отправитель или получатель. */
    public static function side(EdoDocument $document, int $organizationId): ?string
    {
        return match (true) {
            $document->organization_id === $organizationId => self::SENDER,
            $document->counterparty_id === $organizationId => self::RECEIVER,
            default => null,
        };
    }

    /**
     * Подготовка подписания. Возвращает вложения, которые нужно подписать.
     *
     * @return array{side: string, action: string, stage: string, attachments: array<int, array{id: string, name: string, content: string}>}
     */
    public function prepare(EdoDocument $document, Organization $actor): array
    {
        $side = $this->assertSbis($document, $actor);
        $documentId = $this->documentId($document, $side);
        $client = $this->clients->make($actor);

        $stage = $side === self::SENDER
            ? ['Название' => 'Отправка', 'Действие' => [['Название' => 'Отправить']]]
            : $this->receiverStage($client->readDocument($documentId));

        $prepared = $client->request('СБИС.ПодготовитьДействие', [
            'Документ' => ['Идентификатор' => $documentId, 'Этап' => $stage],
        ]);

        $preparedStage = $this->first($prepared['Этап'] ?? null) ?? [];
        $stageId = (string) ($preparedStage['Идентификатор'] ?? ($stage['Идентификатор'] ?? ''));
        $stageName = (string) ($preparedStage['Название'] ?? $stage['Название'] ?? '');
        $action = (string) ($this->first($preparedStage['Действие'] ?? null)['Название'] ?? $stage['Действие'][0]['Название']);

        $attachments = [];
        foreach ($this->list($preparedStage['Вложение'] ?? ($prepared['Вложение'] ?? [])) as $attachment) {
            $id = (string) ($attachment['Идентификатор'] ?? '');
            $file = is_array($attachment['Файл'] ?? null) ? $attachment['Файл'] : [];
            $content = (string) ($file['ДвоичныеДанные'] ?? '');
            if ($content === '' && ($file['Ссылка'] ?? '') !== '') {
                $content = $client->downloadBase64((string) $file['Ссылка']);
            }
            if ($id === '' || $content === '') {
                continue;
            }
            $attachments[] = ['id' => $id, 'name' => (string) ($file['Имя'] ?? $attachment['Название'] ?? $id), 'content' => $content];
        }

        if ($attachments === []) {
            throw new ApiException(422, 'EDO_NOTHING_TO_SIGN', 'СБИС не вернул вложений для подписи. Проверьте документ в СБИС.');
        }

        $document->forceFill(['meta' => [
            ...$this->meta($document),
            'sign' => [
                'side'        => $side,
                'documentId'  => $documentId,
                'stageId'     => $stageId,
                'stageName'   => $stageName,
                'action'      => $action,
                'attachments' => array_column($attachments, 'id'),
                'preparedAt'  => now()->toISOString(),
            ],
        ]])->save();

        Log::channel('edo')->info("SBIS: подготовлено «{$action}» для {$document->number}", ['side' => $side, 'attachments' => count($attachments)]);

        return ['side' => $side, 'action' => $action, 'stage' => $stageName, 'attachments' => $attachments];
    }

    /**
     * Выполнение действия с подписями вложений (base64 открепленной CMS).
     *
     * @param array<string, string> $signatures идентификатор вложения → подпись
     */
    public function execute(EdoDocument $document, Organization $actor, array $signatures): EdoDocument
    {
        $side = $this->assertSbis($document, $actor);
        $sign = $this->meta($document)['sign'] ?? null;

        if (!is_array($sign) || ($sign['side'] ?? null) !== $side) {
            throw new ApiException(422, 'EDO_NOT_PREPARED', 'Документ не подготовлен к подписанию. Нажмите кнопку ещё раз.');
        }

        $missing = array_diff((array) $sign['attachments'], array_keys(array_filter($signatures)));
        if ($missing !== []) {
            throw new ApiException(422, 'EDO_SIGNATURE_MISSING', 'Подписаны не все вложения документа.');
        }

        $stage = array_filter([
            'Идентификатор' => (string) ($sign['stageId'] ?? '') ?: null,
            'Название'      => (string) ($sign['stageName'] ?? '') ?: null,
            'Действие'      => [['Название' => (string) $sign['action']]],
            'Вложение'      => array_map(
                fn (string $id): array => ['Идентификатор' => $id, 'Подпись' => [['Файл' => ['ДвоичныеДанные' => $signatures[$id]]]]],
                array_values((array) $sign['attachments']),
            ),
        ], fn ($value) => $value !== null);

        $result = $this->clients->make($actor)->request('СБИС.ВыполнитьДействие', [
            'Документ' => ['Идентификатор' => (string) $sign['documentId'], 'Этап' => $stage],
        ]);

        $state = is_array($result['Состояние'] ?? null) ? $result['Состояние'] : [];
        $status = self::statusFromState((string) ($state['Название'] ?? ''), $side)
            ?? ($side === self::SENDER ? EdoDocumentStatus::Sended : EdoDocumentStatus::Signed);

        $meta = $this->meta($document);
        unset($meta['sign']);
        $meta['sbis_state'] = self::stateMeta($state);
        $meta[$side . '_signed_at'] = now()->toISOString();
        if ($side === self::RECEIVER && is_string($result['СсылкаДляНашаОрганизация'] ?? null)) {
            $meta['receiver_url'] = $result['СсылкаДляНашаОрганизация'];
        }

        $this->applyStatus($document, $status);
        $document->forceFill([
            'meta'           => $meta,
            'sent_at'        => $side === self::SENDER ? ($document->sent_at ?? now()) : $document->sent_at,
            'signed_at'      => $side === self::RECEIVER ? now() : $document->signed_at,
            'last_synced_at' => now(),
        ])->save();

        Log::channel('edo')->info("SBIS: выполнено «{$sign['action']}» для {$document->number}", ['side' => $side, 'status' => $document->status->value]);

        return $document;
    }

    /**
     * Документ из СБИС.СписокИзменений для организации $organization.
     *
     * @param array<string, mixed> $sbisDocument
     */
    public function applyChange(Organization $organization, array $sbisDocument): ?EdoDocument
    {
        $externalId = (string) ($sbisDocument['Идентификатор'] ?? '');
        if ($externalId === '') {
            return null;
        }

        $document = EdoDocument::query()
            ->where('provider', EdoProvider::Sbis->value)
            ->where(fn ($q) => $q
                ->where('external_id', $externalId)
                ->orWhereRaw("meta->>'receiver_external_id' = ?", [$externalId]))
            ->first();

        $incoming = mb_strtolower((string) ($sbisDocument['Направление'] ?? '')) === 'входящий';
        if ($document === null && $incoming && ($sbisDocument['Номер'] ?? '') !== '') {
            // Получатель видит документ под своим идентификатором — находим по номеру.
            $document = EdoDocument::query()
                ->where('provider', EdoProvider::Sbis->value)
                ->where('counterparty_id', $organization->id)
                ->where('number', (string) $sbisDocument['Номер'])
                ->whereNotNull('external_id')
                ->orderByDesc('id')
                ->first();
        }

        if ($document === null) {
            return null;
        }

        $side = self::side($document, $organization->id) ?? ($incoming ? self::RECEIVER : self::SENDER);
        $state = is_array($sbisDocument['Состояние'] ?? null) ? $sbisDocument['Состояние'] : [];
        $meta = $this->meta($document);

        if ($side === self::RECEIVER && $externalId !== $document->external_id) {
            $meta['receiver_external_id'] = $externalId;
        }
        $link = $sbisDocument['СсылкаДляНашаОрганизация'] ?? null;
        if (is_string($link) && $link !== '') {
            $meta[$side === self::RECEIVER ? 'receiver_url' : 'url'] = $link;
        }
        $meta['sbis_state'] = self::stateMeta($state);
        $meta['events'] = $this->mergeEvents((array) ($meta['events'] ?? []), $this->list($sbisDocument['Событие'] ?? []), $side);

        $status = self::statusFromState((string) ($state['Название'] ?? ''), $side);
        if ($status !== null) {
            $this->applyStatus($document, $status);
        }

        $document->forceFill(['meta' => $meta, 'last_synced_at' => now()])->save();

        return $document;
    }

    /**
     * Статус по названию состояния документа СБИС (коды состояний СБИС
     * в документации не перечислены, поэтому — по тексту; сырое состояние
     * сохраняется в meta.sbis_state).
     */
    public static function statusFromState(string $name, string $side): ?EdoDocumentStatus
    {
        $text = mb_strtolower(trim($name));

        return match (true) {
            $text === '' => null,
            str_contains($text, 'аннулир') => str_contains($text, 'предлож') || str_contains($text, 'запрос')
                ? EdoDocumentStatus::RequestedAvoidance
                : EdoDocumentStatus::AcceptedAvoidance,
            str_contains($text, 'отозван') => EdoDocumentStatus::Revoked,
            str_contains($text, 'отклон') || str_contains($text, 'отказ') => EdoDocumentStatus::Rejected,
            str_contains($text, 'уточнен') => EdoDocumentStatus::ClarificationRequested,
            str_contains($text, 'ошибк') => null,
            str_contains($text, 'ожида') || str_contains($text, 'требует') || str_contains($text, 'на подпис') || str_contains($text, 'на утвержд') => $side === self::RECEIVER
                ? EdoDocumentStatus::Received
                : EdoDocumentStatus::Delivered,
            str_contains($text, 'завершен') || str_contains($text, 'утвержд') || str_contains($text, 'принят')
                || str_contains($text, 'подписан контрагент') || str_contains($text, 'подписан получател') => EdoDocumentStatus::Signed,
            str_contains($text, 'получен') || str_contains($text, 'прочитан') => EdoDocumentStatus::Received,
            str_contains($text, 'доставлен') => EdoDocumentStatus::Delivered,
            str_contains($text, 'отправ') => EdoDocumentStatus::Sended,
            str_contains($text, 'черновик') || str_contains($text, 'подготов') => EdoDocumentStatus::Draft,
            default => null,
        };
    }

    private function applyStatus(EdoDocument $document, EdoDocumentStatus $status): void
    {
        $current = $document->status instanceof EdoDocumentStatus ? $document->status->value : 'draft';
        if ((self::RANK[$status->value] ?? 0) < (self::RANK[$current] ?? 0)) {
            return;
        }

        $document->status = $status;
        $now = now();
        match ($status) {
            EdoDocumentStatus::Sended => $document->sent_at ??= $now,
            EdoDocumentStatus::Delivered, EdoDocumentStatus::Received => $document->delivered_at ??= $now,
            EdoDocumentStatus::Signed, EdoDocumentStatus::Accepted => $document->accepted_at ??= $now,
            EdoDocumentStatus::Rejected => $document->rejected_at ??= $now,
            default => null,
        };
    }

    /**
     * Этап получателя из СБИС.ПрочитатьДокумент: первый этап с действием
     * утверждения/подписания.
     *
     * @param array<string, mixed> $read
     *
     * @return array<string, mixed>
     */
    private function receiverStage(array $read): array
    {
        foreach ($this->list($read['Этап'] ?? []) as $stage) {
            $actions = array_values(array_filter(
                array_map(fn ($a) => (string) ($a['Название'] ?? ''), $this->list($stage['Действие'] ?? [])),
                fn (string $name) => $name !== '' && !preg_match('/отклон|отказ|аннулир|уточн/iu', $name),
            ));
            if ($actions === []) {
                continue;
            }
            $preferred = array_values(array_filter($actions, fn (string $name) => (bool) preg_match('/утверд|подпис|приня/iu', $name)));

            return array_filter([
                'Идентификатор' => (string) ($stage['Идентификатор'] ?? '') ?: null,
                'Название'      => (string) ($stage['Название'] ?? '') ?: null,
                'Действие'      => [['Название' => $preferred[0] ?? $actions[0]]],
            ], fn ($value) => $value !== null);
        }

        throw new ApiException(422, 'EDO_NO_ACTION', 'В СБИС по документу нет этапа, ожидающего подписи получателя.');
    }

    private function assertSbis(EdoDocument $document, Organization $actor): string
    {
        if ($document->provider !== EdoProvider::Sbis || $document->external_id === null) {
            throw new ApiException(422, 'EDO_NOT_SBIS', 'Документ не записан в СБИС.');
        }

        $side = self::side($document, $actor->id);
        if ($side === null) {
            throw new ApiException(403, 'FORBIDDEN', 'Документ не относится к вашей организации.');
        }

        if ((string) ($actor->edo_client_id ?? '') === '') {
            throw new ApiException(422, 'EDO_NOT_CONNECTED', 'Подключите СБИС: «Документы ЭДО» → ⚙️ (логин и пароль).');
        }

        if ($side === self::RECEIVER && ($this->meta($document)['receiver_external_id'] ?? null) === null) {
            // Если получатель в том же аккаунте СБИС, идентификатор совпадает;
            // иначе он появится после синхронизации изменений.
            Log::channel('edo')->info("SBIS: у получателя нет своего идентификатора {$document->number}, используем идентификатор отправителя");
        }

        return $side;
    }

    private function documentId(EdoDocument $document, string $side): string
    {
        $receiverId = $this->meta($document)['receiver_external_id'] ?? null;

        return $side === self::RECEIVER && is_string($receiverId) && $receiverId !== ''
            ? $receiverId
            : (string) $document->external_id;
    }

    /**
     * @param array<int, array<string, mixed>> $known
     * @param array<int, array<string, mixed>> $events
     *
     * @return array<int, array<string, mixed>>
     */
    private function mergeEvents(array $known, array $events, string $side): array
    {
        foreach ($events as $event) {
            $id = (string) ($event['Идентификатор'] ?? '');
            $known[$id !== '' ? $id : md5(json_encode($event, JSON_UNESCAPED_UNICODE) ?: '')] = [
                'id'   => $id,
                'name' => (string) ($event['Название'] ?? ''),
                'at'   => (string) ($event['ДатаВремя'] ?? ''),
                'side' => $side,
            ];
        }

        return array_slice($known, -20, null, true);
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array<string, string>
     */
    private static function stateMeta(array $state): array
    {
        return [
            'code' => (string) ($state['Код'] ?? ''),
            'name' => (string) ($state['Название'] ?? ''),
            'note' => (string) ($state['Примечание'] ?? ($state['Комментарий'] ?? '')),
        ];
    }

    /** @return array<string, mixed> */
    private function meta(EdoDocument $document): array
    {
        return is_array($document->meta) ? $document->meta : [];
    }

    /**
     * СБИС отдаёт «массив из одного объекта» то массивом, то объектом.
     *
     * @return array<int, array<string, mixed>>
     */
    private function list(mixed $value): array
    {
        if (!is_array($value) || $value === []) {
            return [];
        }

        return array_is_list($value) ? array_values(array_filter($value, 'is_array')) : [$value];
    }

    /** @return array<string, mixed>|null */
    private function first(mixed $value): ?array
    {
        return $this->list($value)[0] ?? null;
    }
}
