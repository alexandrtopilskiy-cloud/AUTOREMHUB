<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AttachmentPurpose;
use App\Enums\InvoiceStatus;
use App\Events\RepairRequests\InvoiceIssued;
use App\Http\Requests\IssueInvoiceRequest;
use App\Http\Requests\SubmitInvoicePaymentRequest;
use App\Http\Requests\VerifyInvoicePaymentRequest;
use App\Models\Invoice;
use App\Models\RepairRequest;
use App\Services\RepairRequestService;
use App\Support\ApiInput;
use App\Support\ApiPresenter;
use App\Support\DomainInput as Input;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class InvoiceController
{
    public function __construct(
        private readonly ApiPresenter $apiPresenter,
    ) {
    }

    public function issue(
        IssueInvoiceRequest $request,
        string $id,
        RepairRequestService $service,
    ): JsonResponse {
        $number = trim((string) $request->validated('number'));

        if ($number === '') {
            Input::fail('Укажите номер счёта', 'number');
        }

        $repairRequest = $service->mutate(
            $request->user(),
            $id,
            ApiInput::revision($request),
            'invoice',
            function (RepairRequest $repairRequest) use ($request, $service, $number): array {
                $service->flag($repairRequest, $request->user(), 'canIssueInvoice');

                $amount = (string) $request->validated('amount');

                if (!$repairRequest->workOrder || bccomp($amount, $repairRequest->workOrder->total, 2) !== 0) {
                    Input::fail('Сумма счёта должна совпадать с заказ-нарядом');
                }

                $file = $service->attachmentIds(
                    $repairRequest,
                    [$request->validated('attachmentId')],
                    AttachmentPurpose::IssuedInvoice,
                )[0];

                $invoice = $repairRequest->invoice ?? new Invoice(['repair_request_id' => $repairRequest->id]);

                $invoice->fill([
                    'number'                       => $number,
                    'amount'                       => $request->validated('amount'),
                    'status'                       => InvoiceStatus::Issued,
                    'document_attachment_id'       => $file->id,
                    'paid_document_attachment_id'  => null,
                    'paid_amount'                  => null,
                    'paid_at'                      => null,
                    'verified_by'                  => null,
                    'verified_at'                  => null,
                ]);

                $invoice->save();

                return [
                    'comment' => 'Выставлен счёт ' . $number,
                    'after' => function ($event) use ($file, $repairRequest, $invoice): void {
                        $file->update(['request_event_id' => $event->id]);

                        DB::afterCommit(function () use ($repairRequest, $invoice): void {
                            InvoiceIssued::dispatch($repairRequest, $invoice);
                        });
                    },
                ];
            },
        );

        return response()->json([
            'request' => $this->apiPresenter->document($repairRequest, $request->user()),
        ]);
    }

    public function payment(
        SubmitInvoicePaymentRequest $request,
        string $id,
        RepairRequestService $service,
    ): JsonResponse {
        $comment = trim((string) $request->validated('comment'));

        if ($comment === '') {
            Input::fail('Укажите комментарий об оплате', 'comment');
        }

        $repairRequest = $service->mutate(
            $request->user(),
            $id,
            ApiInput::revision($request),
            'payment',
            function (RepairRequest $repairRequest) use ($request, $service, $comment): array {
                $service->flag($repairRequest, $request->user(), 'canSubmitPayment');

                $invoice = $repairRequest->invoice;

                if (!$invoice) {
                    Input::fail('Счёт не выставлен');
                }

                $paidAmount = (string) $request->validated('paidAmount');

                if (bccomp($paidAmount, (string) $invoice->amount, 2) < 0) {
                    Input::fail('Для полного закрытия нужна оплата всей суммы счёта');
                }

                $paidAt = CarbonImmutable::parse($request->validated('paidAt'));

                if ($paidAt->isFuture()) {
                    Input::fail('Дата оплаты не может быть в будущем');
                }

                $file = $service->attachmentIds(
                    $repairRequest,
                    [$request->validated('attachmentId')],
                    AttachmentPurpose::PaidInvoice,
                )[0];

                $invoice->update([
                    'status'                      => InvoiceStatus::Submitted,
                    'paid_amount'                 => $request->validated('paidAmount'),
                    'paid_at'                     => $paidAt,
                    'paid_document_attachment_id' => $file->id,
                    'verified_at'                 => null,
                    'verified_by'                 => null,
                ]);

                return [
                    'comment' => 'Заказчик приложил закрытый счёт. ' . $comment,
                    'after'   => fn ($event) => $file->update(['request_event_id' => $event->id]),
                ];
            },
        );

        return response()->json([
            'request' => $this->apiPresenter->document($repairRequest, $request->user()),
        ]);
    }

    public function verify(
        VerifyInvoicePaymentRequest $request,
        string $id,
        RepairRequestService $service,
    ): JsonResponse {
        $comment = trim((string) $request->validated('comment'));

        if ($comment === '') {
            Input::fail('Укажите комментарий проверки', 'comment');
        }

        $repairRequest = $service->mutate(
            $request->user(),
            $id,
            ApiInput::revision($request),
            'payment_verified',
            function (RepairRequest $repairRequest) use ($request, $service, $comment): array {
                $service->flag($repairRequest, $request->user(), 'canVerifyPayment');

                $invoice = $repairRequest->invoice;

                if (
                    !$invoice
                    || $invoice->status !== InvoiceStatus::Submitted
                    || !$invoice->paid_document_attachment_id
                    || bccomp((string) $invoice->paid_amount, (string) $invoice->amount, 2) < 0
                ) {
                    Input::fail('Нет полного подтверждения оплаты');
                }

                $invoice->update([
                    'status'      => InvoiceStatus::Paid,
                    'verified_by' => $request->user()->id,
                    'verified_at' => now(),
                ]);

                return ['comment' => 'УК проверила оплату: ' . $comment];
            },
        );

        return response()->json([
            'request' => $this->apiPresenter->document($repairRequest, $request->user()),
        ]);
    }
}
