<?php

declare(strict_types=1);

namespace App\DTO;

use App\Http\Requests\UpdateRepairRequestRequest;

final class UpdateRepairRequestDto
{
    /**
     * @param array<string, mixed> $rawPatch Оригинальный массив (для тех полей, которые мы пока не вынесли)
     */
    public function __construct(
        public readonly string $requestKey,
        public readonly int $revision,
        public readonly array $rawPatch,

        // Опциональные поля (null, если их нет в запросе)
        public readonly ?string $vehicleId = null,
        public readonly ?string $contactId = null,
        public readonly ?string $driverId = null,
        public readonly ?string $stationId = null,
        public readonly ?string $reasonId = null,
        public readonly ?string $reasonSubtypeId = null,
        public readonly ?string $laborRateType = null,
        public readonly ?array $works = null,
        public readonly ?array $parts = null,
    ) {}

    public static function fromRequest(UpdateRepairRequestRequest $request, string $id): self
    {
        $validated = $request->validated();

        return new self(
            requestKey: $id,
            revision: (int) $validated['revision'],
            rawPatch: $request->safe()->except(['revision']),

            // Заполняем только те поля, которые реально пришли
            vehicleId: $validated['vehicleId'] ?? null,
            contactId: $validated['contactId'] ?? null,
            driverId: $validated['driverId'] ?? null,
            stationId: $validated['stationId'] ?? null,
            reasonId: $validated['reasonId'] ?? null,
            reasonSubtypeId: $validated['reasonSubtypeId'] ?? null,
            laborRateType: $validated['laborRateType'] ?? null,
            works: $validated['works'] ?? null,
            parts: $validated['parts'] ?? null,
        );
    }
}
