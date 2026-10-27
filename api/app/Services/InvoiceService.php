<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AttachmentPurpose;
use App\Enums\InvoiceStatus;
use App\Events\RepairRequests\InvoiceIssued;
use App\Models\Attachment;
use App\Models\Invoice;
use App\Models\RepairRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class InvoiceService
{
    public function __construct(
        private readonly DocumentGeneratorService $documentGenerator
    ) {}

    public function generateAndIssue(RepairRequest $request, User $user): void
    {
        if ($request->invoice()->exists()) {
            return;
        }

        if (!$request->workOrder) {
            throw new \RuntimeException('Заказ-наряд ещё не сформирован');
        }

        $number = 'INV-' . $request->number;
        $invoiceUuid = (string) Str::uuid();

        $invoice = new Invoice();
        $invoice->uuid = $invoiceUuid;
        $invoice->repair_request_id = $request->id;
        $invoice->number = $number;
        $invoice->amount = $request->workOrder->total;
        $invoice->status = InvoiceStatus::Issued;

        // Генерация PDF
        $pdfContent = $this->documentGenerator->generateInvoice($request, $invoice);
        $objectKey = 'invoices/' . $invoiceUuid . '.pdf';
        Storage::disk('local')->put($objectKey, $pdfContent);

        // Создание Attachment
        $currentPeriodId = $request->periods()->whereNull('exited_at')->first()?->id;

        $attachment = new Attachment();
        $attachment->uuid = (string) Str::uuid();
        $attachment->repair_request_id = $request->id;
        $attachment->uploaded_by = $user->id;
        $attachment->request_status_period_id = $currentPeriodId;
        $attachment->disk = 'local';
        $attachment->object_key = $objectKey;
        $attachment->original_name = "Счет_{$number}.pdf";
        $attachment->mime_type = 'application/pdf';
        $attachment->size = strlen($pdfContent);
        $attachment->sha256 = hash('sha256', $pdfContent);
        $attachment->purpose = AttachmentPurpose::IssuedInvoice;
        $attachment->save();

        $invoice->document_attachment_id = $attachment->id;
        $invoice->save();

        DB::afterCommit(function () use ($request, $invoice): void {
            InvoiceIssued::dispatch($request, $invoice);
        });
    }

    public function submitPayment(RepairRequest $request, User $user): void
    {
        $invoice = $request->invoice;

        // Если счета нет или он уже проверен — ничего не делаем
        if (!$invoice || $invoice->status === InvoiceStatus::Paid) {
            return;
        }

        // Если чек уже прикреплен, но статус не Submitted — обновляем статус
        if ($invoice->status === InvoiceStatus::Submitted) {
            return;
        }

        // 1. Генерируем файл-заглушку (чек)
        $fileContent = base64_encode("Чек об оплате по заявке {$request->number}. Сумма: {$invoice->amount}");
        $objectKey = 'payments/' . $invoice->uuid . '_paid.pdf';
        Storage::disk('local')->put($objectKey, $fileContent);

        // 2. Получаем текущий период
        $currentPeriodId = $request->periods()->whereNull('exited_at')->first()?->id;

        if (!$currentPeriodId) {
            throw new \RuntimeException("Не найден активный период для заявки {$request->number}");
        }

        // 3. Создаем Attachment
        $attachment = new Attachment();
        $attachment->uuid = (string) Str::uuid();
        $attachment->repair_request_id = $request->id;
        $attachment->uploaded_by = $user->id;
        $attachment->request_status_period_id = $currentPeriodId;
        $attachment->disk = 'local';
        $attachment->object_key = $objectKey;
        $attachment->original_name = "Чек_об_оплате_{$invoice->number}.pdf";
        $attachment->mime_type = 'application/pdf';
        $attachment->size = strlen($fileContent);
        $attachment->sha256 = hash('sha256', $fileContent);
        $attachment->purpose = AttachmentPurpose::PaidInvoice;
        $attachment->save();

        // 4. Обновляем счет
        $invoice->update([
            'status'                      => InvoiceStatus::Submitted,
            'paid_amount'                 => $invoice->amount,
            'paid_at'                     => now(),
            'paid_document_attachment_id' => $attachment->id,
        ]);
    }

    public function verifyPayment(RepairRequest $request, User $user): void
    {
        $invoice = $request->invoice;

        if (!$invoice || $invoice->status === InvoiceStatus::Paid) {
            return;
        }

        // Если чек об оплате не прикреплен (хотя мы его генерировали на прошлом шаге),
        // на всякий случай создадим его.
        if (!$invoice->paid_document_attachment_id) {
            $this->ensurePaymentSubmitted($request, $user);
            $invoice->refresh();
        }

        $invoice->update([
            'status'      => InvoiceStatus::Paid,
            'verified_by' => $user->id,
            'verified_at' => now(),
        ]);
    }

    /**
     * Автоматически прикрепляет чек об оплате, если УК переводит заявку
     * на проверку оплаты.
     */
    private function ensurePaymentSubmitted(RepairRequest $request, User $user): void
    {
        $invoice = $request->invoice;

        // Если счета нет или он уже проверен — ничего не делаем
        if (!$invoice || $invoice->status === InvoiceStatus::Paid) {
            return;
        }

        // Если чек уже прикреплен, но статус не Submitted — обновляем статус
        if ($invoice->status === InvoiceStatus::Submitted) {
            return;
        }

        // 1. Генерируем файл-заглушку (чек)
        $fileContent = base64_encode("Чек об оплате по заявке {$request->number}. Сумма: {$invoice->amount}");
        $objectKey = 'payments/' . $invoice->uuid . '_paid.pdf';
        Storage::disk('local')->put($objectKey, $fileContent);

        // 2. Получаем текущий период
        $currentPeriodId = $request->periods()->whereNull('exited_at')->first()?->id;

        if (!$currentPeriodId) {
            throw new \RuntimeException("Не найден активный период для заявки {$request->number}");
        }

        // 3. Создаем Attachment
        $attachment = new Attachment();
        $attachment->uuid = (string) Str::uuid();
        $attachment->repair_request_id = $request->id;
        $attachment->uploaded_by = $user->id;
        $attachment->request_status_period_id = $currentPeriodId;
        $attachment->disk = 'local';
        $attachment->object_key = $objectKey;
        $attachment->original_name = "Чек_об_оплате_{$invoice->number}.pdf";
        $attachment->mime_type = 'application/pdf';
        $attachment->size = strlen($fileContent);
        $attachment->sha256 = hash('sha256', $fileContent);
        $attachment->purpose = AttachmentPurpose::PaidInvoice;
        $attachment->save();

        // 4. Обновляем счет
        $invoice->update([
            'status'                      => InvoiceStatus::Submitted,
            'paid_amount'                 => $invoice->amount,
            'paid_at'                     => now(),
            'paid_document_attachment_id' => $attachment->id,
        ]);
    }
}