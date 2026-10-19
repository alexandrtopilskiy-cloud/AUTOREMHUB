<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $number
 * @property string $mode
 * @property array<string, mixed> $vehicle_snapshot
 * @property array<string, mixed>|null $contact_snapshot
 * @property array<string, mixed>|null $driver_snapshot
 * @property int $mileage
 * @property string|null $fuel_level
 * @property string $condition
 * @property array<int, string> $defect_types
 * @property array<int, array<string, mixed>> $diagram_marks
 * @property CarbonImmutable $locked_at
 * @property int|null $attachment_id
 */
final class VehicleAcceptanceAct extends Entity
{
    /** @var array<int, string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vehicle_snapshot' => 'array',
            'contact_snapshot' => 'array',
            'driver_snapshot'  => 'array',
            'mileage'          => 'integer',
            'fuel_level'       => 'string',
            'defect_types'     => 'array',
            'diagram_marks'    => 'array',
            'locked_at'        => 'immutable_datetime',
        ];
    }

    public function repairRequest(): BelongsTo
    {
        return $this->belongsTo(RepairRequest::class);
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }
}
