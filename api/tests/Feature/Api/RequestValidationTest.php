<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Attachment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Api\Concerns\CreatesTestData;
use Tests\Feature\Api\Concerns\InteractsWithApi;
use Tests\TestCase;

final class RequestValidationTest extends TestCase
{
    use DatabaseTransactions, CreatesTestData, InteractsWithApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-20 09:00:00'));
        Storage::fake('media');
        $this->setUpTestData();
    }

    public static function invalidVisitTimesProvider(): array
    {
        return [
            'too early' => ['07:30', 'Время визита должно быть с 08:00 до 22:00'],
            'too late'  => ['22:30', 'Время визита должно быть с 08:00 до 22:00'],
        ];
    }

    #[DataProvider('invalidVisitTimesProvider')]
    public function test_visit_time_must_be_inside_service_window(string $time, string $expectedMessage): void
    {
        $date = CarbonImmutable::now()->addDays(2)->format('Y-m-d');
        $request = $this->draft();

        $response = $this->api('PATCH', "/requests/{$request['id']}", [
            'revision' => $request['revision'],
            'slot'     => "{$date}T{$time}",
        ]);

           $response
                ->assertUnprocessable()
                ->assertJsonPath('error.fields.slot', $expectedMessage);
    }

    public function test_visit_time_must_be_at_least_30_mins_in_future(): void
    {
        $request = $this->draft();
        $tooEarly = CarbonImmutable::now()->addMinutes(29);

        $this->api('PATCH', "/requests/{$request['id']}", [
            'revision'  => $request['revision'],
            'slot'      => $tooEarly->format('Y-m-d\TH:i'),
        ])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_customer_cannot_modify_works_or_stage(): void
    {
        $request = $this->draft();
        $new = $this->patchRequest($request, ['description' => 'Версия 1']);

        $this->api('PATCH', "/requests/{$request['id']}", [
            'revision' => $new['revision'],
            'works'    => [],
        ])->assertForbidden();

        $this->api('PATCH', "/requests/{$request['id']}", [
            'revision' => $new['revision'],
            'stage'    => 13,
        ])->assertUnprocessable();
    }

    public function test_wrong_revision_upload_is_removed_from_storage(): void
    {
        $request = $this->draft();
        $request = $this->patchRequest($request, ['description' => 'new']);

        $file = UploadedFile::fake()->createWithContent('photo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6QmcAAAAASUVORK5CYII='));

        $this->call('POST', "/api/requests/{$request['id']}/attachments", [
            'revision' => '0', 'purpose' => 'evidence',
        ], [], ['file' => $file], ['HTTP_ACCEPT' => 'application/json'])->assertConflict();

        $this->assertSame(0, Attachment::count());
        $this->assertEmpty(Storage::disk('media')->allFiles());
    }
}
