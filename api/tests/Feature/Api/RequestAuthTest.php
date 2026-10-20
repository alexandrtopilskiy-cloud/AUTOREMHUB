<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\Concerns\CreatesTestData;
use Tests\Feature\Api\Concerns\InteractsWithApi;
use Tests\TestCase;
use App\Models\User;

final class RequestAuthTest extends TestCase
{
    use DatabaseTransactions, CreatesTestData, InteractsWithApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-09-20 09:00:00'));
        Storage::fake('media');
        $this->setUpTestData();
    }

    public function test_login_requires_trusted_origin_and_returns_uuid(): void
    {
        $user = $this->actors['customer'];
        $body = ['login' => $user->login, 'password' => 'test-password-long'];

        $this->api('POST', '/login', $body)->assertForbidden();

        $this->api('POST', '/login', $body, ['Origin' => 'http://localhost:5173'])
            ->assertOk()
            ->assertJsonPath('user.id', $user->uuid);
    }

    public function test_bearer_token_authenticates_mutations(): void
    {
        $login = $this->api('POST', '/login', [
            'login' => $this->actors['customer']->login,
            'password' => 'test-password-long',
        ], ['Origin' => 'http://localhost:5173'])->assertOk();

        Auth::forgetGuards();
        $this->api('POST', '/requests')->assertUnauthorized();

        $this->api('POST', '/requests', [], [
            'Authorization' => 'Bearer ' . $login->json('token'),
        ])->assertCreated();
    }
}
