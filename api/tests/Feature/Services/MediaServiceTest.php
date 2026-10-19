<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Exceptions\ApiException;
use App\Models\Attachment;
use App\Models\RepairRequest;
use App\Models\RequestStatusPeriod;
use App\Models\Tenant;
use App\Models\User;
use App\Services\MediaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature-тест для MediaService.
 *
 * Покрывает:
 *   - assertConfigured: в testing окружении ничего не делает
 *   - url: private disk → route; public disk → Storage::url
 *   - download: файл есть → StreamedResponse; файла нет → 404
 */
final class MediaServiceTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;
    private User $user;
    private RepairRequest $request;
    private RequestStatusPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media');
        Storage::fake('public');

        $this->tenant = Tenant::query()->create(['name' => 'Тестовая УК']);

        $org = \App\Models\Organization::query()->create([
            'tenant_id' => $this->tenant->id,
            'kind'      => 'customer',
            'name'      => 'ООО «Заказчик»',
        ]);

        $this->user = User::query()->create([
            'organization_id' => $org->id,
            'login'           => 'customer-' . Str::random(10),
            'display_name'    => 'Заказчик',
            'role'            => 'customer',
            'password'        => 'test-password-long',
            'active'          => true,
        ]);

        $uuid = (string) Str::uuid();
        $this->request = RepairRequest::query()->create([
            'uuid'                     => $uuid,
            'public_id'                => $uuid,
            'tenant_id'                => $this->tenant->id,
            'customer_organization_id' => $org->id,
            'data'                     => array_fill_keys(\App\Services\Workflow::TEXT_FIELDS, ''),
            'works'                    => [],
            'history'                  => [],
            'stage'                    => 0,
            'revision'                 => 1,
            'created_by'               => $this->user->id,
        ]);

        $this->period = RequestStatusPeriod::query()->create([
            'uuid'              => (string) Str::uuid(),
            'repair_request_id' => $this->request->id,
            'stage'             => 0,
            'entered_at'        => now()->subHour(),
            'actor_id'          => $this->user->id,
        ]);
    }

    /* ─────────────────── assertConfigured ─────────────────── */

    public function test_assert_configured_passes_in_testing(): void
    {
        // В testing окружении метод просто возвращает — никаких исключений.
        $this->service()->assertConfigured();

        $this->addToAssertionCount(1);
    }

    /* ─────────────────── url: private ─────────────────── */

    public function test_url_returns_route_for_private_disk(): void
    {
        $attachment = $this->makeAttachment('media', 'private');

        $url = $this->service()->url($attachment);

        $this->assertSame(
            route('attachments.download', ['attachmentId' => $attachment->uuid]),
            $url,
        );
    }

    /* ─────────────────── url: public ─────────────────── */

    public function test_url_returns_storage_url_for_public_disk(): void
    {
        $attachment = $this->makeAttachment('public', 'public');

        $url = $this->service()->url($attachment);

        // Для local-диска url() вернёт APP_URL/storage/...
        $this->assertStringContainsString('storage/', $url);
        $this->assertStringContainsString($attachment->object_key, $url);
    }

    /* ─────────────────── download ─────────────────── */

    public function test_download_returns_streamed_response(): void
    {
        $attachment = $this->makeAttachment('media', 'private');
        Storage::disk('media')->put($attachment->object_key, 'file content');

        $response = $this->service()->download($attachment);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            'image/png',
            $response->headers->get('Content-Type'),
        );
    }

    public function test_download_throws_404_when_file_missing(): void
    {
        $attachment = $this->makeAttachment('media', 'private');

        // Файл не записан в Storage::fake('media') → exists=false
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Файл не найден');

        $this->service()->download($attachment);
    }

    public function test_download_404_has_correct_code(): void
    {
        $attachment = $this->makeAttachment('media', 'private');

        try {
            $this->service()->download($attachment);
            $this->fail('Ожидалось ApiException');
        } catch (ApiException $e) {
            $this->assertSame(404, $e->status);
            $this->assertSame('NOT_FOUND', $e->errorCode);
        }
    }

    /* ─────────────────── helpers ─────────────────── */

    private function service(): MediaService
    {
        return app(MediaService::class);
    }

    private function makeAttachment(string $disk): Attachment
    {
        return Attachment::query()->create([
            'uuid'                     => (string) Str::uuid(),
            'repair_request_id'        => $this->request->id,
            'uploaded_by'              => $this->user->id,
            'request_status_period_id' => $this->period->id,
            'purpose'                  => 'evidence',
            'disk'                     => $disk,
            'object_key'               => 'test/' . Str::uuid() . '.png',
            'original_name'            => 'photo.png',
            'mime_type'                => 'image/png',
            'size'                     => 100,
            'sha256'                   => str_repeat('a', 64),
        ]);
    }
}
