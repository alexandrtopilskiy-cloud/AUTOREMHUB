<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AttachmentPurpose;
use App\Enums\RepairStage;
use App\Enums\RequestDocumentType;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\RepairRequest;
use App\Models\RequestDocument;
use App\Services\ClosingDocumentService;
use App\Services\MediaService;
use App\Services\RepairRequestService;
use App\Support\ApiInput;
use App\Support\ApiPresenter;
use App\Support\DomainInput as Input;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Закрытие ЗР: акт передачи ТС (СТО), документы и отметки об оплате (УК).
 */
final class ClosingDocumentController
{
    public function __construct(
        private readonly ApiPresenter $apiPresenter,
        private readonly RepairRequestService $service,
        private readonly ClosingDocumentService $documents,
        private readonly MediaService $media,
    ) {
    }

    /** Переформировать: СТО — акт передачи ТС, УК — комплект документов. */
    public function generate(Request $request, string $id): JsonResponse
    {
        ApiInput::only($request, ['revision']);
        $user = $request->user();

        $repairRequest = $this->service->mutate(
            $user,
            $id,
            ApiInput::revision($request),
            'documents_generated',
            function (RepairRequest $repairRequest) use ($user): array {
                $this->service->flag($repairRequest, $user, 'canGenerateDocuments');

                if ($repairRequest->stage === RepairStage::Ready) {
                    $act = $this->documents->generateTransferAct($repairRequest, $user);

                    return ['comment' => 'Сформирован акт передачи ТС ' . $act->number];
                }

                $this->documents->generateClosingDocuments($repairRequest, $user);

                return ['comment' => 'Сформированы документы по заявке'];
            },
        );

        return $this->respond($repairRequest, $user);
    }

    public function signTransferAct(Request $request, string $id): JsonResponse
    {
        ApiInput::only($request, ['revision', 'attachmentId', 'driverDocumentsVerified']);
        $user = $request->user();
        $attachmentId = (string) $request->input('attachmentId', '');
        $verified = filter_var($request->input('driverDocumentsVerified'), FILTER_VALIDATE_BOOLEAN);

        if ($attachmentId === '') {
            Input::fail('Загрузите скан подписанного акта передачи ТС', 'attachmentId');
        }

        $repairRequest = $this->service->mutate(
            $user,
            $id,
            ApiInput::revision($request),
            'transfer_act_signed',
            function (RepairRequest $repairRequest) use ($user, $attachmentId, $verified): array {
                $this->service->flag($repairRequest, $user, 'canSignTransferAct');
                $file = $this->service->attachmentIds($repairRequest, [$attachmentId], AttachmentPurpose::TransferAct)[0];
                $act = $this->documents->signTransferAct($repairRequest, $user, $file, $verified);

                return [
                    'comment' => 'Загружен подписанный акт передачи ТС ' . $act->number
                        . '; документы водителя сверены с доверенностью',
                    'after' => fn ($event) => $file->update(['request_event_id' => $event->id]),
                ];
            },
        );

        return $this->respond($repairRequest, $user);
    }

    public function customerPayment(Request $request, string $id): JsonResponse
    {
        return $this->markPayment($request, $id, 'canMarkCustomerPayment', RequestDocumentType::CustomerInvoice, 'Оплата Заказчика');
    }

    public function stationPayment(Request $request, string $id): JsonResponse
    {
        return $this->markPayment($request, $id, 'canMarkStationPayment', RequestDocumentType::StationInvoice, 'Оплата в пользу СТО');
    }

    public function pdf(Request $request, string $documentId): StreamedResponse
    {
        $user = $request->user();
        /** @var RequestDocument $document */
        $document = RequestDocument::query()
            ->with('attachment')
            ->where('uuid', $documentId)
            ->whereIn('repair_request_id', RepairRequest::forUser($user)->select('id'))
            ->firstOrFail();

        if (!$document->type->visibleTo($user->role) || $document->attachment === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Документ не найден');
        }

        return $this->media->download($document->attachment);
    }

    private function markPayment(Request $request, string $id, string $flag, RequestDocumentType $type, string $label): JsonResponse
    {
        ApiInput::only($request, ['revision', 'paidAt', 'amount', 'comment']);
        $user = $request->user();
        $paidAt = trim((string) $request->input('paidAt', ''));
        $amount = str_replace([' ', ','], ['', '.'], trim((string) $request->input('amount', '')));
        $comment = (string) $request->input('comment', '');

        $repairRequest = $this->service->mutate(
            $user,
            $id,
            ApiInput::revision($request),
            $type === RequestDocumentType::CustomerInvoice ? 'customer_payment' : 'station_payment',
            function (RepairRequest $repairRequest) use ($user, $flag, $type, $label, $paidAt, $amount, $comment): array {
                $this->service->flag($repairRequest, $user, $flag);
                $invoice = $this->documents->markPaid($repairRequest, $user, $type, $paidAt, $amount, $comment);

                return [
                    'comment' => $label . ' по счёту ' . $invoice->number . ' от ' . $invoice->paid_at?->format('d.m.Y')
                        . ': ' . number_format((float) $invoice->paid_amount, 2, ',', ' ') . ' ₽',
                ];
            },
        );

        return $this->respond($repairRequest, $user);
    }

    private function respond(RepairRequest $repairRequest, $user): JsonResponse
    {
        return response()->json(['request' => $this->apiPresenter->document($repairRequest, $user)]);
    }
}
