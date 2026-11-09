<?php

declare(strict_types=1);

namespace App\Services\Edo;

use App\Enums\AttachmentMime;
use App\Enums\AttachmentPurpose;
use App\Models\Attachment;
use App\Models\EdoDocument;
use App\Models\RepairRequest;
use App\Services\Edo\Builders\UpdBuilder;
use App\Services\Edo\Drivers\Sbis\SbisProvider;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Models\RequestStatusPeriod;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;

final class EdoClosingDocumentsService
{
    public function __construct(
        private readonly UpdBuilder $updBuilder,
        private readonly EdoXmlValidator $validator,
        private readonly SbisProvider $provider
    ) {}

    public function generateAndSendUpd(RepairRequest $request, EdoDocument $document): void
    {
        $logger = Log::channel('edo');
        $logger->info("EdoService: Старт генерации УПД для заявки {$request->number}");

        // 1. Билдер генерирует XML + PDF
        $updRequest = $this->updBuilder->build(
            $request,
            $document,
            $request->customerOrganization,
            $request->stationRecord->organization
        );

        // 2. Валидация XML по XSD
        try {
            $this->validator->validate(
                $updRequest->meta['xml_content'],
                'ON_NSCHFDOPPR_1_998_01_05_01_01.xsd'
            );
            $logger->info("EdoService: XML успешно прошел валидацию по XSD.");
        } catch (\Throwable $e) {
            $logger->error("EdoService: Ошибка валидации XML: " . $e->getMessage());
            throw $e;
        }

        // 3. Отправка в провайдер
        $dto = $this->provider->createStructuredDocument($updRequest);

        $logger->info("EdoService: Документ обработан провайдером", [
            'externalId' => $dto->externalId,
            'status' => $dto->status
        ]);

        // Сохранение вложения в мок-режиме
        $attachment = null;
        if ($this->provider->isMock() && isset($updRequest->meta['file_content'])) {
            $attachment = $this->saveMockAttachment($request, $updRequest->meta);
        }

        // Обновление записи документа
        $document->update([
            'external_id'             => $dto->externalId,
            'external_uuid'           => $dto->externalUuid,
            'status'                  => $dto->status->value,
            'payload_attachment_id'   => $attachment?->id, // Если сохраняли PDF
            'meta'                    => array_merge($document->meta ?? [], [
                'raw_provider_response' => $dto->raw,
            ]),
        ]);
    }

    private function saveMockAttachment(RepairRequest $request, array $meta): void
    {
        $logger = Log::channel('edo');

        $pdfContent = base64_decode($meta['file_content']);
        $originalName = $meta['file_name'] ?? 'document.pdf';
        $uniquePrefix = Str::uuid()->toString();
        $fileName = $uniquePrefix . '_' . $originalName;

        $disk = 'local';
        $objectKey = 'edo/mock/' . $request->id . '_' . $fileName;

        Storage::disk($disk)->put($objectKey, $pdfContent);

        // 1. Сначала пытаемся найти период через обычный запрос
        $statusPeriodId = DB::table('request_status_periods')
            ->where('repair_request_id', $request->id)
            ->orderByRaw('exited_at IS NULL DESC')
            ->orderBy('id', 'desc')
            ->value('id');

        // 2. Если не нашли (например, из-за транзакции), пробуем взять любой период по заявке
        if (!$statusPeriodId) {
            $logger->warning("EdoService: Период не найден запросом, пробуем найти через модель.");

            // Используем Eloquent, чтобы обойти возможные кэши/транзакции
            $period = RequestStatusPeriod::query()
                ->where('repair_request_id', $request->id)
                ->first();

            if ($period) {
                $statusPeriodId = $period->id;
            }
        }

        // 3. Если периодов вообще нет - создаем
        if (!$statusPeriodId) {
            $logger->warning("EdoService: В БД нет ни одного периода для заявки {$request->id}. Создаем.");

            try {
                $statusPeriodId = DB::table('request_status_periods')->insertGetId([
                    'uuid'              => (string) Str::uuid(),
                    'repair_request_id' => $request->id,
                    'stage'             => $request->stage->value,
                    'entered_at'        => now(),
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ]);
            } catch (QueryException $e) {
                // Если упало на уникальности (значит, запись есть, но мы её не видим)
                // Достаем ID принудительно
                $logger->error("EdoService: Ошибка вставки периода. Пытаемся получить ID последнего.");
                $statusPeriodId = DB::table('request_status_periods')
                    ->where('repair_request_id', $request->id)
                    ->max('id');

                if (!$statusPeriodId) {
                    throw new \RuntimeException("Не удалось получить или создать request_status_period_id для заявки {$request->id}", 0, $e);
                }
            }
        }

        $uploadedBy = $meta['uploaded_by'] ?? User::query()->first()?->id;

        // Создаем запись в БД
        Attachment::create([
            'uuid'                     => Str::uuid(),
            'repair_request_id'        => $request->id,
            'uploaded_by'              => $uploadedBy,
            'request_status_period_id' => $statusPeriodId,
            'purpose'                  => AttachmentPurpose::GeneratedDocument,
            'disk'                     => $disk,
            'object_key'               => $objectKey,
            'original_name'            => $originalName,
            'mime_type'                => AttachmentMime::Pdf,
            'size'                     => strlen($pdfContent),
            'sha256'                   => hash('sha256', $pdfContent),
        ]);

        $logger->info("EdoService [MOCK]: PDF сохранен и привязан к заявке", [
            'path' => $objectKey,
            'repair_request_id' => $request->id
        ]);
    }
}
