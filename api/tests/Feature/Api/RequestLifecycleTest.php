<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\ApprovalDecision;
use App\Enums\RepairStage;
use App\Models\RequestApproval;
use App\Models\RequestStatusPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\Concerns\CreatesTestData;
use Tests\Feature\Api\Concerns\InteractsWithApi;
use Tests\TestCase;

final class RequestLifecycleTest extends TestCase
{
    use DatabaseTransactions, CreatesTestData, InteractsWithApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-20 09:00:00'));
        Storage::fake('media');
        $this->setUpTestData();
    }

    public function test_new_requests_are_empty_and_numbering_is_sequential(): void
    {
        $first = $this->draft();
        $second = $this->draft();

        $this->assertSame('RP-' . str_pad((string) $first['systemId'], 6, '0', STR_PAD_LEFT), $first['number']);
        $this->assertSame($first['systemId'] + 1, $second['systemId']);
        $this->assertNull($first['previousServiceMileage']);
    }

    public function test_customer_identity_and_session(): void
    {
        $request = $this->draft();
        $this->api('GET', '/session')->assertJsonPath('user.organization', 'ООО "Тест"');
        $this->assertSame('', $request['company']);
    }

    public function test_initial_transition_requires_form_fields(): void
    {
        $request = $this->draft();

        $this->api('POST', "/requests/{$request['id']}/next", [
            'revision' => 0, 'comment' => '', 'attachmentIds' => [],
        ])->assertUnprocessable();

        $request = $this->patchRequest($request, [
            'vehicleId' => $this->vehicle->uuid,
            'contactId' => $this->contact->uuid,
            'reasonId'  => $this->reason->uuid,
            'mileage'   => '15000',
        ]);

        $request = $this->next($request);

        $this->assertSame(RepairStage::ManagerRouting->value, $request['stage']);
        $this->assertSame(1, RequestApproval::count());
        $this->assertSame(2, RequestStatusPeriod::count());
    }

    public function test_station_decline_keeps_request_visible_in_read_only_mode(): void
    {
        $request = $this->stationStage();

        $this->actingAs($this->actors['station']);
        $request = $this->api('POST', "/requests/{$request['id']}/station-decision", [
            'revision' => $request['revision'],
            'decision' => ApprovalDecision::Declined->value,
            'comment'  => 'Нет поста',
        ])->assertOk()->json('request');

        $this->assertSame(RepairStage::ManagerRouting->value, $request['stage']);
        $this->assertSame([], $request['permissions']['editableFields']);
    }
}
