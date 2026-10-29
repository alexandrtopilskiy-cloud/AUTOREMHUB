<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Edo\EdoDocumentType;
use App\Exceptions\ApiException;
use App\Http\Requests\Edo\PrepareEdoDocumentRequest;
use App\Http\Requests\Edo\SignAndSendEdoDocumentRequest;
use App\Http\Resources\EdoDocumentCollection;
use App\Http\Resources\EdoDocumentResource;
use App\Http\Resources\EdoPreparedDocumentResource;
use App\Http\Requests\Edo\AcceptTitleEdoDocumentRequest;
use App\Http\Requests\Edo\PrepareManagerInvoiceRequest;
use App\Http\Requests\Edo\PrepareUcdRequest;
use App\Models\EdoDocument;
use App\Models\Organization;
use App\Models\RepairRequest;
use App\DTO\EdoSignature;
use App\DTO\EdoSignerInfo;
use App\Services\Edo\EdoService;
use App\Services\MediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class EdoDocumentController
{
    public function __construct(
        private readonly EdoService $service,
        private readonly MediaService $media,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $organizationId = $user->organization_id;

        $query = EdoDocument::query()
            ->where(function ($q) use ($organizationId) {
                $q
                    ->where('organization_id', $organizationId)
                    ->orWhere('counterparty_id', $organizationId);
            })
            ->with(['counterparty', 'organization', 'payloadAttachment'])
            ->orderByDesc('id');

        // Фильтр по статусу
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // Фильтр по типу
        if ($request->filled('type')) {
            $query->where('document_type', $request->input('type'));
        }

        // Фильтр по контрагенту
        if ($request->filled('counterparty_id')) {
            $query->where('counterparty_id', $request->input('counterparty_id'));
        }

        return response()->json([
            'documents' => EdoDocumentResource::collection($query->paginate(50)),
        ]);
    }

    public function show(Request $request, string $uuid): EdoDocumentResource
    {
        $document = EdoDocument::resolveForUser($request->user(), $uuid);

        $document->load([
            'organization',
            'counterparty',
            'payloadAttachment',
            'signedAttachment',
        ]);

        return new EdoDocumentResource($document);
    }

    public function prepare(PrepareEdoDocumentRequest $request, string $id): JsonResponse
    {
        $user = $request->user();

        $repairRequest = RepairRequest::resolveFor($user, $id);

        $counterparty = Organization::query()
            ->where('id', $request->integer('counterparty_organization_id'))
            ->where('tenant_id', $repairRequest->tenant_id)
            ->firstOrFail();

        if ($counterparty->edo_edi_code === null || $counterparty->edo_edi_code === '') {
            throw new ApiException(
                422,
                'EDO_COUNTERPARTY_NOT_REGISTERED',
                sprintf(
                    'Организация «%s» не зарегистрирована в ЭДО',
                    $counterparty->short_name,
                ),
            );
        }

        $prepared = $this->service->prepareStructuredDocument(
            request:         $repairRequest,
            type:            EdoDocumentType::from((string) $request->string('document_type')),
            counterparty:    $counterparty,
            initiatorUserId: (int) $user->id,
        );

        return (new EdoPreparedDocumentResource($prepared))
            ->response()
            ->setStatusCode(201);
    }

    public function signAndSend(SignAndSendEdoDocumentRequest $request, string $uuid): EdoDocumentResource
    {
        $document = EdoDocument::resolveForUser($request->user(), $uuid);

        $signature = new EdoSignature(
            content: $request->validated('signature_base64'),
            certificateFingerprint: $request->input('certificateFingerprint', ''),
        );

        $this->service->signAndSend(
            document:  $document,
            signature: $signature,
        );

        $document->refresh()->load(['organization', 'counterparty']);

        return new EdoDocumentResource($document);
    }

    public function sync(Request $request, string $uuid): EdoDocumentResource
    {
        $document = EdoDocument::resolveForUser($request->user(), $uuid);

        $this->service->syncStatus($document);

        $document->refresh()->load(['organization', 'counterparty']);

        return new EdoDocumentResource($document);
    }

    public function content(Request $request, string $uuid): StreamedResponse
    {
        $document = EdoDocument::resolveForUser($request->user(), $uuid);

        $attachment = $document->payloadAttachment;

        if ($attachment === null) {
            throw new ApiException(404, 'NOT_FOUND', 'XML-файл не найден');
        }

        return $this->media->download($attachment);
    }

    public function pdf(Request $request, string $uuid): StreamedResponse
    {
        $document = EdoDocument::resolveForUser($request->user(), $uuid);

        $attachment = $document->signedAttachment;

        if ($attachment === null) {
            throw new ApiException(404, 'NOT_FOUND', 'PDF-файл не найден');
        }

        return $this->media->download($attachment);
    }

    public function acceptTitle(AcceptTitleEdoDocumentRequest $request, string $uuid): EdoDocumentResource
    {
        $user = $request->user();

        $document = EdoDocument::resolveForUser($request->user(), $uuid);

        $signer = new EdoSignerInfo(
            inn:              (string) $request->string('signer.inn'),
            organizationName: (string) $request->string('signer.organization_name'),
            lastName:         (string) $request->string('signer.last_name'),
            firstName:        (string) $request->string('signer.first_name'),
            middleName:       $request->filled('signer.middle_name') ? (string) $request->string('signer.middle_name') : null,
            title:            (string) $request->string('signer.title'),
            authority:        (string) $request->string('signer.authority'),
            authorityScope:   (int) $request->integer('signer.authority_scope'),
            condition:        (int) $request->integer('signer.condition'),
        );

        $this->service->acceptTitle(
            document: $document,
            signer:   $signer,
            userId:   (int) $user->id,
        );

        $document->refresh()->load(['organization', 'counterparty', 'acceptanceAttachment']);

        return new EdoDocumentResource($document);
    }

    public function prepareManagerInvoice(
        PrepareManagerInvoiceRequest $request,
        string $id,
    ): JsonResponse {
        $user = $request->user();
        $repairRequest = RepairRequest::resolveFor($user, $id);

        $prepared = $this->service->prepareInvoiceForManagerToCustomer(
            request:      $repairRequest,
            userId:       (int) $user->id,
            documentType: $request->documentType(),
        );

        return (new EdoPreparedDocumentResource($prepared))
            ->response()
            ->setStatusCode(201);
    }

    public function prepareUcd(
        PrepareUcdRequest $request,
        string $id,
    ): JsonResponse {
        $user = $request->user();
        $repairRequest = RepairRequest::resolveFor($user, $id);

        $baseUuid = (string) $request->string('base_document_uuid');

        $baseDocument = EdoDocument::resolveForUser($user, $baseUuid);

        $prepared = $this->service->prepareUcdForStationToManager(
            request:      $repairRequest,
            baseDocument: $baseDocument,
            userId:       (int) $user->id,
        );

        return (new EdoPreparedDocumentResource($prepared))
            ->response()
            ->setStatusCode(201);
    }

    public function retry(Request $request, string $uuid): JsonResponse
    {
        $user = $request->user();
        $document = EdoDocument::query()
            ->where('uuid', $uuid)
            ->where(function ($q) use ($user) {
                $q
                    ->where('organization_id', $user->organization_id)
                    ->orWhere('counterparty_id', $user->organization_id);
            })
            ->firstOrFail();


        if (!in_array($document->status, ['rejected', 'unknown'], true)) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'Документ нельзя отправить повторно');
        }

        // Сбрасываем статус и отправляем событие заново
        $document->update(['status' => 'draft']);

        // В зависимости от типа документа, диспатчим нужное событие
        // (можно взять из meta или source)
        // Например:
        // \App\Events\RepairRequests\InvoiceIssued::dispatch($request, $invoice);

        return response()->json(['message' => 'Документ поставлен в очередь на повторную отправку']);
    }
}
