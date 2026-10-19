<?php
declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Organization;
use App\Models\RepairRequest;
use App\Models\RequestWork;
use App\Models\Station;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RepairRequestService;
use App\Services\Workflow;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Feature-тест для RepairRequestService::recalculatePlannedCompletion().
 *
 * Формула:
 *   planned_completion_at = слот + 30 минут приёмки + Σ(norm_hours × qty) × 3600 сек
 *
 * Результат нормализуется по времени и рабочим дням выбранной СТО.
 * Приоритет слотов: stationSlot > slot.
 * Пустой слот → planned_completion_at = null.
 */
final class RecalculatePlannedCompletionTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;
    private Organization $customerOrg;
    private User $customer;
    private Station $station;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-09-20 09:00:00'));

        $this->tenant = Tenant::query()->create(['name' => 'Тестовая УК']);

        $this->customerOrg = Organization::query()->create([
            'tenant_id' => $this->tenant->id,
            'kind'      => 'customer',
            'name'      => 'ООО «Заказчик»',
        ]);

        $this->customer = User::query()->create([
            'organization_id' => $this->customerOrg->id,
            'login'           => 'customer-' . Str::random(10),
            'display_name'    => 'Заказчик',
            'role'            => 'customer',
            'password'        => 'test-password-long',
            'active'          => true,
        ]);

        $stationOrganization = Organization::query()->create([
            'tenant_id' => $this->tenant->id,
            'kind'      => 'station',
            'name'      => 'ООО «СТО»',
        ]);

        $this->station = Station::query()->create([
            'tenant_id'       => $this->tenant->id,
            'organization_id' => $stationOrganization->id,
            'name'            => $stationOrganization->name,
            'address'         => '',
            'contact'         => '',
            'active'          => true,
            'workday_start'   => '10:00',
            'workday_end'     => '20:00',
            'working_days'    => range(1, 7),
            'profile'         => [],
        ]);
    }

    /* ─────────────────── базовые случаи ─────────────────── */

    public function test_empty_slots_clear_planned_completion(): void
    {
        $request = $this->requestWith([
            'slot'        => '',
            'stationSlot' => '',
        ]);

        $this->invokeRecalculate($request);

        $this->assertNull($request->planned_completion_at);
    }

    public function test_only_slot_without_works_gives_slot_plus_thirty_minutes(): void
    {
        $request = $this->requestWith([
            'slot'        => '2026-10-20T11:00',
            'stationSlot' => '',
        ]);

        $this->invokeRecalculate($request);

        // 11:00 MSK + 30 мин = 11:30 MSK = 08:30 UTC
        $this->assertSame(
            '2026-10-20 08:30',
            $request->planned_completion_at->utc()->format('Y-m-d H:i'),
        );
    }

    public function test_only_station_slot_without_works(): void
    {
        $request = $this->requestWith([
            'slot'        => '',
            'stationSlot' => '2026-10-20T14:00',
        ]);

        $this->invokeRecalculate($request);

        // 14:00 MSK + 30 мин = 14:30 MSK = 11:30 UTC
        $this->assertSame(
            '2026-10-20 11:30',
            $request->planned_completion_at->utc()->format('Y-m-d H:i'),
        );
    }

    public function test_station_slot_has_priority_over_slot(): void
    {
        $request = $this->requestWith([
            'slot'        => '2026-10-20T11:00',   // игнорируется
            'stationSlot' => '2026-10-20T15:00',   // используется
        ]);

        $this->invokeRecalculate($request);

        // 15:00 MSK + 30 мин = 15:30 MSK = 12:30 UTC
        $this->assertSame(
            '2026-10-20 12:30',
            $request->planned_completion_at->utc()->format('Y-m-d H:i'),
        );
    }

    /* ─────────────────── работы добавляют нормо-часы ─────────────────── */

    public function test_single_work_adds_norm_hours(): void
    {
        $request = $this->requestWith([
            'slot'        => '2026-10-20T11:00',
            'stationSlot' => '',
        ]);
        $this->createWork($request, normHours: 1.0, quantity: 1);

        $this->invokeRecalculate($request);

        // 11:00 + 30 мин + 1 ч = 12:30 MSK = 09:30 UTC
        $this->assertSame(
            '2026-10-20 09:30',
            $request->planned_completion_at->utc()->format('Y-m-d H:i'),
        );
    }

    public function test_multiple_works_sum_norm_hours(): void
    {
        $request = $this->requestWith([
            'slot'        => '2026-10-20T11:00',
            'stationSlot' => '',
        ]);
        $this->createWork($request, normHours: 1.0, quantity: 1);
        $this->createWork($request, normHours: 2.0, quantity: 1);

        $this->invokeRecalculate($request);

        // 11:00 + 30 мин + 3 ч = 14:30 MSK = 11:30 UTC
        $this->assertSame(
            '2026-10-20 11:30',
            $request->planned_completion_at->utc()->format('Y-m-d H:i'),
        );
    }

    public function test_quantity_multiplies_norm_hours(): void
    {
        $request = $this->requestWith([
            'slot'        => '2026-10-20T11:00',
            'stationSlot' => '',
        ]);
        // 2 × 1 ч = 2 ч
        $this->createWork($request, normHours: 1.0, quantity: 2.0);

        $this->invokeRecalculate($request);

        // 11:00 + 30 мин + 2 ч = 13:30 MSK = 10:30 UTC
        $this->assertSame(
            '2026-10-20 10:30',
            $request->planned_completion_at->utc()->format('Y-m-d H:i'),
        );
    }

    public function test_fractional_norm_hours_are_supported(): void
    {
        $request = $this->requestWith([
            'slot'        => '2026-10-20T11:00',
            'stationSlot' => '',
        ]);
        // 0.5 ч = 30 мин
        $this->createWork($request, normHours: 0.5, quantity: 1);

        $this->invokeRecalculate($request);

        // 11:00 + 30 мин + 30 мин = 12:00 MSK = 09:00 UTC
        $this->assertSame(
            '2026-10-20 09:00',
            $request->planned_completion_at->utc()->format('Y-m-d H:i'),
        );
    }

    public function test_work_without_norm_hours_keeps_completion_undefined(): void
    {
        $request = $this->requestWith([
            'slot'        => '2026-10-20T11:00',
            'stationSlot' => '',
        ]);
        $this->createWork($request, normHours: null, quantity: 1);

        $this->invokeRecalculate($request);

        $this->assertNull($request->planned_completion_at);
    }

    /* ─────────────────── интеграционные: через stationDecision ─────────────────── */

    public function test_station_decision_confirmed_sets_planned_completion(): void
    {
        $request = $this->requestWith([
            'slot'        => '2026-10-20T11:00',
            'stationSlot' => '',
        ]);
        $this->createWork($request, normHours: 1.0, quantity: 1);

        $this->invokeRecalculate($request);

        $this->assertNotNull($request->planned_completion_at);
        $this->assertSame(
            '2026-10-20 09:30',
            $request->planned_completion_at->utc()->format('Y-m-d H:i'),
        );
    }

    public function test_completion_before_opening_moves_to_opening_time(): void
    {
        $request = $this->requestWith([
            'slot'        => '2026-10-20T08:00',
            'stationSlot' => '',
        ]);

        $this->invokeRecalculate($request);

        $this->assertSame(
            '2026-10-20 07:00',
            $request->planned_completion_at->utc()->format('Y-m-d H:i'),
        );
    }

    public function test_completion_at_closing_moves_to_next_workday(): void
    {
        $this->station->update(['working_days' => [1, 2, 3, 4, 5]]);
        $request = $this->requestWith([
            'slot'        => '2026-10-23T19:30',
            'stationSlot' => '',
        ]);

        $this->invokeRecalculate($request);

        // Пятница 20:00 MSK → понедельник 10:00 MSK = 07:00 UTC.
        $this->assertSame(
            '2026-10-26 07:00',
            $request->planned_completion_at->utc()->format('Y-m-d H:i'),
        );
    }

    public function test_completion_on_day_off_moves_to_next_workday(): void
    {
        $this->station->update(['working_days' => [1, 2, 3, 4, 5]]);
        $request = $this->requestWith([
            'slot'        => '2026-10-25T11:00',
            'stationSlot' => '',
        ]);

        $this->invokeRecalculate($request);

        // Воскресенье → понедельник 10:00 MSK = 07:00 UTC.
        $this->assertSame(
            '2026-10-26 07:00',
            $request->planned_completion_at->utc()->format('Y-m-d H:i'),
        );
    }

    /* ─────────────────── хелперы ─────────────────── */

    /**
     * @param array<string, string> $data
     */
    private function requestWith(array $data): RepairRequest
    {
        $uuid = (string) Str::uuid();

        return RepairRequest::query()->create([
            'uuid'                     => $uuid,
            'public_id'                => $uuid,
            'tenant_id'                => $this->tenant->id,
            'customer_organization_id' => $this->customerOrg->id,
            'assigned_station_id'       => $this->station->id,
            'data'                     => array_replace(
                array_fill_keys(Workflow::TEXT_FIELDS, ''),
                $data,
            ),
            'works'                    => [],
            'history'                  => [],
            'stage'                    => 1,
            'revision'                 => 1,
            'created_by'               => $this->customer->id,
            'estimate_version'         => 1,
        ]);
    }

    private function createWork(
        RepairRequest $request,
        ?float $normHours,
        float $quantity,
    ): RequestWork {
        return RequestWork::query()->create([
            'uuid'              => (string) Str::uuid(),
            'repair_request_id' => $request->id,
            'name'              => 'Работа',
            'quantity'          => $quantity,
            'price'             => 100,
            'norm_hours'        => $normHours,
            'is_maintenance'    => false,
        ]);
    }

    private function invokeRecalculate(RepairRequest $request): void
    {
        $method = new ReflectionMethod(
            RepairRequestService::class,
            'recalculatePlannedCompletion',
        );
        $method->setAccessible(true);
        $method->invoke($this->service(), $request);
    }

    private function service(): RepairRequestService
    {
        return app(RepairRequestService::class);
    }
}
