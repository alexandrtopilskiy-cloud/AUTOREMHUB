<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\DeveloperToolsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Меню «Разработчик» в ЛК УК. */
final class DeveloperController
{
    public function __construct(private readonly DeveloperToolsService $tools) {}

    public function requests(Request $request): JsonResponse
    {
        return response()->json([
            'requests' => $this->tools->listRequests($request->user()),
            'maxDelete' => DeveloperToolsService::MAX_DELETE,
        ]);
    }

    public function deleteRequests(Request $request): JsonResponse
    {
        return response()->json($this->tools->deleteRequests($request->user(), $request->input('ids')));
    }

    public function stockDocuments(Request $request): JsonResponse
    {
        return response()->json([
            'documents' => $this->tools->listStockDocuments($request->user()),
            'maxDelete' => DeveloperToolsService::MAX_DELETE,
        ]);
    }

    public function deleteStockDocuments(Request $request): JsonResponse
    {
        return response()->json($this->tools->deleteStockDocuments($request->user(), $request->input('ids')));
    }

    public function notifications(Request $request): JsonResponse
    {
        return response()->json($this->tools->notificationStats($request->user()));
    }

    public function readAllNotifications(Request $request): JsonResponse
    {
        return response()->json($this->tools->readAllNotifications($request->user()));
    }
}
