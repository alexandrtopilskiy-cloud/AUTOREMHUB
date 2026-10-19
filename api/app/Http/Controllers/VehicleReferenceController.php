<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Services\VehicleReferenceService;
use App\Support\ApiInput;
use App\Support\DomainInput as Input;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class VehicleReferenceController
{
    public function __construct(
        private readonly VehicleReferenceService $service,
    ) {
    }

    public function selection(Request $request): JsonResponse
    {
        $data = Input::validate($request->query(), [
            'makeId' => ['required', 'uuid'],
            'step' => ['required', 'string'],
            'filters' => ['sometimes', 'array'],
            'filters.*' => ['string', 'max:500'],
        ]);
        return response()->json($this->service->selectionOptions(
            $request->user(),
            $data['makeId'],
            $data['step'],
            $data['filters'] ?? [],
        ));
    }

    public function makes(Request $request): JsonResponse
    {
        $validated = Input::validate($request->query(), [
            'search' => ['sometimes', 'string', 'max:255'],
            'limit'  => ['sometimes', 'integer', 'between:1,100'],
        ]);

        return response()->json([
            'makes' => $this->service->makes(
                $request->user(),
                (string) ($validated['search'] ?? ''),
                (int) ($validated['limit'] ?? 50),
            ),
        ]);
    }

    public function models(Request $request): JsonResponse
    {
        $validated = Input::validate($request->query(), [
            'uniqueNames' => ['sometimes', 'boolean'],
            'makeId' => ['required', 'uuid'],
            'search' => ['sometimes', 'string', 'max:255'],
            'limit'  => ['sometimes', 'integer', 'between:1,200'],
        ]);

        return response()->json([
            'models' => $this->service->models(
                $request->user(),
                (string) $validated['makeId'],
                (string) ($validated['search'] ?? ''),
                (int) ($validated['limit'] ?? 100),
                (bool) ($validated['uniqueNames'] ?? false),
            ),
        ]);
    }

    public function generations(Request $request): JsonResponse
    {
        $validated = Input::validate($request->query(), [
            'modelId' => ['required', 'uuid'],
            'search'  => ['sometimes', 'string', 'max:255'],
            'limit'   => ['sometimes', 'integer', 'between:1,200'],
        ]);

        return response()->json([
            'generations' => $this->service->generations(
                $request->user(),
                (string) $validated['modelId'],
                (string) ($validated['search'] ?? ''),
                (int) ($validated['limit'] ?? 100),
            ),
        ]);
    }

    public function engines(Request $request): JsonResponse
    {
        $validated = Input::validate($request->query(), [
            'modelId'      => ['required', 'uuid'],
            'generationId' => ['sometimes', 'nullable', 'uuid'],
            'search'       => ['sometimes', 'string', 'max:255'],
            'limit'        => ['sometimes', 'integer', 'between:1,200'],
        ]);

        return response()->json([
            'engines' => $this->service->engines(
                $request->user(),
                (string) $validated['modelId'],
                isset($validated['generationId'])
                    ? (string) $validated['generationId']
                    : null,
                (string) ($validated['search'] ?? ''),
                (int) ($validated['limit'] ?? 100),
            ),
        ]);
    }

    public function transmissions(Request $request): JsonResponse
    {
        $validated = Input::validate($request->query(), [
            'search' => ['sometimes', 'string', 'max:255'],
            'limit'  => ['sometimes', 'integer', 'between:1,100'],
        ]);

        return response()->json([
            'transmissions' => $this->service->transmissions(
                $request->user(),
                (string) ($validated['search'] ?? ''),
                (int) ($validated['limit'] ?? 50),
            ),
        ]);
    }

    public function drives(Request $request): JsonResponse
    {
        $validated = Input::validate($request->query(), [
            'search' => ['sometimes', 'string', 'max:255'],
            'limit'  => ['sometimes', 'integer', 'between:1,100'],
        ]);

        return response()->json([
            'drives' => $this->service->drives(
                $request->user(),
                (string) ($validated['search'] ?? ''),
                (int) ($validated['limit'] ?? 50),
            ),
        ]);
    }

    public function catalog(Request $request): JsonResponse
    {
        $validated = Input::validate($request->query(), [
            'page'          => ['sometimes', 'integer', 'min:1'],
            'perPage'       => ['sometimes', 'integer', 'between:25,200'],
            'make'          => ['sometimes', 'string', 'max:255'],
            'model'         => ['sometimes', 'string', 'max:255'],
            'modelYearFrom' => ['sometimes', 'string', 'max:20'],
            'modelYearTo'   => ['sometimes', 'string', 'max:20'],
            'engineIndex'   => ['sometimes', 'string', 'max:100'],
            'bodyCode'      => ['sometimes', 'string', 'max:150'],
            'horsepower'    => ['sometimes', 'string', 'max:20'],
            'engineVolumeLiters' => ['sometimes', 'string', 'max:20'],
            'transmission'  => ['sometimes', 'string', 'max:100'],
            'driveType'     => ['sometimes', 'string', 'max:100'],
            'engineType'    => ['sometimes', 'string', 'max:100'],
        ]);

        $filters = array_intersect_key($validated, array_flip([
            'make',
            'model',
            'modelYearFrom',
            'modelYearTo',
            'engineIndex',
            'bodyCode',
            'horsepower',
            'engineVolumeLiters',
            'transmission',
            'driveType',
            'engineType',
        ]));

        return response()->json($this->service->catalog(
            $request->user(),
            $filters,
            (int) ($validated['page'] ?? 1),
            (int) ($validated['perPage'] ?? 100),
        ));
    }

    public function storeCatalog(Request $request): JsonResponse
    {
        if ($request->user()->role !== \App\Enums\UserRole::Manager) {
            throw new ApiException(403, 'FORBIDDEN', 'Добавлять записи справочника может только УК');
        }
        ApiInput::only($request, ['record']);
        $record = $request->json('record');
        if (!is_array($record)) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'Передайте объект record');
        }
        return response()->json(['model' => $this->service->create($request->user(), $record, true)], 201);
    }

    public function store(Request $request): JsonResponse
    {
        ApiInput::only($request, ['record']);
        $record = $request->json('record');

        if (!is_array($record)) {
            throw new ApiException(
                422,
                'VALIDATION_ERROR',
                'Передайте объект record',
            );
        }

        return response()->json([
            'model' => $this->service->create($request->user(), $record),
        ], 201);
    }
}
