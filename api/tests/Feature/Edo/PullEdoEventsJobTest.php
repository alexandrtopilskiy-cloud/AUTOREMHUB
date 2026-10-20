<?php

declare(strict_types=1);

namespace Tests\Feature\Edo;

use App\Enums\Edo\EdoProvider;
use App\Jobs\PullEdoEventsJob;
use App\Jobs\SyncEdoDocumentJob;
use App\Models\Organization;
use App\Models\Tenant;
use App\Services\Edo\EdoManager;
use App\Services\Edo\EdoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class PullEdoEventsJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_exits_when_provider_does_not_match_active(): void
    {
        Queue::fake();
        Http::fake(F::fakeMap());

        $edoEventJob = new PullEdoEventsJob(EdoProvider::Diadoc->value);
        $edoEventJob->handle(app(EdoManager::class), app(EdoService::class));

        Queue::assertNotPushed(SyncEdoDocumentJob::class);
        Http::assertNothingSent();
    }

    /**
     * @return array{0: Tenant, 1: Organization, 2: Organization}
     */
    private function seedMinimal(): array
    {
        $tenant = Tenant::factory()->create();

        $org = Organization::factory()->create([
            'tenant_id'         => $tenant->id,
            'kind'              => 'manager',
            'edo_provider'      => EdoProvider::SberKorus->value,
            'edo_client_id'     => 'client-1',
            'edo_edi_code'      => '2BM-1',
            'edo_registered_at' => now(),
        ]);

        $counterparty = Organization::factory()->create([
            'tenant_id'         => $tenant->id,
            'kind'              => 'customer',
            'edo_provider'      => EdoProvider::SberKorus->value,
            'edo_client_id'     => 'client-2',
            'edo_edi_code'      => '2BM-2',
            'edo_registered_at' => now(),
        ]);

        return [$tenant, $org, $counterparty];
    }
}
