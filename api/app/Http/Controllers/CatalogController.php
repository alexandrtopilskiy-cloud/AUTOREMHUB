<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTO\UserDto;
use App\Enums\CatalogKind;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Services\CatalogService;
use App\Services\CatalogVersionService;
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
        private readonly CatalogVersionService $versions,
    ) {
    }

    /**
     * ?version=<версия из прошлого ответа>: если справочники не менялись,
     * ответ {unchanged: true, version} без записей (см. CatalogVersionService).
     */
    public function index(Request $request): JsonResponse
    {
        $version = $this->versionFor($request);
        $userDto = UserDto::fromUserModel($request->user());

        if ($version !== null && $this->clientVersion($request) === $version) {
            return response()->json(['unchanged' => true, 'version' => $version]);
        }

        $records = $this->service->records($userDto);

        if ($version === null) {
            return response()->json(['records' => $records]);
        }

        return response()->json(['unchanged' => false, 'version' => $version, 'records' => $records]);
    }

    /**
     * Без ?version — прежний ответ (объект списков). С ?version — обёртка
     * {unchanged, version, data}, data только если справочники изменились.
     */
    public function reference(Request $request): JsonResponse
    {
        $version = $this->versionFor($request);
        $userDto = UserDto::fromUserModel($request->user());

        if ($version === null) {
            return response()->json($this->service->reference($userDto));
        }

        if ($this->clientVersion($request) === $version) {
            return response()->json(['unchanged' => true, 'version' => $version]);
        }

        return response()->json([
            'unchanged' => false,
            'version'   => $version,
            'data'      => $this->service->reference($userDto),
        ]);
    }

    private function versionFor(Request $request): ?string
    {
        $userDto = UserDto::fromUserModel($request->user());

        return $request->query->has('version')
            ? $this->versions->version($userDto)
            : null;
    }

    private function clientVersion(Request $request): string
    {
        $value = $request->query('version');

        return is_string($value) ? $value : '';
    }

    public function update(Request $request, string $id): JsonResponse
    {
        ApiInput::only($request, ['patch']);
        $userDto = UserDto::fromUserModel($request->user());

        $record = $this->service->update(
            $userDto,
            $id,
            ApiInput::patch($request),
        );

        return response()->json(['record' => $record]);
    }

    public function store(Request $request, string $kind): JsonResponse
    {
        ApiInput::only($request, ['record']);
        $user = $request->user();
        $userDto = UserDto::fromUserModel($user);

        $payload = $request->json('record');

        if (!is_array($payload)) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'Передайте объект record');
        }

        $catalogKind = CatalogKind::tryFrom($kind);

        if ($catalogKind === null) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'Неизвестный справочник');
        }

        $record = $this->service->create($userDto, $catalogKind, $payload);

        return response()->json(['record' => $record], 201);
    }

    public function history(Request $request): JsonResponse
    {
        $userDto = UserDto::fromUserModel($request->user());

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
                $userDto,
                $kind,
                $validated['recordId'] ?? null,
            ),
        ]);
    }

    public function importTimeNorms(Request $request): JsonResponse
    {
        $userDto = UserDto::fromUserModel($request->user());

        if ($userDto->role !== UserRole::Manager) {
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
            $userDto->tenantId,
            (string) $file->getRealPath(),
            $userDto,
        );

        return response()->json(['result' => $result]);
    }
}
