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

final class DiagnosticController
{
    public function __construct(
        private readonly ApiPresenter $apiPresenter,
    ) {
    }

    public function store(
        StoreDiagnosticReportRequest $request,
        string $id,
        RepairRequestService $service,
    ): JsonResponse {
        $conclusion = trim((string) $request->validated('conclusion'));

        if ($conclusion === '') {
            Input::fail('Укажите заключение диагностики');
        }

        $attachmentIds = $request->validated('attachmentIds');

        $repairRequest = $service->mutate(
            $request->user(),
            $id,
            ApiInput::revision($request),
            'diagnosis',
            function ($repairRequest) use ($service, $request, $conclusion, $attachmentIds): array {
                $service->flag($repairRequest, $request->user(), 'canDiagnose');

                $files = $service->attachmentIds(
                    $repairRequest,
                    $attachmentIds,
                    AttachmentPurpose::Diagnostic,
                );

                DiagnosticReport::create([
                    'repair_request_id' => $repairRequest->id,
                    'station_id'        => $repairRequest->assigned_station_id,
                    'author_id'         => $request->user()->id,
                    'mileage'           => $repairRequest->acceptanceAct?->mileage
                        ?? (int) ($repairRequest->data['mileage'] ?? 0),
                    'conclusion'        => $conclusion,
                    'attachment_ids'    => $attachmentIds,
                ]);

                return [
                    'comment' => 'Сохранено заключение диагностики',
                    'details' => ['conclusion' => $conclusion],
                    'after'   => function ($event) use ($files): void {
                        Attachment::query()
                            ->whereIn('id', array_map(static fn ($file) => $file->id, $files))
                            ->update(['request_event_id' => $event->id]);

                        DB::afterCommit(function () use ($repairRequest, $report): void {
                            DiagnosticReportSaved::dispatch($repairRequest, $report);
                        });
                    },
                ];
            },
        );

        return response()->json(
            ['request' => $this->apiPresenter->document($repairRequest, $request->user())],
            201,
        );
    }
}
