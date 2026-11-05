<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTO\UserDto;
use App\Http\Requests\IndexVehicleRequest;
use App\Http\Requests\LookupVehicleRequest;
use App\Models\RepairRequest;
use App\Models\Vehicle;
use App\Services\ReferenceSearch;
use App\Services\VehicleService;
use App\Support\DomainInput as Input;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class VehicleController
{
    public function __construct(
        private readonly VehicleService $service,
    ) {}

    public function index(IndexVehicleRequest $request): JsonResponse
    {
        $user = $request->user();
        $dto = UserDto::fromUserModel($user);
        $term = trim((string) $request->validated('q', ''));
        $excludeRequestId = null;

        if ($request->filled('excludeRequest')) {
            $key = (string) $request->validated('excludeRequest');

            $excludeRequestId = RepairRequest::resolveFor($dto, $key)->id;
        }

        $query = Vehicle::forUser($dto)->with('organization');

        if ($term !== '') {
            $this->applySearch($query, $term);
        }

        $vehicles = $query
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->map(
                fn (Vehicle $vehicle) => $this->service->present(
                    $vehicle,
                    $dto,
                    $excludeRequestId,
                ),
            );

        return response()->json(['vehicles' => $vehicles]);
    }

    public function lookup(LookupVehicleRequest $request): JsonResponse
    {
        $user = $request->user();
        $vin = $request->validated('vin');
        $plate = $request->validated('plate');
        $dto = UserDto::fromUserModel($user);

        $query = Vehicle::forUser(dto: $dto);

        if (is_string($vin) && $vin !== '') {
            $query->where('vin', strtoupper(trim($vin)));
        } else {
            $query->where('plate_normalized', Vehicle::plateKey((string) $plate));
        }

        $matches = $query->limit(2)->get();

        if ($matches->count() > 1) {
            Input::fail('Госномер неоднозначен. Уточните VIN');
        }

        $vehicle = $matches->first();

        if (!$vehicle) {
            abort(404);
        }

        return response()->json([
            'vehicle' => $this->service->present($vehicle, $dto),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        if (!Str::isUuid($id)) {
            abort(404);
        }

        $user = $request->user();
        $dto = UserDto::fromUserModel($user);

        $vehicle = Vehicle::forUser($dto)
            ->where('uuid', $id)
            ->firstOrFail();

        return response()->json([
            'vehicle' => $this->service->present($vehicle, $dto),
        ]);
    }

    public function statistics(Request $request, string $id): JsonResponse
    {
        if (!Str::isUuid($id)) {
            abort(404);
        }

        $user = $request->user();
        $dto = UserDto::fromUserModel($user);

        $vehicle = Vehicle::forUser($dto)
            ->where('uuid', $id)
            ->firstOrFail();

        return response()->json(
            $this->service->statistics($vehicle, $dto)
        );
    }

    public function contacts(Request $request, string $id): JsonResponse
    {
        if (!Str::isUuid($id)) {
            abort(404);
        }

        $dto = UserDto::fromUserModel($request->user());

        $vehicle = Vehicle::forUser($dto)
            ->where('uuid', $id)
            ->firstOrFail();

        return response()->json([
            'contacts' => $this->service->contacts($vehicle),
        ]);
    }

    public function drivers(Request $request, string $id): JsonResponse
    {
        if (!Str::isUuid($id)) {
            abort(404);
        }

        $dto = UserDto::fromUserModel($request->user());

        $vehicle = Vehicle::forUser($dto)
            ->where('uuid', $id)
            ->firstOrFail();

        return response()->json([
            'drivers' => $this->service->drivers($vehicle),
        ]);
    }

    /**
     * Применяет поиск по VIN, госномеру и названию.
     */
    private function applySearch(Builder $query, string $term): void
    {
        foreach (preg_split('/\s+/u', trim($term), -1, PREG_SPLIT_NO_EMPTY) as $fragment) {
            $query->where(function (Builder $inner) use ($fragment): void {
                ReferenceSearch::apply($inner, $fragment, ['vin', 'plate', 'name']);
                $plateKey = Vehicle::plateKey($fragment);
                if ($plateKey !== '') {
                    $inner->orWhereRaw('STRPOS(plate_normalized, ?) > 0', [$plateKey]);
                }
            });
        }
    }
}
