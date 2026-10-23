<?php

declare(strict_types=1);

namespace App\Services\Edo;

use App\Models\WorkOrder;
use App\Services\Edo\Providers\EdoRu\EdoRuClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Collection;

final class EdoActService
{
    /**
     * Отправляет Акт выполненных работ в ЭДО.Поток.
     * Пока это заглушка: логируем факт вызова и данные заказ-наряда.
     */
    public function sendAct(WorkOrder $order): void
    {
        // TODO: Здесь будет формирование XML, подпись и вызов API ЭДО.
        // Пока просто фиксируем, что триггер сработал.

        Log::info('EdoActService: готов к отправке Акта выполненных работ', [
            'work_order_id' => $order->id,
            'work_order_uuid' => $order->uuid,
            'repair_request_id' => $order->repair_request_id,
            'total' => $order->total,
            'completed_at' => $order->completed_at?->toIso8601String(),
            'station_id' => $order->station_id,
        ]);

        // Позже здесь будет:
        // 1. $xml = $this->buildXml($order);
        // 2. $signature = $this->signXml($xml); // или отправка без подписи
        // 3. $this->edoClient->sendDocument($order->repairRequest->customer_organization, $xml, $signature);
    }

    public function getActDocTypeId(EdoRuClient $client)
    {
        $response = $client->get('/dictionary/get-document-type');
        $types = new Collection($response['result'] ?? []);
        $list = $types->whereIn('id', [7, 8, 14, 15, 46]);

        return $list;
    }
}