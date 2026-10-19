<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\RequestNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NotificationController
{
    public function index(Request $request): JsonResponse
    {
        $notifications = RequestNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->with([
                'repairRequest:id,uuid,number',
                'vehicleChangeRequest:id,uuid',
            ])
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (RequestNotification $notification): array => $this->present($notification));

        return response()->json([
            'notifications' => $notifications,
        ]);
    }

    public function read(Request $request, string $id): JsonResponse
    {
        $notification = RequestNotification::query()
            ->where('uuid', $id)
            ->where('user_id', $request->user()->id)
            ->with([
                'repairRequest:id,uuid,number',
                'vehicleChangeRequest:id,uuid',
            ])
            ->firstOrFail();

        $presented = $this->present($notification);

        $notification->delete();

        return response()->json([
            'notification' => array_merge($presented, ['deleted' => true]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(RequestNotification $notification): array
    {
        return [
            'id'            => $notification->uuid,
            'kind'          => $notification->kind,
            'title'         => $notification->title,
            'message'       => $notification->message,
            'requestId'     => $notification->repairRequest?->uuid ?? '',
            'requestNumber' => $notification->repairRequest?->number ?? '',
            'targetType'    => $notification->part_manufacturer_id ? 'partManufacturer' : ($notification->vehicleChangeRequest !== null
                ? 'vehicleApproval'
                : 'request'),
            'targetId'      => $notification->partManufacturer?->uuid ?? $notification->vehicleChangeRequest?->uuid
                ?? $notification->repairRequest?->uuid
                ?? '',
            'readAt'        => $notification->read_at?->toISOString(),
            'createdAt'     => $notification->created_at->toISOString(),
        ];
    }
}
