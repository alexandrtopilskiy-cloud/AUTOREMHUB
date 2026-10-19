<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Services\WorkCatalogImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

final class WorkCatalogImportController
{
    public function __construct(private readonly WorkCatalogImportService $service)
    {
    }

    public function preview(Request $request): JsonResponse
    {
        $file = $this->file($request);

        return response()->json([
            'result' => $this->service->preview(
                $request->user(),
                (string) $file->getRealPath(),
                $file->getClientOriginalName(),
            ),
        ]);
    }

    public function apply(Request $request): JsonResponse
    {
        $file = $this->file($request);
        $validated = $request->validate([
            'checksum' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/'],
        ]);
        $result = $this->service->apply(
            $request->user(),
            (string) $file->getRealPath(),
            $file->getClientOriginalName(),
            $validated['checksum'],
        );

        return response()->json(
            ['result' => $result],
            $result['canApply'] ? 201 : 422,
        );
    }

    private function file(Request $request): UploadedFile
    {
        $request->validate(['file' => ['required', 'file', 'max:30720']]);
        $file = $request->file('file');

        if (!$file instanceof UploadedFile
            || strtolower($file->getClientOriginalExtension()) !== 'xlsx') {
            throw new ApiException(
                422,
                'VALIDATION_ERROR',
                'Передайте XLSX размером не более 30 МБ',
            );
        }

        return $file;
    }
}
