<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\PartManufacturerService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

final class PartManufacturerController
{
    public function review(Request $request, string $id, PartManufacturerService $service): JsonResponse
    {
        return response()->json(['record' => $service->review($request->user(), $id, $request->all())]);
    }
}
