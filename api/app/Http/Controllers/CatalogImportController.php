<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Services\CatalogExcelImportService;
use App\Services\CatalogImportSchema;
use App\Services\XlsxTemplateWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class CatalogImportController
{
    public function __construct(
        private readonly CatalogExcelImportService $importer,
        private readonly XlsxTemplateWriter $writer,
    ) {
    }

    public function template(Request $request, string $kind): BinaryFileResponse
    {
        $this->assertManager($request);
        $path = $this->writer->write(
            array_values(CatalogImportSchema::headers($kind)),
            CatalogImportSchema::name($kind),
        );

        return response()
            ->download(
                $path,
                CatalogImportSchema::filename($kind),
                [
                    'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'Cache-Control' => 'no-store, private',
                ],
            )
            ->deleteFileAfterSend(true);
    }

    public function store(Request $request, string $kind): JsonResponse
    {
        $this->assertManager($request);
        CatalogImportSchema::headers($kind);
        $request->validate([
            'file' => ['required', 'file', 'max:30720'],
        ]);
        $file = $request->file('file');

        if (
            !($file instanceof UploadedFile)
            || strtolower($file->getClientOriginalExtension()) !== 'xlsx'
        ) {
            throw new ApiException(
                422,
                'VALIDATION_ERROR',
                'Передайте файл XLSX размером не более 30 МБ',
            );
        }

        $result = $this->importer->import(
            $request->user(),
            $kind,
            (string) $file->getRealPath(),
        );
        $body = ['result' => $result];

        if (!$result['success']) {
            $body['error'] = [
                'code'    => 'IMPORT_CONFLICTS',
                'message' => 'В XLSX найдены конфликты. Данные не импортированы',
            ];
        }

        return response()->json(
            $body,
            $result['success'] ? 201 : 422,
        );
    }

    private function assertManager(Request $request): void
    {
        if ($request->user()?->role !== UserRole::Manager) {
            throw new ApiException(
                403,
                'FORBIDDEN',
                'Импорт справочников доступен только Управляющей компании',
            );
        }
    }
}
