<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTO\UserDto;
use App\Enums\AttachmentPurpose;
use App\Enums\RepairStage;
use App\Enums\RequestDocumentType;
use App\Events\RepairRequests\ClosingDocumentsRequested;
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
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
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
        $userDto = UserDto::fromUserModel($user);

        $req = $this->service->mutate(
            $userDto,
            $id,
            ApiInput::revision($request),
            'documents_generated',
            function (RepairRequest $req) use ($userDto): array {
                $this->service->flag($req, $userDto, 'canGenerateDocuments');

                if ($req->stage === RepairStage::Ready) {
                    $act = $this->documents->generateTransferAct($req, $userDto);

                    return ['comment' => 'Сформирован лист приёма-передачи ТС (выдача) ' . $act->number];
                }

                $this->documents->markGenerationQueued($req);
                $requestId = $req->id;
                $userId = $userDto->userId;
                DB::afterCommit(static function () use ($requestId, $userId): void {
                    ClosingDocumentsRequested::dispatch($requestId, $userId);
                });

                return ['comment' => 'Документы по заявке поставлены на формирование и отправку в ЭДО'];
            },
        );

        return $this->respond($req, $userDto);
    }

    public function signTransferAct(Request $request, string $id): JsonResponse
    {
        ApiInput::only($request, ['revision', 'attachmentId', 'driverDocumentsVerified']);
        $user = $request->user();
        $userDto = UserDto::fromUserModel($user);
        $attachmentId = (string) $request->input('attachmentId', '');
        $verified = filter_var($request->input('driverDocumentsVerified'), FILTER_VALIDATE_BOOLEAN);

        if ($attachmentId === '') {
            Input::fail('Загрузите подписанный лист приёма-передачи ТС', 'attachmentId');
        }

        $repairRequest = $this->service->mutate(
            $userDto,
            $id,
            ApiInput::revision($request),
            'transfer_act_signed',
            function (RepairRequest $req) use ($userDto, $attachmentId, $verified): array {
                $this->service->flag($req, $userDto, 'canSignTransferAct');
                $file = $this->service->attachmentIds($req, [$attachmentId], AttachmentPurpose::TransferAct)[0];
                $act = $this->documents->signTransferAct($req, $userDto, $file, $verified);

                return [
                    'comment' => 'Загружен подписанный лист приёма-передачи ТС ' . $act->number
                        . ' (приём ТС от СТО на Заказчика); лицо, которому передаётся ТС, сверено с действующей доверенностью',
                    'after' => fn ($event) => $file->update(['request_event_id' => $event->id]),
                ];
            },
        );

        return $this->respond($repairRequest, $userDto);
    }

    public function customerPayment(Request $request, string $id): JsonResponse
    {
        return $this->markPayment($request, $id, 'canMarkCustomerPayment', RequestDocumentType::CustomerInvoice, 'Оплата Заказчика');
    }

    public function stationPayment(Request $request, string $id): JsonResponse
    {
        return $this->markPayment($request, $id, 'canMarkStationPayment', RequestDocumentType::StationInvoice, 'Оплата в пользу СТО');
    }

    /** УК: удалить принятую оплату Заказчика — только на этапе «Подтверждение оплаты Заказчика». */
    public function deleteCustomerPayment(Request $request, string $id): JsonResponse
    {
        return $this->deletePayment($request, $id, 'canMarkCustomerPayment', RequestDocumentType::CustomerInvoice, 'Оплата Заказчика');
    }

    /** УК: удалить принятую оплату в пользу СТО — только на этапе «Оплата в СТО». */
    public function deleteStationPayment(Request $request, string $id): JsonResponse
    {
        return $this->deletePayment($request, $id, 'canMarkStationPayment', RequestDocumentType::StationInvoice, 'Оплата в пользу СТО');
    }

    public function pdf(Request $request, string $documentId): StreamedResponse
    {
        $user = $request->user();
        $dto = UserDto::fromUserModel($user);
        $repqirRequestQuery = RepairRequest::forUser($dto)->select('id');

        /** @var RequestDocument $document */
        $document = RequestDocument::query()
            ->with('attachment')
            ->where('uuid', $documentId)
            ->whereIn('repair_request_id', $repqirRequestQuery)
            ->firstOrFail();

        if (!$document->type->visibleTo($user->role) || $document->attachment === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Документ не найден');
        }

        return $this->media->download($document->attachment);
    }

    /**
     * Предзаполненный «Лист приёма-передачи ТС» для печати:
     * in — приём ТС от Заказчика на СТО, out — передача ТС от СТО Заказчику.
     */
    public function handoverSheet(Request $request, string $id, string $direction): Response
    {
        $user = $request->user();
        $userDto = UserDto::fromUserModel($user);
        $repairRequest = RepairRequest::resolveFor($userDto, $id);
        $sheet = $this->documents->handoverSheet($repairRequest, $direction, $userDto);
        $pdf = $this->documents->handoverSheetPdf($repairRequest, $direction, $userDto);
        $name = str_replace('/', '-', (string) $sheet['number']) . ' Лист приёма-передачи ТС.pdf';

        return response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"handover.pdf\"; filename*=UTF-8''" . rawurlencode($name),
        ]);
    }

    private function markPayment(Request $request, string $id, string $flag, RequestDocumentType $type, string $label): JsonResponse
    {
        ApiInput::only($request, ['revision', 'paidAt', 'amount', 'comment']);
        $user = $request->user();
        $userDto = UserDto::fromUserModel($user);
        $paidAt = trim((string) $request->input('paidAt', ''));
        $amount = str_replace([' ', ','], ['', '.'], trim((string) $request->input('amount', '')));
        $comment = (string) $request->input('comment', '');

        $req = $this->service->mutate(
            $userDto,
            $id,
            ApiInput::revision($request),
            $type === RequestDocumentType::CustomerInvoice ? 'customer_payment' : 'station_payment',
            function (RepairRequest $req) use ($userDto, $flag, $type, $label, $paidAt, $amount, $comment): array {
                $this->service->flag($req, $userDto, $flag);
                $invoice = $this->documents->markPaid($req, $userDto, $type, $paidAt, $amount, $comment);

                return [
                    'comment' => $label . ' по счёту ' . $invoice->number . ' от ' . $invoice->paid_at?->format('d.m.Y')
                        . ': ' . number_format((float) $invoice->paid_amount, 2, ',', ' ') . ' ₽',
                ];
            },
        );

        return $this->respond($req, $userDto);
    }

    private function deletePayment(Request $request, string $id, string $flag, RequestDocumentType $type, string $label): JsonResponse
    {
        ApiInput::only($request, ['revision']);
        $user = $request->user();
        $userDto = UserDto::fromUserModel($user);

        $req = $this->service->mutate(
            $userDto,
            $id,
            ApiInput::revision($request),
            $type === RequestDocumentType::CustomerInvoice ? 'customer_payment_deleted' : 'station_payment_deleted',
            function (RepairRequest $req) use ($userDto, $flag, $type, $label): array {
                $this->service->flag($req, $userDto, $flag);
                [$invoice, $paidAt, $paidAmount] = $this->documents->deletePayment($req, $type);

                return [
                    'comment' => $label . ' по счёту ' . $invoice->number . ' удалена (была принята '
                        . $paidAt . ': ' . number_format((float) $paidAmount, 2, ',', ' ') . ' ₽)',
                ];
            },
        );

        return $this->respond($req, $userDto);
    }

    private function respond(RepairRequest $repairRequest, UserDto $userDto): JsonResponse
    {
        return response()->json(['request' => $this->apiPresenter->document($repairRequest, $userDto)]);
    }
}
