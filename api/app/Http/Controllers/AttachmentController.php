<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTO\UserDto;
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
    ) {}

    public function store(StoreAttachmentRequest $request, string $id): JsonResponse
    {
        $user = $request->user();
        $userDto = UserDto::fromUserModel($user);
        $req = RepairRequest::resolveFor($userDto, $id);

        $this->service->flag($req, $userDto, 'canUpload');

        $file = $request->file('file');
        $mime = $file->getMimeType();
        $purpose = AttachmentPurpose::from((string) $request->input('purpose'));
        $extension = $this->extensionFor($mime, $purpose);

        $this->assertPurposeAllowedForRole($userDto->role, $purpose);
        $this->assertPurposeMatchesStage($req, $purpose);

        $this->media->assertConfigured();

        $key = sprintf(
            'tenant-%d/requests/%s/%s.%s',
            $req->tenant_id,
            $req->uuid,
            Str::uuid(),
            $extension,
        );

        $this->putToStorage($key, $file, $mime);

        try {
            $updated = $this->attachAndMutate($req, $request, $file, $mime, $purpose, $key);
        } catch (Throwable $e) {
            $this->safeDelete($key);
            throw $e;
        }

        return response()->json(
            ['request' => $this->apiPresenter->document($updated, $userDto)],
            201,
        );
    }

    public function url(Request $request, string $attachmentId): JsonResponse
    {
        $dto = UserDto::fromUserModel($request->user());
        $repairRequestQuery = RepairRequest::forUser($dto)->select('id');

        $attachment = Attachment::query()
            ->where('uuid', $attachmentId)
            ->whereIn('repair_request_id', $repairRequestQuery)
            ->firstOrFail();

        return response()->json([
            'url'       => $this->media->url($attachment),
            'expiresIn' => 300,
        ]);
    }

    private function attachAndMutate(
        RepairRequest $req,
        StoreAttachmentRequest $request,
        $file,
        string $mime,
        AttachmentPurpose $purpose,
        string $key,
    ): RepairRequest {
        $user = $request->user();
        $userDto = UserDto::fromUserModel($user);
        return $this->service->mutate(
            $user,
            (string) $req->uuid,
            (int) $request->input('revision'),
            'attachment',
            function (RepairRequest $r) use ($userDto,  $key, $file, $mime, $purpose): array {
                $this->service->flag($r, $userDto, 'canUpload');
                $this->assertPurposeAllowedForRole($userDto->role, $purpose);
                $this->assertPurposeMatchesStage($r, $purpose);

                Attachment::create([
                    'repair_request_id'         => $r->id,
                    'uploaded_by'               => $userDto->userId,
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

    private function assertPurposeMatchesStage(RepairRequest $repairRequest, AttachmentPurpose $purpose, ): void
    {
        if (!$purpose->allowsStage($repairRequest->stage)) {
            Input::fail('Назначение файла не соответствует текущему этапу');
        }
    }

    private function assertPurposeAllowedForRole(UserRole $role, AttachmentPurpose $purpose, ): void
    {
        if ($role === UserRole::Manager) {
            throw new ApiException(
                403,
                'FORBIDDEN',
                'УК не загружает файлы в заявку: документы формируются автоматически',
            );
        }

        if ($purpose === AttachmentPurpose::TransferAct && $role !== UserRole::Station) {
            throw new ApiException(403, 'FORBIDDEN', 'Подписанный акт передачи ТС загружает СТО');
        }
    }

    public function download(Request $request, string $attachmentId): StreamedResponse
    {
        $dto = UserDto::fromUserModel($request->user());
        $repqirRequestQuery = RepairRequest::forUser($dto)->select('id');

        $attachment = Attachment::query()
            ->where('uuid', $attachmentId)
            ->whereIn('repair_request_id', $repqirRequestQuery)
            ->firstOrFail();

        return $this->media->download($attachment);
    }
}
