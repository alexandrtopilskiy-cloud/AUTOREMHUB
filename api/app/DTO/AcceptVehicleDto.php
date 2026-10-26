<?php

declare(strict_types=1);

namespace App\DTO;

use App\Http\Requests\AcceptVehicleRequest;

final class AcceptVehicleDto
{
    /**
     * @param array<int, string> $defectTypes
     * @param array<int, array{zone: string, type: string, comment: ?string}> $diagramMarks
     */
    public function __construct(
        public readonly string $requestKey,
        public readonly int $revision,
        public readonly string $mode,
        public readonly int $mileage,
        public readonly ?string $fuelLevel = null,
        public readonly ?string $condition = null,
        public readonly array $defectTypes = [],
        public readonly array $diagramMarks = [],
        public readonly ?string $attachmentId = null,
    ) {}

    /**
     * Фабрика для создания DTO из валидированного запроса.
     */
    public static function fromRequest(AcceptVehicleRequest $request, string $id): self
    {
        return new self(
            requestKey: $id,
            revision: (int) $request->validated('revision'),
            mode: (string) $request->validated('mode'),
            mileage: (int) $request->validated('mileage'),
            fuelLevel: $request->validated('fuelLevel'),
            condition: $request->validated('condition'),
            defectTypes: (array) $request->validated('defectTypes', []),
            diagramMarks: (array) $request->validated('diagramMarks', []),
            attachmentId: $request->validated('attachmentId'),
        );
    }
}
