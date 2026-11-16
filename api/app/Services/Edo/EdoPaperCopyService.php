<?php

declare(strict_types=1);

namespace App\Services\Edo;

use App\Enums\Edo\EdoProvider;
use App\Enums\RequestDocumentType;
use App\Models\EdoDocument;
use App\Models\Organization;
use App\Models\RequestDocument;
use App\Services\Edo\Builders\Closing\InvoiceBuilder as ClosingInvoiceBuilder;
use App\Services\Edo\Builders\Closing\WorkOrderBuilder as ClosingWorkOrderBuilder;
use App\Services\Edo\Drivers\Sbis\SbisClientFactory;
use App\Services\Edo\Drivers\Sbis\SbisDocumentWorkflow;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Бумажная копия документа ЭДО (PDF). ЗН и Счёт отправляются в ЭДО готовым
 * PDF — его и отдаём, обновляя из СБИС только статус подписи/отправки.
 * УПД уходит XML-файлом 5.03, поэтому печатается стандартной формой
 * (pdf.closing.upd, альбомный лист) с отметкой ЭДО «_edo_stamp».
 */
final class EdoPaperCopyService
{
    public function __construct(
        private readonly SbisClientFactory $clients,
        private readonly ClosingWorkOrderBuilder $workOrders,
        private readonly ClosingInvoiceBuilder $invoices,
    ) {}

    public function render(EdoDocument $document, Organization $viewer): string
    {
        // Статус подписи/отправки обновляется из СБИС при каждом просмотре.
        $stamp = $this->stamp($document, $viewer);
        $source = $document->source_type === RequestDocument::class
            ? RequestDocument::query()->with(['repairRequest', 'seller', 'buyer', 'attachment'])->find($document->source_id)
            : null;

        // ЗН и Счёт уходят в ЭДО готовым PDF — отдаём его как есть.
        if ($source !== null && !self::isUpd($source->type)) {
            $stored = $this->storedPdf($source);
            if ($stored !== null) {
                return $stored;
            }
        }

        $pdf = $source !== null
            ? $this->fromRequestDocument($source, $stamp)
            : Pdf::loadView('pdf.closing.edo_copy', ['edo' => $stamp]);

        $orientation = $source !== null && self::isUpd($source->type) ? 'landscape' : 'portrait';

        return $pdf->setPaper('a4', $orientation)->setOption('defaultFont', 'DejaVu Sans')->output();
    }

    public static function isUpd(RequestDocumentType $type): bool
    {
        return $type === RequestDocumentType::StationUpd || $type === RequestDocumentType::CustomerUpd;
    }

    private function storedPdf(RequestDocument $source): ?string
    {
        $attachment = $source->attachment;
        if ($attachment === null) {
            return null;
        }

        try {
            $disk = Storage::disk($attachment->disk);

            return $disk->exists($attachment->object_key) ? $disk->get($attachment->object_key) : null;
        } catch (Throwable $e) {
            Log::channel('edo')->warning("Не удалось прочитать PDF документа {$source->number}", ['error' => $e->getMessage()]);

            return null;
        }
    }

    public function fileName(EdoDocument $document): string
    {
        return str_replace(['/', '\\'], '-', $document->number) . ' ' . $document->document_type->label() . ' (ЭДО).pdf';
    }

    /**
     * Сведения ЭДО для отметки: из СБИС (ПрочитатьДокумент), при ошибке —
     * последние сохранённые, иначе — из нашей записи документа.
     *
     * @return array<string, mixed>
     */
    public function stamp(EdoDocument $document, Organization $viewer): array
    {
        $meta = is_array($document->meta) ? $document->meta : [];
        $notice = null;
        $read = null;

        if ($this->canRead($document, $viewer)) {
            try {
                $side = SbisDocumentWorkflow::side($document, $viewer->id);
                $id = $side === SbisDocumentWorkflow::RECEIVER && is_string($meta['receiver_external_id'] ?? null)
                    ? $meta['receiver_external_id']
                    : (string) $document->external_id;
                $read = $this->normalize($this->clients->make($viewer)->readDocument($id));
                $read['readAt'] = now()->toISOString();
                $meta['sbis_read'] = $read;
                $document->forceFill(['meta' => $meta])->save();
            } catch (Throwable $e) {
                Log::channel('edo')->warning("SBIS: не удалось прочитать {$document->number} для бумажной копии", ['error' => $e->getMessage()]);
                $notice = 'Сведения из СБИС сейчас недоступны: ' . $e->getMessage() . '. Показаны последние сохранённые.';
            }
        }

        $read ??= is_array($meta['sbis_read'] ?? null) ? $meta['sbis_read'] : null;
        $readAt = $read['readAt'] ?? null;

        return [
            'provider'   => $document->provider->label(),
            'name'       => (string) ($read['name'] ?? '') ?: $document->document_type->label() . ' № ' . $document->number,
            'number'     => (string) ($read['number'] ?? '') ?: $document->number,
            'date'       => (string) ($read['date'] ?? '') ?: $document->document_date->format('d.m.Y'),
            'sum'        => (string) ($read['sum'] ?? ''),
            'sender'     => $this->party($document->organization),
            'receiver'   => $this->party($document->counterparty),
            'externalId' => (string) ($document->external_id ?? '—'),
            'state'      => (string) ($read['state'] ?? '') ?: (string) (($meta['sbis_state']['name'] ?? '') ?: $document->status->label()),
            'readAt'     => $readAt !== null ? \Carbon\CarbonImmutable::parse($readAt)->timezone(config('app.timezone'))->format('d.m.Y H:i') : now()->format('d.m.Y H:i'),
            'source'     => $read !== null ? 'СБИС' : 'данные AutoRemHub',
            'signatures' => $read['signatures'] ?? [],
            'events'     => $read['events'] ?? array_values(array_map(
                fn (array $event): array => ['at' => (string) ($event['at'] ?? ''), 'name' => (string) ($event['name'] ?? '')],
                array_filter((array) ($meta['events'] ?? []), 'is_array'),
            )),
            'notice'     => $notice ?? (($meta['mock'] ?? false) ? 'Тестовый режим ЭДО: документ не передавался оператору.' : null),
        ];
    }

    /**
     * Ответ СБИС.ПрочитатьДокумент → данные отметки.
     *
     * @param array<string, mixed> $doc
     *
     * @return array<string, mixed>
     */
    public function normalize(array $doc): array
    {
        $signatures = [];
        foreach ($this->list($doc['Вложение'] ?? []) as $attachment) {
            if (mb_strtolower((string) ($attachment['Служебный'] ?? 'Нет')) === 'да') {
                continue;
            }
            $file = (string) ($attachment['Файл']['Имя'] ?? $attachment['Название'] ?? '');
            foreach ($this->list($attachment['Подпись'] ?? []) as $signature) {
                $certificate = is_array($signature['Сертификат'] ?? null) ? $signature['Сертификат'] : [];
                $signatures[] = [
                    'owner'        => (string) ($certificate['ФИО'] ?? '—'),
                    'position'     => (string) ($certificate['Должность'] ?? ''),
                    'organization' => (string) ($certificate['Название'] ?? ''),
                    'inn'          => (string) ($certificate['ИНН'] ?? ''),
                    'file'         => $file,
                ];
            }
        }

        $events = array_map(
            fn (array $event): array => ['at' => (string) ($event['ДатаВремя'] ?? ''), 'name' => (string) ($event['Название'] ?? '')],
            $this->list($doc['Событие'] ?? []),
        );

        return [
            'name'       => (string) ($doc['Название'] ?? ''),
            'number'     => (string) ($doc['Номер'] ?? ''),
            'date'       => (string) ($doc['Дата'] ?? ''),
            'sum'        => (string) ($doc['Сумма'] ?? ''),
            'state'      => (string) ($doc['Состояние']['Название'] ?? ''),
            'signatures' => $signatures,
            'events'     => array_slice($events, -15),
        ];
    }

    /**
     * @param array<string, mixed> $stamp
     */
    private function fromRequestDocument(RequestDocument $source, array $stamp): \Barryvdh\DomPDF\PDF
    {
        $type = $source->type;
        $seller = $source->seller;
        $buyer = $source->buyer;
        $request = $source->repairRequest;

        if ($seller !== null && $buyer !== null && $request !== null) {
            if ($type === RequestDocumentType::StationWorkOrder || $type === RequestDocumentType::CustomerWorkOrder) {
                return Pdf::loadView('pdf.closing.work_order', [
                    ...$this->workOrders->viewData($request, $source, $seller, $buyer),
                    'edo' => $stamp,
                ]);
            }

            if ($type === RequestDocumentType::StationInvoice || $type === RequestDocumentType::CustomerInvoice) {
                return Pdf::loadView('pdf.closing.invoice', [
                    'payload' => $this->invoices->payload($source, $seller, $buyer),
                    'doc'     => (object) ['number' => $source->number, 'document_date' => $source->document_date],
                    'title'   => 'Счёт на оплату',
                    'edo'     => $stamp,
                ]);
            }
        }

        return Pdf::loadView($type->view(), [
            'doc'     => $source,
            'title'   => $type->label(),
            'payload' => is_array($source->payload) ? $source->payload : [],
            'side'    => $type->isStationSide() ? 'station' : 'customer',
            'edo'     => $stamp,
        ]);
    }

    private function canRead(EdoDocument $document, Organization $viewer): bool
    {
        $meta = is_array($document->meta) ? $document->meta : [];

        return $document->provider === EdoProvider::Sbis
            && $document->external_id !== null
            && !($meta['mock'] ?? false)
            && !(bool) config('edo.mock', true)
            && (string) ($viewer->edo_client_id ?? '') !== ''
            && SbisDocumentWorkflow::side($document, $viewer->id) !== null;
    }

    private function party(?Organization $organization): string
    {
        if ($organization === null) {
            return '—';
        }

        return trim(($organization->short_name ?: $organization->name) . ' · ИНН ' . $organization->inn . ($organization->kpp ? ' / КПП ' . $organization->kpp : ''));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function list(mixed $value): array
    {
        if (!is_array($value) || $value === []) {
            return [];
        }

        return array_is_list($value) ? array_values(array_filter($value, 'is_array')) : [$value];
    }
}
