<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ChangeRegistryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ChangeRegistryController
{
    public function __construct(
        private readonly ChangeRegistryService $service,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'records' => $this->service->records($request->user()),
        ]);
    }
}
