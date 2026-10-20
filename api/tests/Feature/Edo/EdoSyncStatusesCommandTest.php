<?php

declare(strict_types=1);

namespace Tests\Feature\Edo;

use App\Enums\Edo\EdoDocumentStatus;
use App\Enums\Edo\EdoDocumentType;
use App\Enums\Edo\EdoProvider;
use App\Jobs\SyncEdoDocumentJob;
use App\Models\EdoDocument;
use App\Models\Organization;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class EdoSyncStatusesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_dispatches_jobs_for_stale_documents_only(): void
    {
        Queue::fake();

        [$tenant, $org, $counterparty] = $this->seedMinimal();

        // stale: pending + last_synced_at старый
        $stale = EdoDocument::factory()->create([
            'tenant_id'       => $tenant->id,
            'organization_id' => $org->id,
            'counterparty_id' => $counterparty->id,
            'provider'        => EdoProvider::SberKorus->value,
            'document_type'   => EdoDocumentType::UpdSf->value,
            'status'          => EdoDocumentStatus::Sended,
            'external_id'     => '111',
            'last_synced_at'  => now()->subHour(),
        ]);

        // finished — не должна попасть, даже если last_synced_at старый
        $finished = EdoDocument::factory()->create([
            'tenant_id'       => $tenant->id,
            'organization_id' => $org->id,
            'counterparty_id' => $counterparty->id,
            'provider'        => EdoProvider::SberKorus->value,
            'document_type'   => EdoDocumentType::UpdSf->value,
            'status'          => EdoDocumentStatus::Accepted,
            'external_id'     => '222',
            'last_synced_at'  => now()->subHour(),
        ]);

        // недавно синканный — тоже мимо
        $fresh = EdoDocument::factory()->create([
            'tenant_id'       => $tenant->id,
            'organization_id' => $org->id,
            'counterparty_id' => $counterparty->id,
            'provider'        => EdoProvider::SberKorus->value,
            'document_type'   => EdoDocumentType::UpdSf->value,
            'status'          => EdoDocumentStatus::Sended,
            'external_id'     => '333',
            'last_synced_at'  => now()->subMinute(),
        ]);

        $this->artisan('edo:sync-status')->assertSuccessful();

        Queue::assertPushed(SyncEdoDocumentJob::class, 1);
        Queue::assertPushed(SyncEdoDocumentJob::class, fn ($job) => $job->documentId === $stale->id);
        Queue::assertNotPushed(SyncEdoDocumentJob::class, fn ($job) => $job->documentId === $finished->id);
        Queue::assertNotPushed(SyncEdoDocumentJob::class, fn ($job) => $job->documentId === $fresh->id);
    }

    /**
     * @return array{0: Tenant, 1: Organization, 2: Organization}
     */
    private function seedMinimal(): array
    {
        $tenant = Tenant::factory()->create();

        $org = Organization::factory()->create([
            'tenant_id' => $tenant->id, 'kind' => 'manager',
            'edo_provider' => EdoProvider::SberKorus->value,
            'edo_client_id' => 'c1', 'edo_edi_code' => '2BM-1', 'edo_registered_at' => now(),
        ]);

        $counterparty = Organization::factory()->create([
            'tenant_id' => $tenant->id, 'kind' => 'customer',
            'edo_provider' => EdoProvider::SberKorus->value,
            'edo_client_id' => 'c2', 'edo_edi_code' => '2BM-2', 'edo_registered_at' => now(),
        ]);

        return [$tenant, $org, $counterparty];
    }
}
