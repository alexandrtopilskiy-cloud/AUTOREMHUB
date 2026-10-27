<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Concerns;

use App\Enums\UserRole;
use Illuminate\Http\UploadedFile;

trait InteractsWithApi
{
    protected function api(string $method, string $path, array $body = [], array $headers = [])
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'  => 'application/json',
        ];

        foreach ($headers as $key => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $key))] = $value;
        }

        return $this->call(
            $method,
            '/api' . $path,
            [], [], [],
            $server,
            json_encode((object) $body, JSON_THROW_ON_ERROR),
        );
    }

    protected function draft(array $overrides = []): array
    {
        $this->actingAs($this->actors['customer']);

        return $this->api('POST', '/requests')
            ->assertCreated()
            ->json('request');
    }

    protected function patchRequest(array $request, array $patch): array
    {
        $response = $this->api('PATCH', "/requests/{$request['id']}", array_merge([
            'revision' => $request['revision'],
        ], $patch));

        return $response->assertOk()->json('request');
    }

    protected function uploadMedia(array $request, string $purpose = 'evidence'): array
    {
        $file = $purpose === 'evidence'
            ? UploadedFile::fake()->createWithContent('photo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6QmcAAAAASUVORK5CYII='))
            : UploadedFile::fake()->createWithContent('invoice.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");

        return $this->call(
            'POST',
            "/api/requests/{$request['id']}/attachments",
            ['revision' => (string) $request['revision'], 'purpose' => $purpose],
            [], ['file' => $file], ['HTTP_ACCEPT' => 'application/json'],
        )->assertCreated()->json('request');
    }

    protected function next(array $request, string $action = 'next'): array
    {
        $role = auth()->user()?->role;

        $body = [
            'comment'       => $role === UserRole::Station ? 'Комментарий решения' : '',
            'attachmentIds' => [],
        ];

        if ($role === UserRole::Customer) {
            $request = $this->uploadMedia($request);
            $files = $request['attachments'];
            $body['attachmentIds'] = [end($files)['id']];
            $body['comment'] = 'Комментарий решения';
        }

        // 👇 ВАЖНО: берем актуальную ревизию из ответа после загрузки медиа
        $body['revision'] = $request['revision'];

        return $this->api('POST', "/requests/{$request['id']}/{$action}", $body)
            ->assertOk()
            ->json('request');
    }

    protected function stationStage(): array
    {
        $request = $this->patchRequest($this->draft(), [
            'vehicleId' => $this->vehicle->uuid,
            'contactId' => $this->contact->uuid,
            'reasonId'  => $this->reason->uuid,
            'mileage'   => '15000',
            'slot'      => '2026-10-20T11:00',
        ]);

        $request = $this->next($request);
        $this->actingAs($this->actors['manager']);

        $request = $this->patchRequest($request, [
            'stationId' => $this->station->uuid,
            'works'     => [[
                'name' => 'Диагностика', 'type' => 'Работа', 'workTypeId' => $this->work->uuid,
                'qty' => 1, 'price' => 100, 'normHours' => 1, 'actualHours' => 0.5,
                'normIsManual' => true, 'normOverrideReason' => 'Ручная норма',
            ]],
        ]);

        return $this->next($request);
    }
}
