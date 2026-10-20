<?php

declare(strict_types=1);

namespace Tests\Feature\Edo;

use App\Enums\Edo\EdoProvider;
use App\Jobs\PullEdoEventsJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class EdoPullEventsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_dispatches_for_explicit_provider(): void
    {
        Queue::fake();

        $this->artisan('edo:pull-events --provider=sberkorus')->assertSuccessful();

        Queue::assertPushed(
            PullEdoEventsJob::class,
            fn (PullEdoEventsJob $job) => $job->providerValue === EdoProvider::SberKorus->value,
        );
    }

    public function test_it_rejects_unknown_provider(): void
    {
        Queue::fake();

        $this->artisan('edo:pull-events --provider=unknown')
            ->assertFailed();

        Queue::assertNothingPushed();
    }

    public function test_it_does_not_dispatch_when_provider_is_none(): void
    {
        Queue::fake();

        config(['edo.default' => EdoProvider::None->value]);

        $this->artisan('edo:pull-events')->assertSuccessful();

        Queue::assertNothingPushed();
    }
}
