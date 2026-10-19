<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $user_id
 * @property int|null $repair_request_id
 * @property int|null $vehicle_change_request_id
 * @property int|null $request_event_id
 * @property string $kind
 * @property string $title
 * @property string $message
 * @property CarbonImmutable|null $read_at
 *
 * @property-read RepairRequest|null $repairRequest
 * @property-read VehicleChangeRequest|null $vehicleChangeRequest
 */
final class RequestNotification extends Entity
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'read_at' => 'immutable_datetime',
        ];
    }

    public function partManufacturer(): BelongsTo
    {
        return $this->belongsTo(PartManufacturer::class);
    }

    public function repairRequest(): BelongsTo
    {
        return $this->belongsTo(RepairRequest::class);
    }

    public function vehicleChangeRequest(): BelongsTo
    {
        return $this->belongsTo(VehicleChangeRequest::class);
    }
}
