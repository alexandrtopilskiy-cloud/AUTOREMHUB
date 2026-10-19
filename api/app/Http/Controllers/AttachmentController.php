<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AttachmentMime;
use App\Enums\AttachmentPurpose;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Http\Requests\StoreAttachmentRequest;
use App\Models\Attachment;
use App\Models\RepairRequest;
use App\Services\MediaService;
use App\Services\RepairRequestService;
use App\Support\ApiPresenter;
use App\Support\DomainInput as Input;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class AttachmentController
{
    public function __construct(
        private readonly RepairRequestService $service,
        private readonly MediaService $media,
        private readonly ApiPresenter $apiPresenter,
    ) {
    }

    public function store(StoreAttachmentRequest $request, string $id): JsonResponse
    {
        $user = $request->user();

        $repairRequest = RepairRequest::resolveFor($user, $id);
        $this->service->flag($repairRequest, $user, 'canUpload');

        $file = $request->file('file');
        $mime = $file->getMimeType();
        $purpose = AttachmentPurpose::from((string) $request->input('purpose'));
        $extension = $this->extensionFor($mime, $purpose);

        $this->assertPurposeAllowedForRole($user->role, $purpose);
        $this->assertPurposeMatchesStage($repairRequest, $purpose);

        $this->media->assertConfigured();

        $key = sprintf(
            'tenant-%d/requests/%s/%s.%s',
            $repairRequest->tenant_id,
            $repairRequest->uuid,
            Str::uuid(),
            $extension,
        );

        $this->putToStorage($key, $file, $mime);

        try {
            $updated = $this->attachAndMutate($repairRequest, $request, $file, $mime, $purpose, $key);
        } catch (Throwable $e) {
            $this->safeDelete($key);
            throw $e;
        }

        return response()->json(
            ['request' => $this->apiPresenter->document($updated, $user)],
            201,
        );
    }

    public function url(Request $request, string $attachmentId): JsonResponse
    {
        $attachment = Attachment::query()
            ->where('uuid', $attachmentId)
            ->whereIn(
                'repair_request_id',
                RepairRequest::forUser($request->user())->select('id'),
            )
            ->firstOrFail();

        return response()->json([
            'url'       => $this->media->url($attachment),
            'expiresIn' => 300,
        ]);
    }

    private function attachAndMutate(
        RepairRequest $repairRequest,
        StoreAttachmentRequest $request,
        $file,
        string $mime,
        AttachmentPurpose $purpose,
        string $key,
    ): RepairRequest
    {
        return $this->service->mutate(
            $request->user(),
            (string) $repairRequest->uuid,
            (int) $request->input('revision'),
            'attachment',
            function (RepairRequest $r) use ($request, $key, $file, $mime, $purpose): array {
                $this->service->flag($r, $request->user(), 'canUpload');
                $this->assertPurposeAllowedForRole($request->user()->role, $purpose);
                $this->assertPurposeMatchesStage($r, $purpose);

                Attachment::create([
                    'repair_request_id'         => $r->id,
                    'uploaded_by'               => $request->user()->id,
                    'request_status_period_id'  => $r->periods()->whereNull('exited_at')->firstOrFail()->id,
                    'purpose'                   => $purpose,
                    'disk'                      => 'media',
                    'object_key'                => $key,
                    'original_name'             => mb_substr(basename($file->getClientOriginalName()), 0, 200),
                    'mime_type'                 => $mime,
                    'size'                      => $file->getSize(),
                    'sha256'                    => hash_file('sha256', $file->getRealPath()),
                ]);

                return ['comment' => 'Прикреплён файл: ' . basename($file->getClientOriginalName())];
            },
        );
    }

    private function putToStorage(string $key, $file, string $mime): void
    {
        try {
            $stored = Storage::disk('media')->putFileAs(
                dirname($key),
                $file,
                basename($key),
                ['visibility' => 'private', 'ContentType' => $mime],
            );

            if (!$stored) {
                throw new RuntimeException('Storage write failed');
            }
        } catch (Throwable $e) {
            report($e);
            throw new ApiException(503, 'UPLOAD_FAILED', 'Не удалось загрузить файл в облако');
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

    private function extensionFor(string $mime, AttachmentPurpose $purpose): string
    {
        $attachmentMime = AttachmentMime::tryFrom($mime);

        if ($attachmentMime === null || !$purpose->allowsMime($attachmentMime)) {
            Input::fail('Разрешены JPEG, PNG, WebP, MP4, WebM, MOV; для документов — PDF');
        }

        return $attachmentMime->extension();
    }

    private function assertPurposeMatchesStage(
        RepairRequest $repairRequest,
        AttachmentPurpose $purpose,
    ): void
    {
        if (!$purpose->allowsStage($repairRequest->stage)) {
            Input::fail('Назначение файла не соответствует текущему этапу');
        }
    }

    private function assertPurposeAllowedForRole(
        UserRole $role,
        AttachmentPurpose $purpose,
    ): void
    {
        if ($role === UserRole::Manager && $purpose !== AttachmentPurpose::IssuedInvoice) {
            throw new ApiException(
                403,
                'FORBIDDEN',
                'УК загружает только документ выставленного счёта',
            );
        }
    }

    public function download(Request $request, string $attachmentId): StreamedResponse
    {
        $attachment = Attachment::query()
            ->where('uuid', $attachmentId)
            ->whereIn(
                'repair_request_id',
                RepairRequest::forUser($request->user())->select('id'),
            )
            ->firstOrFail();

        return $this->media->download($attachment);
    }
}
