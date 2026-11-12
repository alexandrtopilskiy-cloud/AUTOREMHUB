<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AttachmentPurpose;
use App\Events\RepairRequests\DiagnosticReportSaved;
use App\Http\Requests\StoreDiagnosticReportRequest;
use App\Models\Attachment;
use App\Models\DiagnosticReport;
use App\Services\RepairRequestService;
use App\Support\ApiInput;
use App\Support\ApiPresenter;
use App\Support\DomainInput as Input;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\DTO\UserDto;
use App\Models\RepairRequest;

final class DiagnosticController
{
    public function __construct(
        private readonly ApiPresenter $apiPresenter,
    ) {
    }

    public function store(StoreDiagnosticReportRequest $request, string $id, RepairRequestService $service): JsonResponse
    {
        $user = $request->user();
        $userDto = UserDto::fromUserModel($user);
        $conclusion = trim((string) $request->validated('conclusion'));

        if ($conclusion === '') {
            Input::fail('Укажите заключение диагностики');
        }

        $attachmentIds = $request->validated('attachmentIds') ?? [];

        $repairRequest = $service->mutate(
            $user,
            $id,
            ApiInput::revision($request),
            'diagnosis',
            function (RepairRequest $req) use ($service, $userDto, $conclusion, $attachmentIds): array {
                $service->flag($req, $userDto, 'canDiagnose');

                $files = $service->attachmentIds(
                    $req,
                    $attachmentIds,
                    AttachmentPurpose::Diagnostic,
                );

                $report = DiagnosticReport::create([
                    'repair_request_id' => $req->id,
                    'station_id'        => $req->assigned_station_id,
                    'author_id'         => $userDto->userId,
                    'mileage'           => $req->acceptanceAct?->mileage ?? (int)($req->data['mileage'] ?? 0),
                    'conclusion'        => $conclusion,
                    'attachment_ids'    => $attachmentIds,
                ]);

                return [
                    'comment' => 'Сохранено заключение диагностики',
                    'details' => ['conclusion' => $conclusion],
                    'after'   => function ($event) use ($files, $req, $report): void {
                        Attachment::query()
                            ->whereIn('id', array_map(static fn ($file) => $file->id, $files))
                            ->update(['request_event_id' => $event->id]);

                        DB::afterCommit(function () use ($req, $report): void {
                            DiagnosticReportSaved::dispatch($req, $report);
                        });
                    },
                ];
            },
        );

        return response()->json(
            ['request' => $this->apiPresenter->document($repairRequest, $userDto)],
            201,
        );
    }
}
