<?php

declare(strict_types=1);

namespace App\Services\Edo;

use App\Enums\AttachmentMime;
use App\Enums\AttachmentPurpose;
use App\Enums\WorkOrderItemKind;
use App\Enums\Edo\EdoAcceptanceResultCode;
use App\Enums\Edo\EdoDocumentFunction;
use App\Enums\Edo\EdoDocumentStatus;
use App\Enums\Edo\EdoDocumentType;
use App\Exceptions\ApiException;
use App\Models\Attachment;
use App\Models\EdoDocument;
use App\Models\Organization;
use App\Models\RepairRequest;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\DTO\EdoAcceptTitleRequest;
use App\DTO\EdoFile;
use App\DTO\EdoInvoicePayload;
use App\DTO\EdoInvoiceRow;
use App\DTO\EdoOrganizationDto;
use App\DTO\EdoPartyInfo;
use App\DTO\EdoPreparedDocument;
use App\DTO\EdoSignature;
use App\DTO\EdoSignerDto;
use App\DTO\EdoSignerInfo;
use App\DTO\EdoStructuredRequest;
use App\DTO\EdoUcdRow;
use App\DTO\EdoUcdPayload;
use App\DTO\EdoUcdStructuredRequest;
use App\Services\MediaService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class EdoService
{
    public function __construct(
        private readonly EdoManager $manager,
        private readonly MediaService $media,
    ) {
    }

    /**
     * УПД от СТО к УК по конкретной заявке.
     */
    public function buildInvoicePayloadForStationToManager(RepairRequest $request): EdoInvoicePayload
    {
        $workOrder = $request->workOrder;
        if (!$workOrder instanceof WorkOrder) {
            throw new ApiException(422, 'EDO_NO_WORK_ORDER', 'У заявки нет заказ-наряда');
        }

        $station = $request->stationRecord?->organization;
        if (!$station instanceof Organization) {
            throw new ApiException(422, 'EDO_NO_STATION', 'У заявки нет СТО');
        }

        // УК — это tenant. Организация с kind=manager в этом же тенанте.
        $manager = Organization::query()
        ->where('tenant_id', $request->tenant_id)
            ->where('kind', 'manager')
            ->first();

        if (!$manager instanceof Organization) {
            throw new ApiException(422, 'EDO_NO_MANAGER', 'Не найдена УК для тенанта');
        }

        return new EdoInvoicePayload(
            seller: $this->toPartyInfo($station),
            buyer:  $this->toPartyInfo($manager),
            rows:   $this->buildRows($workOrder),
        );
    }

    private function toPartyInfo(Organization $org): EdoPartyInfo
    {
        return new EdoPartyInfo(
            name:       (string) $org->short_name,
            inn:        (string) $org->inn,
            kpp:        (string) $org->kpp,
            address:    (string) $org->legal_address,
            regionCode: $this->extractRegionCode((string) $org->legal_address),
            ediCode:    $org->edo_edi_code !== null ? (string) $org->edo_edi_code : null,
        );
    }

    /**
     * @return array<int, EdoInvoiceRow>
     */
    private function buildRows(WorkOrder $workOrder): array
    {
        $vatRate = 20.0; // на первой итерации — глобально для СТО
        $vatType = $vatRate > 0 ? rtrim(rtrim(number_format($vatRate, 2, '.', ''), '0'), '.') . '%' : 'без НДС';

        $rows = [];

        foreach ($workOrder->items as $item) {
            /** @var WorkOrderItem $item */
            $unit = $item->kind === 'work' ? '018' : '796';

            // Все суммы уже с НДС → обратный расчёт
            $totalSum = (float) $item->quantity * (float) $item->price;
            $netSum   = $vatRate > 0 ? $totalSum / (1 + $vatRate / 100) : $totalSum;
            $vatSum   = $totalSum - $netSum;

            $rows[] = new EdoInvoiceRow(
                name:     (string) $item->name,
                unit:     $unit,
                amount:   $this->num((float) $item->quantity),
                price:    $this->num((float) $item->price),
                netSum:   $this->num($netSum),
                vatSum:   $this->num($vatSum),
                totalSum: $this->num($totalSum),
                vatType:  $vatType,
            );
        }

        return $rows;
    }

    private function num(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function extractRegionCode(string $address): ?string
    {
        if (preg_match('/(?:^|[^0-9])(\d{2})(?:[^0-9]|$)/', $address, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * Сохраняет XML от СберКоруса как Attachment.
     * Отличается от пользовательской загрузки тем, что:
     *  - purpose = edo_payload
     *  - uploaded_by = автор заявки (или текущий пользователь-инициатор отправки)
     *  - request_status_period_id = текущий открытый период
     *  - sha256 считается от бинарного содержимого
     */
    public function persistPayloadAsAttachment(
        EdoFile $file,
        RepairRequest $request,
        int $uploadedByUserId,
    ): Attachment {
        $mime = $this->mimeFor($file->mimeType);
        $purpose = AttachmentPurpose::EdoPayload;

        $this->assertPurposeAllowsMime($purpose, $mime);

        $binary = $file->toBinary();
        if ($binary === '') {
            throw new ApiException(422, 'EDO_PAYLOAD_EMPTY', 'Пустой XML от оператора ЭДО');
        }

        $this->media->assertConfigured();

        $key = sprintf(
            'tenant-%d/requests/%s/edo/%s.xml',
            $request->tenant_id,
            $request->uuid,
            Str::uuid(),
        );

        $this->writeToStorage($key, $binary, $mime);

        try {
            $attachment = Attachment::query()->create([
                'repair_request_id'        => $request->id,
                'uploaded_by'              => $uploadedByUserId,
                'request_status_period_id' => $this->currentPeriodId($request),
                'purpose'                  => $purpose->value,
                'disk'                     => 'media',
                'object_key'               => $key,
                'original_name'            => $file->filename,
                'mime_type'                => $mime->value,
                'size'                     => strlen($binary),
                'sha256'                   => hash('sha256', $binary),
            ]);
        } catch (Throwable $e) {
            $this->safeDelete($key);
            throw $e;
        }

        return $attachment;
    }

    private function mimeFor(string $mimeType): AttachmentMime
    {
        $normalized = strtolower(trim($mimeType));

        return match ($normalized) {
            'text/xml',
            'application/xml',
            'application/octet-stream' => AttachmentMime::Xml,
            default                    => throw new ApiException(
                422,
                'EDO_PAYLOAD_BAD_MIME',
                sprintf('Неподдерживаемый MIME XML: %s', $mimeType),
            ),
        };
    }

    private function assertPurposeAllowsMime(AttachmentPurpose $purpose, AttachmentMime $mime): void
    {
        if (!$purpose->allowsMime($mime)) {
            throw new ApiException(500, 'EDO_PURPOSE_MIME_MISMATCH', 'MIME не разрешён для purpose');
        }
    }

    private function currentPeriodId(RepairRequest $request): int
    {
        $periodId = $request->periods()->whereNull('exited_at')->value('id');

        if ($periodId === null) {
            throw new ApiException(422, 'EDO_NO_OPEN_PERIOD', 'У заявки нет открытого периода');
        }

        return (int) $periodId;
    }

    private function writeToStorage(string $key, string $binary, AttachmentMime $mime): void
    {
        try {
            $stored = Storage::disk('media')->put($key, $binary, [
                'visibility'  => 'private',
                'ContentType' => $mime->value,
            ]);

            if ($stored === false) {
                throw new RuntimeException('Storage write failed');
            }
        } catch (Throwable $e) {
            report($e);
            throw new ApiException(503, 'UPLOAD_FAILED', 'Не удалось сохранить XML в облако');
        }
    }

    private function safeDelete(string $key): void
    {
        try {
            Storage::disk('media')->delete($key);
        } catch (Throwable $cleanup) {
            report($cleanup);
        }
    }

    /**
     * Подготовить структурированный документ к подписи:
     *  1. Собирает payload из бизнес-данных.
     *  2. Просит оператора сгенерировать XML (createStructuredDocument).
     *  3. Сохраняет XML как Attachment с purpose=edo_payload.
     *  4. Создаёт EdoDocument со статусом Draft.
     *  5. Возвращает uuid + содержимое для подписи.
     */
    public function prepareStructuredDocument(
        RepairRequest $request,
        EdoDocumentType $type,
        Organization $counterparty,
        int $initiatorUserId,
        ?EdoInvoicePayload $payload = null,
        ?EdoSignerDto $signer = null,
        ?Organization $sellerOrganization = null,   // ← добавили
    ): EdoPreparedDocument {
        $provider = $this->manager->provider();
        $payload ??= $this->buildInvoicePayloadForStationToManager($request);
        $sellerOrganization ??= $this->resolveDefaultSellerOrganization($request, $type);

        $signer ??= $this->resolveSignerForSeller($sellerOrganization, $type);

        $structuredRequest = new EdoStructuredRequest(
            type:            $type,
            receiverEdiCode: (string) $counterparty->edo_edi_code,
            number:          $this->generateNumber($request, $type),
            date:            new \DateTimeImmutable(),
            payload:         $payload,
            signer:          $signer,
            senderEdiCode:   (string) ($sellerOrganization->edo_edi_code ?? ''),
        );

        $dto = $provider->createStructuredDocument($structuredRequest);

        if ($dto->content === null) {
            throw new ApiException(502, 'EDO_NO_CONTENT', 'Оператор не вернул XML документа');
        }

        $attachment = $this->persistPayloadAsAttachment(
            file:             $dto->content,
            request:          $request,
            uploadedByUserId: $initiatorUserId,
        );

        $edo = EdoDocument::query()->create([
            'tenant_id'             => $request->tenant_id,
            'organization_id'       => $request->stationRecord?->organization_id ?? $request->customer_organization_id,
            'counterparty_id'       => $counterparty->id,
            'document_type'         => $type->value,
            'status'                => EdoDocumentStatus::Draft->value,
            'provider'              => $provider->provider()->value,
            'external_id'           => $dto->externalId !== '' ? $dto->externalId : null,
            'number'                => $structuredRequest->number,
            'document_date'         => $structuredRequest->date->format('Y-m-d'),
            'payload_attachment_id' => $attachment->id,
            'source_type'           => RepairRequest::class,
            'source_id'             => $request->id,
            'meta'                  => [
                'items_snapshot' => $this->buildItemsSnapshot($payload),
            ],
        ]);

        return new EdoPreparedDocument(
            uuid:            $edo->uuid,
            payloadBase64:   $attachment->object_key === '' ? $dto->content->content : $dto->content->content,
            payloadFilename: $dto->content->filename,
            signatureHint:   'detached_cms',
        );
    }

    /**
     * Снимок строк УПД для последующей сборки УКД.
     * Хранится в edo_documents.meta.items_snapshot.
     *
     * @return array<int, array<string, string>>
     */
    private function buildItemsSnapshot(EdoInvoicePayload $payload): array
    {
        return array_map(
            static fn (EdoInvoiceRow $row): array => [
                'name'      => $row->name,
                'unit'      => $row->unit,
                'amount'    => $row->amount,
                'price'     => $row->price,
                'net_sum'   => $row->netSum,
                'vat_sum'   => $row->vatSum,
                'total_sum' => $row->totalSum,
                'vat_type'  => $row->vatType,
            ],
            $payload->rows,
        );
    }

    /**
     * Принять подпись с фронта, подписать документ в ЭДО и отправить.
     */
    public function signAndSend(
        EdoDocument $document,
        EdoSignature $signature,
    ): void {
        if (!$document->isDraft()) {
            throw new ApiException(
                422,
                'EDO_NOT_DRAFT',
                sprintf('Документ в статусе «%s» нельзя подписать', $document->status->label()),
            );
        }

        if (!$document->hasExternalId()) {
            throw new ApiException(
                422,
                'EDO_NO_EXTERNAL_ID',
                'Документ ещё не зарегистрирован в ЭДО (нет external_id)',
            );
        }

        $provider = $this->manager->provider();

        $provider->signDocument($document->external_id, $signature);
        $provider->sendDocument($document->external_id);

        $document->update([
            'status'  => EdoDocumentStatus::Sended->value,
            'sent_at' => now(),
        ]);
    }

    private function generateNumber(RepairRequest $request, EdoDocumentType $type): string
    {
        $prefix = match ($type) {
            EdoDocumentType::UpdSf     => 'УПД',
            EdoDocumentType::UpdSfDop  => 'УПД-ДОП',
            EdoDocumentType::UcdCsf    => 'УКД',
            EdoDocumentType::ActWorks  => 'АКТ',
            default                    => 'ДОК',
        };

        return sprintf('%s-%s', $prefix, $request->number ?? $request->id);
    }

    /**
    * Синхронизировать статус одного документа с оператором ЭДО.
    */
    public function syncStatus(EdoDocument $document): void
    {
        if (!$document->hasExternalId()) {
            throw new ApiException(
                422,
                'EDO_NO_EXTERNAL_ID',
                'Документ ещё не зарегистрирован в ЭДО',
            );
        }

        $provider = $this->manager->provider();
        $remote = $provider->getDocument($document->external_id);

        $update = [
            'status'         => $remote->status->value,
            'last_synced_at' => now(),
            'meta'           => array_merge(
                (array) $document->meta,
                ['last_sync_raw' => $remote->raw],
            ),
        ];

        // Заполняем external_uuid, если пришёл в ответе и у нас пусто
        if ($document->external_uuid === null && $remote->externalUuid !== null) {
            $update['external_uuid'] = $remote->externalUuid;
        }

        match ($remote->status) {
            EdoDocumentStatus::Delivered => $update['delivered_at'] = now(),
            EdoDocumentStatus::Signed    => $update['signed_at']    = now(),
            EdoDocumentStatus::Accepted  => $update['accepted_at']  = now(),
            EdoDocumentStatus::Rejected  => $update['rejected_at']  = now(),
            default                      => null,
        };

        $document->update($update);
    }

    public function acceptTitle(
        EdoDocument $document,
        EdoSignerInfo $signer,
        int $userId,
    ): EdoDocument {
        if (!$document->hasExternalId()) {
            throw new ApiException(422, 'EDO_NO_EXTERNAL_ID', 'Документ ещё не зарегистрирован в ЭДО');
        }

        $source = $document->source;
        if (!$source instanceof RepairRequest) {
            throw new ApiException(
                422,
                'EDO_SOURCE_NOT_REQUEST',
                'Титул заказчика формируется только для документов, привязанных к заявке',
            );
        }

        $provider = $this->manager->provider();

        $request = new EdoAcceptTitleRequest(
            externalId:  (string) $document->external_id,
            resultCode:  EdoAcceptanceResultCode::AcceptedNoDisaccord,
            dealSummary: sprintf(
                'Товары (работы, услуги) по документу %s приняты без расхождений (претензий)',
                $document->number ?? $document->external_id,
            ),
            date:        new \DateTimeImmutable(),
            signer:      $signer,
        );

        $file = $provider->acceptTitle($request);

        $attachment = $this->persistPayloadAsAttachment(
            file:             $file,
            request:          $source,
            uploadedByUserId: $userId,
        );

        $document->update([
            'acceptance_attachment_id' => $attachment->id,
            'accepted_at'              => now(),
            'status'                   => EdoDocumentStatus::Signed->value,
            'last_synced_at'           => now(),
        ]);

        return $document->refresh();
    }

    /**
     * Формирует payload УПД, где продавец — УК (manager, current tenant),
     * покупатель — Заказчик (customerOrganization заявки).
     *
     * Наценка УК берётся из Organization::markupPercent().
     * НДС считается обратным счётом от итоговой суммы (по строкам).
     */
    public function buildInvoicePayloadForManagerToCustomer(RepairRequest $request, Organization $manager): EdoInvoicePayload
    {
        $customer = $request->customerOrganization;
        if (!$customer instanceof Organization) {
            throw new ApiException(422, 'EDO_NO_CUSTOMER', 'У заявки нет заказчика');
        }

        $workOrder = $request->workOrder;
        if (!$workOrder instanceof WorkOrder) {
            throw new ApiException(422, 'EDO_NO_WORK_ORDER', 'У заявки нет заказ-наряда');
        }

        $rows = $this->buildRowsForCustomer($workOrder, $manager);

        if ($rows === []) {
            throw new ApiException(422, 'EDO_INVOICE_ROWS_EMPTY', 'В заказ-наряде нет позиций для УПД');
        }

        return new EdoInvoicePayload(
            seller: $this->toPartyInfo($manager),
            buyer:  $this->toPartyInfo($customer),
            rows:   $rows,
        );
    }

    /**
     * Собирает УКД СТО→УК: изменяем только цену (добавляем наценку УК).
     * Объём, единица, НДС-ставка, наименование — не меняются.
     *
     * @throws ApiException 422 EDO_UCD_BASE_NOT_FOUND  — base_document не привязан к заявке
     * @throws ApiException 422 EDO_UCD_BASE_WRONG_TYPE — base_document не УПД
     * @throws ApiException 422 EDO_UCD_BASE_FINALIZED  — base_document отклонён/отозван
     * @throws ApiException 422 EDO_UCD_NO_SNAPSHOT     — нет снимка строк в meta
     */
    public function buildUcdPayloadForStationToManager(RepairRequest $request, EdoDocument $baseDocument): EdoUcdPayload
    {
        // 1. Base документ должен быть привязан к этой заявке
        if (!$baseDocument->isLinkedTo($request)) {
            throw new ApiException(
                422,
                'EDO_UCD_BASE_NOT_FOUND',
                'Базовый документ не привязан к этой заявке',
            );
        }

        // 2. Base документ должен быть УПД
        if (!$baseDocument->isUpd()) {
            throw new ApiException(
                422,
                'EDO_UCD_BASE_WRONG_TYPE',
                sprintf('УКД формируется только к УПД, а не к «%s»', $baseDocument->document_type->label()),
            );
        }

        // 3. Нельзя корректировать отклонённый/отозванный/аннулированный
        if ($baseDocument->isFinalized()) {
            throw new ApiException(
                422,
                'EDO_UCD_BASE_FINALIZED',
                sprintf('Базовый документ в статусе «%s» нельзя корректировать', $baseDocument->status->label()),
            );
        }

        // 4. Снимок строк
        if (!$baseDocument->hasItemsSnapshot()) {
            throw new ApiException(
                422,
                'EDO_UCD_NO_SNAPSHOT',
                'У базового УПД нет снимка строк (создан до включения поддержки УКД)',
            );
        }

        $snapshot = $baseDocument->itemsSnapshot();

        // 5. Продавец/покупатель — как в базовом УПД
        $station = $request->stationRecord?->organization;
        if (!$station instanceof Organization) {
            throw new ApiException(422, 'EDO_NO_STATION', 'У заявки нет СТО');
        }

        $manager = Organization::query()
        ->where('tenant_id', $request->tenant_id)
            ->where('kind', 'manager')
            ->first();

        if (!$manager instanceof Organization) {
            throw new ApiException(422, 'EDO_NO_MANAGER', 'Не найдена УК для тенанта');
        }

        // 6. Наценка УК — процент
        $markup  = 1 + $manager->markupPercent() / 100;
        $vatRate = 20.0;

        // 7. Строки УКД
        $rows = [];
        foreach ($snapshot as $row) {
            $priceBefore = (float) $row['price'];
            $priceAfter  = $priceBefore * $markup;
            $amount      = (float) $row['amount'];

            $totalSumBefore = (float) $row['total_sum'];
            $totalSumAfter  = $amount * $priceAfter;

            $netSumBefore = (float) $row['net_sum'];
            $netSumAfter  = $totalSumAfter / (1 + $vatRate / 100);

            $vatSumBefore = (float) $row['vat_sum'];
            $vatSumAfter  = $totalSumAfter - $netSumAfter;

            $rows[] = new EdoUcdRow(
                name:           (string) $row['name'],
                unitBefore:     (string) $row['unit'],
                unitAfter:      (string) $row['unit'],
                amountBefore:   $this->num($amount),
                amountAfter:    $this->num($amount),
                priceBefore:    $this->num($priceBefore),
                priceAfter:     $this->num($priceAfter),
                netSumBefore:   $this->num($netSumBefore),
                netSumAfter:    $this->num($netSumAfter),
                vatSumBefore:   $this->num($vatSumBefore),
                vatSumAfter:    $this->num($vatSumAfter),
                vatTypeBefore:  (string) $row['vat_type'],
                vatTypeAfter:   (string) $row['vat_type'],
                totalSumBefore: $this->num($totalSumBefore),
                totalSumAfter:  $this->num($totalSumAfter),
            );
        }

        if ($rows === []) {
            throw new ApiException(422, 'EDO_UCD_ROWS_EMPTY', 'Не удалось собрать строки УКД');
        }

        return new EdoUcdPayload(
            documentFunction: EdoDocumentFunction::UcdCsf,
            formatVersion:    '5.01',
            formatCode:       '1115133',
            currencyCode:     '643',
            seller:           $this->toPartyInfo($station),
            buyer:            $this->toPartyInfo($manager),
            rows:             $rows,
            invoiceNumber:    $baseDocument->number,
            invoiceDate:      $baseDocument->document_date->toDateTimeImmutable(),
        );
    }

    /**
     * Готовит УКД СТО→УК. Подпись и отправка — отдельным вызовом signAndSend.
     *
     * @throws ApiException — см. buildUcdPayloadForStationToManager
     */
    public function prepareUcdForStationToManager(
        RepairRequest $request,
        EdoDocument $baseDocument,
        int $userId,
    ): EdoPreparedDocument {
        $payload = $this->buildUcdPayloadForStationToManager($request, $baseDocument);

        $manager = Organization::query()
        ->where('tenant_id', $request->tenant_id)
            ->where('kind', 'manager')
            ->first();

        if (!$manager instanceof Organization) {
            throw new ApiException(422, 'EDO_NO_MANAGER', 'Не найдена УК для тенанта');
        }

        $provider = $this->manager->provider();

        $structuredRequest = new EdoUcdStructuredRequest(
            type:            EdoDocumentType::UcdCsf,
            receiverEdiCode: (string) $manager->edo_edi_code,
            number:          $this->generateNumber($request, EdoDocumentType::UcdCsf),
            date:            new \DateTimeImmutable(),
            payload:         $payload,
            // senderEdiCode / signer — нет
        );

        $dto = $provider->createUcdDocument($structuredRequest);

        if ($dto->content === null) {
            throw new ApiException(502, 'EDO_NO_CONTENT', 'Оператор не вернул XML УКД');
        }

        $attachment = $this->persistPayloadAsAttachment(
            file:             $dto->content,
            request:          $request,
            uploadedByUserId: $userId,
        );

        $edo = EdoDocument::query()->create([
            'tenant_id'             => $request->tenant_id,
            'organization_id'       => $request->stationRecord?->organization_id ?? $request->customer_organization_id,
            'counterparty_id'       => $manager->id,
            'document_type'         => EdoDocumentType::UcdCsf->value,
            'status'                => EdoDocumentStatus::Draft->value,
            'provider'              => $provider->provider()->value,
            'external_id'           => $dto->externalId !== '' ? $dto->externalId : null,
            'number'                => $structuredRequest->number,
            'document_date'         => $structuredRequest->date->format('Y-m-d'),
            'payload_attachment_id' => $attachment->id,
            'source_type'           => RepairRequest::class,
            'source_id'             => $request->id,
            'meta'                  => [
                'base_edo_document_id'   => $baseDocument->id,
                'base_edo_document_uuid' => $baseDocument->uuid,
            ],
        ]);

        return new EdoPreparedDocument(
            uuid:            $edo->uuid,
            payloadBase64:   $dto->content->content,
            payloadFilename: $dto->content->filename,
            signatureHint:   'detached_cms',
        );
    }

    /**
     * Строки УПД для УК→Заказчик.
     * Цена = WorkOrderItem::price × (1 + markup_percent / 100).
     * НДС по каждой строке — обратным счётом (20%).
     *
     * @return array<int, EdoInvoiceRow>
     */
    public function buildRowsForCustomer(WorkOrder $workOrder, Organization $manager): array
    {
        $vatRate = 20.0;
        $vatType = rtrim(rtrim(number_format($vatRate, 2, '.', ''), '0'), '.') . '%';
        $markup  = 1 + $manager->markupPercent() / 100;

        $rows = [];

        foreach ($workOrder->items()->orderBy('id')->get() as $item) {
            /** @var WorkOrderItem $item */
            $qty   = (float) $item->quantity;
            $price = (float) $item->price;

            if ($qty <= 0.0) {
                continue;
            }

            $unit = $item->kind === WorkOrderItemKind::Work ? '018' : '796';

            // Цена для заказчика — с наценкой УК
            $priceWithMarkup = $price * $markup;
            $totalSum        = $qty * $priceWithMarkup;
            $netSum          = $totalSum / (1 + $vatRate / 100);
            $vatSum          = $totalSum - $netSum;

            $rows[] = new EdoInvoiceRow(
                name:     (string) $item->name,
                unit:     $unit,
                amount:   $this->num($qty),
                price:    $this->num($priceWithMarkup),
                netSum:   $this->num($netSum),
                vatSum:   $this->num($vatSum),
                totalSum: $this->num($totalSum),
                vatType:  $vatType,
            );
        }

        return $rows;
    }

    /**
     * Готовит УПД от УК к Заказчику.
     * Продавец — УК (Organization kind=manager в тенанте заявки).
     */
    public function prepareInvoiceForManagerToCustomer(
        RepairRequest $request,
        int $userId,
        EdoDocumentType $documentType = EdoDocumentType::UpdSf,
    ): EdoPreparedDocument {
        $customer = $request->customerOrganization;
        if (!$customer instanceof Organization) {
            throw new ApiException(422, 'EDO_NO_CUSTOMER', 'У заявки нет заказчика');
        }

        $manager = Organization::query()
        ->where('tenant_id', $request->tenant_id)
            ->where('kind', 'manager')
            ->first();

        if (!$manager instanceof Organization) {
            throw new ApiException(422, 'EDO_NO_MANAGER', 'Не найдена УК для тенанта');
        }

        $payload = $this->buildInvoicePayloadForManagerToCustomer($request, $manager);

        return $this->prepareStructuredDocument(
            request:            $request,
            type:               $documentType,
            counterparty:       $customer,
            initiatorUserId:    $userId,
            payload:            $payload,
            sellerOrganization: $manager,   // ← продавец = УК
        );
    }

    /**
     * Найти организацию в ЭДО по ИНН/КПП.
     * Возвращает DTO с ediCode (ИдУчЭДО) и id (Id в системе оператора).
     *
     * @throws ApiException 422 EDO_ORG_NOT_REGISTERED — если контрагент не найден в ЭДО
     * @throws ApiException 422 EDO_ORG_NO_INN          — если у организации не заполнен ИНН
     */
    public function resolveOrganization(Organization $organization): EdoOrganizationDto
    {
        if (!$organization->hasInn()) {
            throw new ApiException(
                422,
                'EDO_ORG_NO_INN',
                sprintf('У организации #%d не заполнен ИНН', $organization->id),
            );
        }

        $inn = (string)$organization->inn;
        $kpp = $organization->kppOrNull();

        $dto = $this->manager->provider()->findOrganization($inn, $kpp);

        if ($dto === null) {
            throw new ApiException(
                422,
                'EDO_ORG_NOT_REGISTERED',
                sprintf('Контрагент с ИНН %s%s не найден в ЭДО', $inn, $kpp !== null ? '/' . $kpp : ''),
            );
        }

        if (!$dto->hasEdiCode()) {
            throw new ApiException(
                422,
                'EDO_ORG_NO_EDI_CODE',
                sprintf('У контрагента %s не получен ИдУчЭДО', $dto->name),
            );
        }

        return $dto;
    }

    /**
     * Зарегистрировать организацию в ЭДО: найти у оператора и записать
     * edo_client_id / edo_edi_code / edo_provider / edo_registered_at.
     *
     * @param  bool  $force  Перезаписать, если уже заполнено
     *
     * @throws ApiException 409 EDO_ORG_ALREADY_REGISTERED — если уже заполнено и !$force
     */
    public function registerOrganization(Organization $organization, bool $force = false): EdoOrganizationDto
    {
        if (!$force && $organization->isRegisteredInEdo()) {
            throw new ApiException(
                409,
                'EDO_ORG_ALREADY_REGISTERED',
                sprintf(
                    'Организация #%d уже зарегистрирована (client_id=%s, edi_code=%s). Используйте --force для перезаписи',
                    $organization->id,
                    $organization->edo_client_id,
                    $organization->edo_edi_code,
                ),
            );
        }

        $dto = $this->resolveOrganization($organization);

        $organization->forceFill([
            'edo_client_id'     => $dto->id,
            'edo_edi_code'      => $dto->ediCode,
            'edo_provider'      => $this->manager->provider()->provider()->value,
            'edo_registered_at' => now(),
        ])->save();

        return $dto;
    }

    /**
     * Подписант продавца — директор организации.
     *
     * На первом этапе используется Organization::director_name (ФИО одной строкой).
     * Должность — «Руководитель». СпосПодтПолном = 1.
     * TODO: заменить на репозиторий подписантов, когда появятся МЧД.
     */
    private function resolveSignerForSeller(
        Organization $seller,
        EdoDocumentType $type,
    ): EdoSignerDto {
        $directorName = trim((string) $seller->director_name);

        if ($directorName === '') {
            throw new ApiException(
                422,
                'EDO_NO_SIGNER',
                sprintf('У организации «%s» не заполнено поле director_name', $seller->name),
            );
        }

        return EdoSignerDto::fromFullName($directorName, 'Руководитель');
    }

    /**
     * Дефолтный продавец по контексту:
     *  - UpdSf / UpdSfDop / UcdCsf, вызванные из prepareInvoiceForManagerToCustomer:
     *    продавец = УК. Но мы не можем это понять по типу.
     *  - Иначе продавец = СТО.
     *
     * Правильно: всегда передавать продавца явно из вызывающего метода.
     * Этот метод — только fallback для обратной совместимости.
     */
    private function resolveDefaultSellerOrganization(
        RepairRequest $request,
        EdoDocumentType $type,
    ): Organization {
        $station = $request->stationRecord?->organization;

        if ($station instanceof Organization) {
            return $station;
        }

        throw new ApiException(
            422,
            'EDO_NO_SELLER',
            'Не удалось определить организацию-продавца для документа ' . $type->value
            . ' (stationRecord не заполнен, sellerOrganization не передан)',
        );
    }
}
