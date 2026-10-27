<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DiagnosticReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DiagnosticReport */
final class DiagnosticReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->uuid,
            'conclusion'    => $this->conclusion,
            'mileage'       => $this->mileage,
            'attachmentIds' => $this->attachment_ids,
            'createdAt'     => $this->created_at->toISOString(),
        ];
    }
}
