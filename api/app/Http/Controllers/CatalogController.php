<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CatalogKind;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Services\CatalogService;
use App\Services\TimeNormImporter;
use App\Support\ApiInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

final class CatalogController
{
    public function __construct(
        private readonly CatalogService $service,
        private readonly TimeNormImporter $timeNormImporter,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $records = $this->service->records($request->user());

        return response()->json(['records' => $records]);
    }

    public function reference(Request $request): JsonResponse
    {
        $data = $this->service->reference($request->user());

        return response()->json($data);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        ApiInput::only($request, ['patch']);

        $record = $this->service->update(
            $request->user(),
            $id,
            ApiInput::patch($request),
        );

        return response()->json(['record' => $record]);
    }

    public function store(Request $request, string $kind): JsonResponse
    {
        ApiInput::only($request, ['record']);

        $payload = $request->json('record');

        if (!is_array($payload)) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'Передайте объект record');
        }

        $catalogKind = CatalogKind::tryFrom($kind);

        if ($catalogKind === null) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'Неизвестный справочник');
        }

        $record = $this->service->create($request->user(), $catalogKind, $payload);

        return response()->json(['record' => $record], 201);
    }

    public function history(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'kind'     => ['sometimes', 'string', 'max:80'],
            'recordId' => ['sometimes', 'uuid'],
        ]);

        $kind = $validated['kind'] ?? null;
        $kind = is_string($kind) && $kind !== ''
            ? (CatalogKind::tryFrom($kind) ?? $kind)
            : null;

        return response()->json([
            'events' => $this->service->history(
                $request->user(),
                $kind,
                $validated['recordId'] ?? null,
            ),
        ]);
    }

    public function importTimeNorms(Request $request): JsonResponse
    {
        if ($request->user()->role !== UserRole::Manager) {
            throw new ApiException(
                403,
                'FORBIDDEN',
                'Импорт норм времени доступен только '
                . 'Управляющей компании',
            );
        }

        $request->validate([
            'file' => ['required', 'file', 'max:20480'],
        ]);

        $file = $request->file('file');

        if (
            !($file instanceof UploadedFile)
            || strtolower($file->getClientOriginalExtension()) !== 'xlsx'
        ) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'Передайте файл XLSX');
        }

        $result = $this->timeNormImporter->import(
            $request->user()->tenantId(),
            (string) $file->getRealPath(),
            $request->user(),
        );

        return response()->json(['result' => $result]);
    }
}
