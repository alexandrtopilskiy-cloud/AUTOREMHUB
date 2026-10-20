<?php

declare(strict_types=1);

namespace Tests\Feature\Edo;

use App\Enums\Edo\EdoDocumentStatus;
use App\Enums\Edo\EdoDocumentType;
use App\Enums\Edo\EdoProvider;
use App\Jobs\PullEdoEventsJob;
use App\Jobs\SyncEdoDocumentJob;
use App\Models\EdoDocument;
use App\Models\EdoProviderState;
use App\Models\Organization;
use App\Models\Tenant;
use App\Services\Edo\EdoManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Edo\SberKorusResponseFactory as F;
use Tests\TestCase;

final class PullEdoEventsJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_dispatches_sync_jobs_for_event_documents_and_advances_cursor(): void
    {
        Queue::fake();

        [$tenant, $org, $counterparty] = $this->seedMinimal();

        $document = EdoDocument::factory()->create([
            'tenant_id'       => $tenant->id,
            'organization_id' => $org->id,
            'counterparty_id' => $counterparty->id,
            'provider'        => EdoProvider::SberKorus->value,
            'document_type'   => EdoDocumentType::UpdSf->value,
            'status'          => EdoDocumentStatus::Sended,
            'external_id'     => '161355',
        ]);

        Http::fake([
            '*/api/auth/logon' => Http::response(F::logon(), 200),
            '*/api/events/*'   => Http::response(F::events(fromId: 100), 200),
        ]);

        $state = EdoProviderState::forProvider(EdoProvider::SberKorus);
        $state->update(['last_event_id' => 100]);

        (new PullEdoEventsJob(EdoProvider::SberKorus->value))
        ->handle(app(EdoManager::class), app(\App\Services\Edo\EdoService::class));

        Queue::assertPushed(SyncEdoDocumentJob::class, function (SyncEdoDocumentJob $job) use ($document) {
            return $job->documentId === $document->id;
        });

        $state->refresh();
        $this->assertSame(102, $state->last_event_id);
        $this->assertNotNull($state->last_pulled_at);
    }

    public function test_it_does_not_advance_cursor_when_no_events(): void
    {
        Queue::fake();

        $this->seedMinimal();

        Http::fake([
            '*/api/auth/logon' => Http::response(F::logon(), 200),
            '*/api/events/*'   => Http::response([], 200),
        ]);

        $state = EdoProviderState::forProvider(EdoProvider::SberKorus);
        $state->update(['last_event_id' => 50]);

        (new PullEdoEventsJob(EdoProvider::SberKorus->value))
        ->handle(app(EdoManager::class), app(\App\Services\Edo\EdoService::class));

        Queue::assertNotPushed(SyncEdoDocumentJob::class);

        $state->refresh();
        $this->assertSame(50, $state->last_event_id);
        $this->assertNotNull($state->last_pulled_at);
    }

    public function test_it_exits_when_provider_does_not_match_active(): void
    {
        Queue::fake();
        Http::fake(F::fakeMap());

        (new PullEdoEventsJob(EdoProvider::Diadoc->value))
        ->handle(app(EdoManager::class), app(\App\Services\Edo\EdoService::class));

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
