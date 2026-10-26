<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\RequestWork;
use App\Support\TimeNormDescription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RequestWork */
final class WorkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->uuid,
            'name'        => $this->name,
            'type'        => 'Работа',
            'qty'         => (float) $this->quantity,
            'price'       => $this->price !== null ? (float) $this->price : null,
            'amount'      => $this->line_amount !== null ? (float) $this->line_amount : null,
            'lineAmount'  => $this->line_amount !== null ? (float) $this->line_amount : null,
            'hourlyRate'  => $this->hourly_rate !== null ? (float) $this->hourly_rate : null,
            'workCode'    => $this->work_code_snapshot,
            'workTypeId'  => $this->work_type_id ? $this->whenLoaded('workType', fn() => $this->workType->uuid) : '',
            'timeNormId'  => $this->time_norm_id ? $this->whenLoaded('timeNorm', fn() => $this->timeNorm->uuid) : '',
            'normId'      => $this->norm_id_snapshot,
            'normSelectionScope' => $this->norm_selection_scope,
            'normApplicabilityLabel' => $this->norm_is_manual ? 'Ручная норма для этой заявки' : $this->getNormLabel(),
            'normIsManual' => (bool) $this->norm_is_manual,
            'normOverrideReason' => $this->norm_override_reason,
            'normHours'   => $this->norm_hours !== null ? (float) $this->norm_hours : null,
            'actualHours' => $this->actual_hours !== null ? (float) $this->actual_hours : null,
        ];
    }

    private function getNormLabel(): string
    {
        // Упрощенная логика: если норма загружена, берем её описание, иначе пусто
        if ($this->relationLoaded('timeNorm') && $this->timeNorm) {
            // Здесь можно использовать логику из TimeNormDescription
            return TimeNormDescription::label($this->timeNorm) ?? '';
        }
        return '';
    }
}
