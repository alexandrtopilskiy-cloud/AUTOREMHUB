<?php

declare(strict_types=1);

namespace App\Services\Edo\Drivers\Sbis;

use App\Models\Organization;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Получение событий документооборота СБИС (СБИС.СписокИзменений) по
 * организации. Позиция (последнее событие) хранится в кеше, при первом
 * запуске берутся события за последние 7 дней.
 */
final class SbisChangesPuller
{
    private const PAGE_SIZE = 50;
    private const MAX_PAGES = 20;

    public function __construct(
        private readonly SbisClientFactory $clients,
        private readonly SbisDocumentWorkflow $workflow,
    ) {}

    /**
     * @return array{documents: int, matched: int}
     */
    public function pull(Organization $organization): array
    {
        $client = $this->clients->make($organization);
        $cursor = (array) Cache::get(self::cursorKey($organization), []);
        $documents = 0;
        $matched = 0;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $filter = array_filter([
                'ДатаВремяС'             => $cursor['at'] ?? now()->subDays(7)->format('d.m.Y H.i.s'),
                'ИдентификаторСобытия'   => $cursor['eventId'] ?? null,
                'ИдентификаторДокумента' => $cursor['documentId'] ?? null,
                'НашаОрганизация'        => self::party($organization),
                'ПолныйСертификатЭП'     => 'Нет',
                'Навигация'              => ['РазмерСтраницы' => (string) self::PAGE_SIZE],
            ], fn ($value) => $value !== null);

            $result = $client->request('СБИС.СписокИзменений', ['Фильтр' => $filter]);
            $rows = $result['Документ'] ?? [];
            $rows = is_array($rows) ? (array_is_list($rows) ? $rows : [$rows]) : [];

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $documents++;
                if ($this->workflow->applyChange($organization, $row) !== null) {
                    $matched++;
                }
                $cursor = $this->advance($cursor, $row);
            }

            Cache::forever(self::cursorKey($organization), $cursor);

            if ($rows === [] || mb_strtolower((string) ($result['Навигация']['ЕстьЕще'] ?? 'Нет')) !== 'да') {
                break;
            }
        }

        if ($documents > 0) {
            Log::channel('edo')->info("SBIS: изменения организации {$organization->id}", ['documents' => $documents, 'matched' => $matched]);
        }

        return ['documents' => $documents, 'matched' => $matched];
    }

    public static function cursorKey(Organization $organization): string
    {
        return "edo:sbis:changes:{$organization->id}";
    }

    /**
     * Последнее событие документа становится позицией для следующего запроса.
     *
     * @param array<string, mixed> $cursor
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function advance(array $cursor, array $row): array
    {
        $events = $row['Событие'] ?? [];
        $events = is_array($events) ? (array_is_list($events) ? $events : [$events]) : [];
        $last = end($events);

        if (!is_array($last) || ($last['Идентификатор'] ?? '') === '') {
            return $cursor;
        }

        return [
            'eventId'    => (string) $last['Идентификатор'],
            'documentId' => (string) ($row['Идентификатор'] ?? ''),
            'at'         => (string) ($last['ДатаВремя'] ?? ($cursor['at'] ?? '')),
        ];
    }

    /** @return array<string, array<string, string>> */
    private static function party(Organization $organization): array
    {
        $inn = (string) $organization->inn;

        return strlen($inn) === 12
            ? ['СвФЛ' => ['ИНН' => $inn]]
            : ['СвЮЛ' => array_filter(['ИНН' => $inn, 'КПП' => (string) ($organization->kpp ?? '')])];
    }
}
