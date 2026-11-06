<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AttachmentMime;
use App\Enums\AttachmentPurpose;
use App\Enums\Edo\EdoDocumentType;
use App\Enums\OrganizationKind;
use App\Enums\RequestDocumentType;
use App\Enums\UserRole;
use App\Models\Attachment;
use App\Models\Organization;
use App\Models\RepairRequest;
use App\Models\RequestDocument;
use App\Models\RequestPart;
use App\Models\RequestWork;
use App\Models\User;
use App\Support\AgentDiscount;
use App\Support\DomainInput as Input;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\DTO\UserDto;

/**
 * Документы закрытия ЗР.
 *
 * - «ТС готово к выдаче Заказчику»: акт передачи ТС (данные ЗР, ТС, водитель,
 *   доверенность). СТО загружает подписанный водителем скан и подтверждает
 *   сверку документов водителя с доверенностью.
 * - «Выдан. Подготовка документов»: ЗН, УПД и счёт СТО → УК (с агентской
 *   скидкой), ЗН, УПД и счёт УК → Заказчик (полная стоимость), отчёт агента.
 */
final class ClosingDocumentService
{
    private const MONEY_SCALE = 2;
    private const DISK = 'local';

    public function generateTransferAct(RepairRequest $request, User $user): RequestDocument
    {
        $request->loadMissing(['vehicleRecord', 'driverRecord', 'stationRecord.organization', 'customerOrganization', 'acceptanceAct', 'workOrder']);
        $station = $request->stationRecord?->organization;
        $customer = $request->customerOrganization;
        $vehicle = $request->vehicleRecord;
        $driver = $this->driver($request);
        $powerOfAttorney = $this->powerOfAttorney($request);

        $payload = [
            'request'  => ['number' => $request->number, 'mileage' => $request->workOrder?->mileage ?? $request->acceptanceAct?->mileage ?? ($request->data['mileage'] ?? '')],
            'vehicle'  => [
                'name'  => trim(($vehicle?->make ?? '') . ' ' . ($vehicle?->model ?? '')),
                'vin'   => $vehicle?->vin ?? '',
                'plate' => $vehicle?->plate ?? '',
                'year'  => $vehicle?->year,
            ],
            'driver'   => $driver,
            'powerOfAttorney' => $powerOfAttorney,
            'seller'   => $this->party($station, $request->stationRecord?->name),
            'buyer'    => $this->party($customer),
            'repairCompletedAt' => $request->repair_completed_at?->toISOString(),
            'acceptanceAct' => $request->acceptanceAct?->number,
            'workOrder' => $request->workOrder?->number,
            'sheet'    => $this->handoverSheet($request, 'out', $user),
        ];

        $document = $this->store($request, $user, RequestDocumentType::TransferAct, $station, $customer, '0', '0', $payload);
        // Новый акт — прежняя подпись недействительна.
        $document->forceFill([
            'signed_attachment_id'      => null,
            'driver_documents_verified' => false,
            'signed_at'                 => null,
            'signed_by'                 => null,
        ])->save();

        return $document;
    }

    public function signTransferAct(RepairRequest $request, User $user, Attachment $attachment, bool $verified): RequestDocument
    {
        $document = $request->document(RequestDocumentType::TransferAct);

        if ($document === null) {
            Input::fail('Лист приёма-передачи ТС (выдача) ещё не сформирован');
        }

        if (!$verified) {
            Input::fail('Подтвердите, что лицо, которому передаётся ТС, сверено с действующей доверенностью', 'driverDocumentsVerified');
        }

        $document->forceFill([
            'signed_attachment_id'      => $attachment->id,
            'driver_documents_verified' => true,
            'signed_at'                 => now(),
            'signed_by'                 => $user->id,
        ])->save();

        return $document;
    }

    public function isTransferActSigned(RepairRequest $request): bool
    {
        $document = $request->document(RequestDocumentType::TransferAct);

        return $document !== null
            && $document->signed_attachment_id !== null
            && $document->driver_documents_verified;
    }

    /**
     * @return array<int, RequestDocument>
     */
    public function generateClosingDocuments(RepairRequest $request, User $user): array
    {
        $request->load(['workLines', 'parts', 'stationRecord.organization', 'customerOrganization', 'workOrder', 'vehicleRecord']);
        $station = $request->stationRecord?->organization;
        $customer = $request->customerOrganization;
        $manager = Organization::query()
            ->where('tenant_id', $request->tenant_id)
            ->where('kind', OrganizationKind::Manager->value)
            ->orderBy('id')
            ->first();

        if ($station === null || $customer === null || $manager === null) {
            Input::fail('Для документов нужны реквизиты СТО, УК и Заказчика');
        }

        $stationRows = $this->rows($request, true, $station);
        $customerRows = $this->rows($request, false, $manager);
        $stationTotals = $this->totals($stationRows);
        $customerTotals = $this->totals($customerRows);
        $summary = AgentDiscount::summary($request);
        $vehicle = $request->vehicleRecord;
        $common = [
            'request' => [
                'number'  => $request->number,
                'vehicle' => trim(($vehicle?->make ?? '') . ' ' . ($vehicle?->model ?? '')),
                'vin'     => $vehicle?->vin ?? '',
                'plate'   => $vehicle?->plate ?? '',
                'mileage' => $request->workOrder?->mileage,
                'repairCompletedAt' => $request->repair_completed_at?->toISOString(),
            ],
        ];

        $documents = [];
        $stationSide = ['seller' => $this->party($station, $request->stationRecord?->name), 'buyer' => $this->party($manager)];
        $customerSide = ['seller' => $this->party($manager), 'buyer' => $this->party($customer)];

        $stationOrder = $this->store($request, $user, RequestDocumentType::StationWorkOrder, $station, $manager, $stationTotals['amount'], $stationTotals['vat'], [
            ...$common, ...$stationSide, 'rows' => $stationRows, 'totals' => $stationTotals,
            'agentDiscount' => ['percent' => $summary['percent'], 'amount' => $summary['amount'], 'fullAmount' => $summary['total']],
        ]);
        $documents[] = $stationOrder;
        $documents[] = $this->store($request, $user, RequestDocumentType::StationUpd, $station, $manager, $stationTotals['amount'], $stationTotals['vat'], [
            ...$common, ...$stationSide, 'rows' => $stationRows, 'totals' => $stationTotals,
            'basis' => $this->basis($stationOrder),
        ]);
        $documents[] = $this->store($request, $user, RequestDocumentType::StationInvoice, $station, $manager, $stationTotals['amount'], $stationTotals['vat'], [
            ...$common, ...$stationSide, 'rows' => $stationRows, 'totals' => $stationTotals,
            'basis' => $this->basis($stationOrder),
        ]);

        $customerOrder = $this->store($request, $user, RequestDocumentType::CustomerWorkOrder, $manager, $customer, $customerTotals['amount'], $customerTotals['vat'], [
            ...$common, ...$customerSide, 'rows' => $customerRows, 'totals' => $customerTotals,
        ]);
        $documents[] = $customerOrder;
        $documents[] = $this->store($request, $user, RequestDocumentType::CustomerUpd, $manager, $customer, $customerTotals['amount'], $customerTotals['vat'], [
            ...$common, ...$customerSide, 'rows' => $customerRows, 'totals' => $customerTotals,
            'basis' => $this->basis($customerOrder),
        ]);
        $documents[] = $this->store($request, $user, RequestDocumentType::CustomerInvoice, $manager, $customer, $customerTotals['amount'], $customerTotals['vat'], [
            ...$common, ...$customerSide, 'rows' => $customerRows, 'totals' => $customerTotals,
            'basis' => $this->basis($customerOrder),
        ]);
        $documents[] = $this->store($request, $user, RequestDocumentType::AgentReport, $manager, $customer, $customerTotals['amount'], $customerTotals['vat'], [
            ...$common, ...$customerSide, 'rows' => $customerRows, 'totals' => $customerTotals,
            'station' => $this->party($station, $request->stationRecord?->name),
            'contract' => RepairRequest::documentNumber('AD', (int) $customer->id),
            'basis' => $this->basis($customerOrder),
        ]);

        return $documents;
    }

    public function hasClosingDocuments(RepairRequest $request): bool
    {
        $types = $request->documents()->pluck('type')->map(fn ($type) => $type instanceof RequestDocumentType ? $type->value : (string) $type)->all();

        foreach (RequestDocumentType::closing() as $type) {
            if (!in_array($type->value, $types, true)) {
                return false;
            }
        }

        return true;
    }

    public function markPaid(RepairRequest $request, User $user, RequestDocumentType $type, string $paidAt, string $amount, string $comment): RequestDocument
    {
        $document = $request->document($type);

        if ($document === null) {
            Input::fail('Счёт на оплату ещё не сформирован');
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paidAt) || strtotime($paidAt) === false) {
            Input::fail('Укажите дату оплаты', 'paidAt');
        }

        if ($paidAt > now()->toDateString()) {
            Input::fail('Дата оплаты не может быть в будущем', 'paidAt');
        }

        if (!is_numeric($amount) || bccomp($amount, '0', self::MONEY_SCALE) <= 0) {
            Input::fail('Укажите сумму оплаты', 'amount');
        }

        if (bccomp($amount, (string) $document->amount, self::MONEY_SCALE) < 0) {
            Input::fail('Сумма оплаты меньше суммы счёта ' . $document->number, 'amount');
        }

        $document->forceFill([
            'paid_at'        => $paidAt,
            'paid_amount'    => $amount,
            'paid_comment'   => trim($comment),
            'paid_marked_at' => now(),
            'paid_marked_by' => $user->id,
        ])->save();

        return $document;
    }

    /**
     * Удалить записи, сформированные при «Принять оплату».
     *
     * @return array{0: RequestDocument, 1: string, 2: string} счёт, прежняя дата (d.m.Y) и сумма оплаты
     */
    public function deletePayment(RepairRequest $request, RequestDocumentType $type): array
    {
        $document = $request->document($type);

        if ($document === null || !$document->isPaid()) {
            Input::fail('Оплата по счёту не принята — удалять нечего');
        }

        $paidAt = (string) $document->paid_at?->format('d.m.Y');
        $paidAmount = (string) $document->paid_amount;

        $document->forceFill([
            'paid_at'        => null,
            'paid_amount'    => null,
            'paid_comment'   => null,
            'paid_marked_at' => null,
            'paid_marked_by' => null,
        ])->save();

        return [$document, $paidAt, $paidAmount];
    }

    public function isPaid(RepairRequest $request, RequestDocumentType $type): bool
    {
        return $request->document($type)?->isPaid() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function present(RepairRequest $request, UserDto $dto): array
    {
        $documents = $request->relationLoaded('documents')
            ? $request->documents
            : $request->documents()->with(['attachment', 'signedAttachment'])->get();

        $visible = $documents
            ->filter(fn (RequestDocument $doc): bool => $doc->type->visibleTo($dto->role))
            ->sortBy(fn (RequestDocument $doc): int => array_search($doc->type, RequestDocumentType::cases(), true))
            ->values();

        $transfer = $visible->first(fn (RequestDocument $doc): bool => $doc->type === RequestDocumentType::TransferAct);

        return [
            'documents' => $visible
                ->reject(fn (RequestDocument $doc): bool => $doc->type === RequestDocumentType::TransferAct)
                ->map(fn (RequestDocument $doc): array => $this->presentDocument($doc))
                ->values()
                ->all(),
            'transferAct' => $transfer ? [
                ...$this->presentDocument($transfer),
                'driver' => $transfer->payload['driver'] ?? null,
                'powerOfAttorney' => $transfer->payload['powerOfAttorney'] ?? null,
                'signedAttachmentId' => $transfer->signedAttachment?->uuid,
                'signedAttachmentName' => $transfer->signedAttachment?->original_name,
                'driverDocumentsVerified' => $transfer->driver_documents_verified,
                'signedAt' => $transfer->signed_at?->toISOString(),
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDocument(RequestDocument $doc): array
    {
        return [
            'id'          => $doc->uuid,
            'type'        => $doc->type->value,
            'label'       => $doc->type->label(),
            'number'      => $doc->number,
            'date'        => $doc->document_date->toDateString(),
            'seller'      => $doc->payload['seller']['name'] ?? '',
            'buyer'       => $doc->payload['buyer']['name'] ?? '',
            'side'        => $doc->type->isStationSide() ? 'station' : ($doc->type->isCustomerSide() ? 'customer' : 'vehicle'),
            'amount'      => (float) $doc->amount,
            'vatAmount'   => (float) $doc->vat_amount,
            'basis'       => $doc->payload['basis'] ?? '',
            'fileId'      => $doc->attachment?->uuid,
            'paidAt'      => $doc->paid_at?->toDateString(),
            'paidAmount'  => $doc->paid_amount !== null ? (float) $doc->paid_amount : null,
            'paidComment' => $doc->paid_comment ?? '',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function store(
        RepairRequest $request,
        User $user,
        RequestDocumentType $type,
        ?Organization $seller,
        ?Organization $buyer,
        string $amount,
        string $vat,
        array $payload,
    ): RequestDocument {
        /** @var RequestDocument $document */
        $document = RequestDocument::query()->firstOrNew([
            'repair_request_id' => $request->id,
            'type'              => $type->value,
        ]);

        $document->fill([
            'tenant_id'              => $request->tenant_id,
            'number'                 => $request->numberWithPrefix($type->prefix()) . $type->suffix(),
            'document_date'          => now()->toDateString(),
            'seller_organization_id' => $seller?->id,
            'buyer_organization_id'  => $buyer?->id,
            'amount'                 => $amount,
            'vat_amount'             => $vat,
            'payload'                => $payload,
            'created_by'             => $document->created_by ?? $user->id,
        ]);
        $document->save();

        $pdf = Pdf::loadView($type->view(), [
            'doc'     => $document,
            'title'   => $type->label(),
            'payload' => $payload,
            'side'    => $type->isStationSide() ? 'station' : 'customer',
        ])
            ->setPaper('a4', 'portrait')
            ->setOption('defaultFont', 'DejaVu Sans')
            ->output();

        $previous = $document->attachment_id ? Attachment::query()->find($document->attachment_id) : null;
        $objectKey = 'documents/' . $request->uuid . '/' . $type->value . '-' . Str::uuid() . '.pdf';
        Storage::disk(self::DISK)->put($objectKey, $pdf);

        $attachment = Attachment::query()->create([
            'uuid'                     => (string) Str::uuid(),
            'repair_request_id'        => $request->id,
            'uploaded_by'              => $user->id,
            'request_status_period_id' => $request->periods()->whereNull('exited_at')->value('id'),
            'disk'                     => self::DISK,
            'object_key'               => $objectKey,
            'original_name'            => str_replace('/', '-', $document->number) . ' ' . $type->label() . '.pdf',
            'mime_type'                => AttachmentMime::Pdf,
            'size'                     => strlen($pdf),
            'sha256'                   => hash('sha256', $pdf),
            'purpose'                  => AttachmentPurpose::GeneratedDocument,
        ]);

        $document->forceFill(['attachment_id' => $attachment->id])->save();

        if ($previous !== null) {
            $previous->delete();
            Storage::disk($previous->disk)->delete($previous->object_key);
        }

        return $document->refresh();
    }

    /**
     * Строки документов: для СТО → УК сумма строки уменьшается на агентскую
     * скидку, для УК → Заказчик — полная стоимость.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rows(RepairRequest $request, bool $stationSide, Organization $seller): array
    {
        $vatRate = $seller->vat_rate !== null ? (string) $seller->vat_rate : null;
        $rows = [];

        foreach ($request->workLines as $work) {
            /** @var RequestWork $work */
            $fields = AgentDiscount::workFields($work);
            $full = (string) ($work->line_amount ?? bcmul((string) $work->quantity, (string) ($work->price ?? 0), self::MONEY_SCALE));
            $rows[] = $this->row('work', (string) $work->name, (string) $work->quantity, 'н/ч', $stationSide && $fields['stationAmount'] !== null ? (string) $fields['stationAmount'] : $full, $vatRate);
        }

        foreach ($request->parts as $part) {
            /** @var RequestPart $part */
            $quantity = bcsub((string) $part->quantity, (string) $part->returned_quantity, 3);
            if (bccomp($quantity, '0', 3) <= 0) {
                continue;
            }
            $fields = AgentDiscount::partFields($part);
            $full = bcadd(bcmul($quantity, (string) $part->price, 5), '0.005', self::MONEY_SCALE);
            $name = trim((string) $part->name . ($part->article ? ' (' . $part->article . ')' : ''));
            $rows[] = $this->row('part', $name, $quantity, (string) ($part->unit ?: 'шт'), $stationSide && $fields['stationAmount'] !== null ? (string) $fields['stationAmount'] : $full, $vatRate);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $kind, string $name, string $quantity, string $unit, string $amount, ?string $vatRate): array
    {
        $amount = bcadd($amount, '0', self::MONEY_SCALE);
        $price = bccomp($quantity, '0', 3) > 0
            ? bcadd(bcdiv($amount, $quantity, 5), '0.005', self::MONEY_SCALE)
            : $amount;
        $vat = $vatRate !== null && bccomp($vatRate, '0', 2) > 0
            ? bcadd(bcdiv(bcmul($amount, $vatRate, 6), bcadd('100', $vatRate, 2), 6), '0.005', self::MONEY_SCALE)
            : '0.00';

        return [
            'kind'     => $kind,
            'name'     => $name,
            'quantity' => (float) $quantity,
            'unit'     => $unit,
            'price'    => (float) $price,
            'amount'   => (float) $amount,
            'vatRate'  => $vatRate !== null ? (float) $vatRate : null,
            'vat'      => (float) $vat,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array{amount: string, vat: string, works: string, parts: string}
     */
    private function totals(array $rows): array
    {
        $amount = '0.00';
        $vat = '0.00';
        $works = '0.00';
        $parts = '0.00';

        foreach ($rows as $row) {
            $value = number_format((float) $row['amount'], 2, '.', '');
            $amount = bcadd($amount, $value, self::MONEY_SCALE);
            $vat = bcadd($vat, number_format((float) $row['vat'], 2, '.', ''), self::MONEY_SCALE);
            if ($row['kind'] === 'work') {
                $works = bcadd($works, $value, self::MONEY_SCALE);
            } else {
                $parts = bcadd($parts, $value, self::MONEY_SCALE);
            }
        }

        return ['amount' => $amount, 'vat' => $vat, 'works' => $works, 'parts' => $parts];
    }

    private function basis(RequestDocument $order): string
    {
        return 'Заказ-наряд № ' . $order->number . ' от ' . $order->document_date->format('d.m.Y');
    }

    /**
     * Данные для предзаполнения «Листа приёма-передачи ТС».
     * in — приём ТС от Заказчика на СТО (APP-…/1), out — передача ТС от СТО Заказчику (APP-…/2).
     * Дата листа — дата выгрузки; представитель СТО — пользователь ЛК СТО,
     * который выгружает лист (для других ЛК — контактное лицо СТО).
     *
     * @return array<string, mixed>
     */
    public function handoverSheet(RepairRequest $request, string $direction, ?User $user = null): array
    {
        $request->loadMissing(['vehicleRecord', 'driverRecord', 'stationRecord.organization', 'customerOrganization', 'acceptanceAct', 'workOrder']);
        $vehicle = $request->vehicleRecord;
        $stationRecord = $request->stationRecord;
        $in = $direction === 'in';

        return [
            'direction'     => $in ? 'in' : 'out',
            'number'        => $in
                ? ($request->acceptanceAct?->number ?? $request->numberWithPrefix('APP') . '/1')
                : $request->numberWithPrefix(RequestDocumentType::TransferAct->prefix()) . RequestDocumentType::TransferAct->suffix(),
            'date'          => now()->toDateString(),
            'requestNumber' => $request->number,
            'workOrder'     => $request->workOrder?->number,
            'acceptanceNumber' => $request->acceptanceAct?->number,
            'customer'      => $this->party($request->customerOrganization),
            'station'       => [
                ...$this->party($stationRecord?->organization, $stationRecord?->name),
                'address' => (string) (($stationRecord?->organization?->actual_address ?? '') ?: ($stationRecord?->address ?? '')),
            ],
            'driver'        => $this->driver($request),
            'stationRepresentative' => $user?->role === UserRole::Station
                ? [
                    'fullName' => $user->fullName(),
                    'phone'    => (string) (($user->phone ?? '') ?: ($stationRecord?->organization?->phone ?? '')),
                ]
                : [
                    'fullName' => (string) ($stationRecord?->contact ?? ''),
                    'phone'    => (string) ($stationRecord?->organization?->phone ?? ''),
                ],
            // В лист попадает только реально оформленная доверенность; иначе
            // № и дата заполняются от руки.
            'powerOfAttorney' => $this->issuedPowerOfAttorney($request),
            'vehicle'       => [
                'name'  => trim(($vehicle?->make ?? '') . ' ' . ($vehicle?->model ?? '')),
                'plate' => (string) ($vehicle?->plate ?? ''),
                'vin'   => (string) ($vehicle?->vin ?? ''),
                'year'  => $vehicle?->year,
            ],
        ];
    }

    /** PDF предзаполненного листа приёма-передачи ТС (для печати и подписи). */
    public function handoverSheetPdf(RepairRequest $request, string $direction, ?User $user = null): string
    {
        return Pdf::loadView('pdf.handover_sheet', ['sheet' => $this->handoverSheet($request, $direction, $user)])
            ->setPaper('a4', 'portrait')
            ->setOption('defaultFont', 'DejaVu Sans')
            ->output();
    }

    /**
     * @return array<string, mixed>
     */
    private function party(?Organization $org, ?string $fallbackName = null): array
    {
        return [
            'name'        => (string) ($org?->name ?? $fallbackName ?? ''),
            'inn'         => (string) ($org?->inn ?? ''),
            'kpp'         => (string) ($org?->kpp ?? ''),
            'ogrn'        => (string) ($org?->ogrn ?? ''),
            'address'     => (string) ($org?->legal_address ?? ''),
            'director'    => (string) ($org?->director_name ?? ''),
            'bank'        => (string) ($org?->bank_name ?? ''),
            'bik'         => (string) ($org?->bik ?? ''),
            'account'     => (string) ($org?->settlement_account ?? ''),
            'corrAccount' => (string) ($org?->correspondent_account ?? ''),
            'vatRate'     => $org?->vat_rate !== null ? (float) $org->vat_rate : null,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function driver(RepairRequest $request): array
    {
        $snapshot = is_array($request->driver_snapshot) ? $request->driver_snapshot : ($request->driverRecord?->snapshot() ?? []);

        return [
            'fullName' => (string) ($snapshot['fullName'] ?? ''),
            'phone'    => (string) ($snapshot['phone'] ?? ''),
        ];
    }

    /**
     * Доверенность Заказчика на водителя (DV-…), с которой СТО сверяет
     * документы водителя при выдаче ТС.
     *
     * @return array<string, mixed>
     */
    private function powerOfAttorney(RepairRequest $request): array
    {
        $edo = $request->edoDocument(EdoDocumentType::PowerOfAttorney);

        return [
            'number' => $edo?->number ?? $request->numberWithPrefix('DV'),
            'date'   => $edo?->document_date?->toDateString(),
            'driver' => $this->driver($request)['fullName'],
        ];
    }

    /**
     * Действующая доверенность Заказчика на водителя, если она оформлена в системе.
     *
     * @return array{number: string, date: ?string}
     */
    private function issuedPowerOfAttorney(RepairRequest $request): array
    {
        $edo = $request->edoDocument(EdoDocumentType::PowerOfAttorney);

        return [
            'number' => (string) ($edo?->number ?? ''),
            'date'   => $edo?->document_date?->toDateString(),
        ];
    }

    public static function roleCanSee(UserRole $role, RequestDocument $document): bool
    {
        return $document->type->visibleTo($role);
    }
}
