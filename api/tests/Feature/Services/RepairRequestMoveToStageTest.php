<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\OrganizationKind;
use App\Enums\RepairStage;
use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\RepairRequest;
use App\Models\RequestStatusPeriod;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RepairRequestService;
use App\Services\Workflow;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Characterization-тест для RepairRequestService::moveToStage().
 *
 * Проверяет:
 *   - закрытие текущего активного периода;
 *   - создание нового периода с правильным stage и actor_id;
 *   - для stage=11 (Closed) новый период сразу закрывается;
 *   - без активного периода — ModelNotFoundException;
 *   - moveToStage не сохраняет модель (это делает mutate()).
 */
final class RepairRequestMoveToStageTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;
    private User $manager;
    private RepairRequest $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::query()->create(['name' => 'Тестовая УК']);

        $managerOrg = Organization::query()->create([
            'tenant_id' => $this->tenant->id,
            'kind'      => OrganizationKind::Manager,
            'name'      => 'УК',
        ]);

        $this->manager = User::query()->create([
            'organization_id' => $managerOrg->id,
            'login'           => 'manager-' . Str::random(10),
            'display_name'    => 'Менеджер',
            'role'            => UserRole::Manager,
            'password'        => 'test-password-long',
            'active'          => true,
        ]);

        $customerOrg = Organization::query()->create([
            'tenant_id' => $this->tenant->id,
            'kind'      => OrganizationKind::Customer,
            'name'      => 'ООО «Заказчик»',
        ]);

        $uuid = (string) Str::uuid();

        $this->request = RepairRequest::query()->create([
            'uuid'                     => $uuid,
            'public_id'                => $uuid,
            'tenant_id'                => $this->tenant->id,
            'customer_organization_id' => $customerOrg->id,
            'data'                     => array_fill_keys(Workflow::TEXT_FIELDS, ''),
            'works'                    => [],
            'history'                  => [],
            'stage'                    => RepairStage::ManagerRouting,
            'revision'                 => 1,
            'created_by'               => $this->manager->id,
        ]);
    }

    public function test_move_to_stage_closes_current_and_creates_new_period(): void
    {
        $from = RepairStage::ManagerRouting;
        $to = RepairStage::StationApproval;

        $old = RequestStatusPeriod::query()->create([
            'repair_request_id' => $this->request->id,
            'stage'             => $from->value,
            'entered_at'        => now()->subHour(),
            'actor_id'          => $this->manager->id,
        ]);

        $this->invokeMoveToStage($this->request, $this->manager, $to);

        $this->assertSame(
            $to,
            $this->request->stage,
            'stage в памяти не обновился',
        );

        $this->assertNotNull(
            $old->refresh()->exited_at,
            'Старый период не закрыт',
        );

        $active = $this->request->periods()->whereNull('exited_at')->get();
        $this->assertCount(1, $active, 'Должен быть ровно один активный период');

        $new = $active->first();
        $this->assertSame($to, $new->stage);
        $this->assertSame($this->request->id, $new->repair_request_id);
        $this->assertSame($this->manager->id, $new->actor_id);
        $this->assertNull($new->exited_at);
    }

    public function test_move_to_closed_closes_new_period_immediately(): void
    {
        $from = RepairStage::PaymentReview;
        $to = RepairStage::Closed;

        RequestStatusPeriod::query()->create([
            'repair_request_id' => $this->request->id,
            'stage'             => $from->value,
            'entered_at'        => now()->subHour(),
            'actor_id'          => $this->manager->id,
        ]);

        $this->invokeMoveToStage($this->request, $this->manager, $to);

        $this->assertSame($to, $this->request->stage);

        $this->assertSame(
            0,
            $this->request->periods()->whereNull('exited_at')->count(),
            'При переходе в Closed не должно оставаться активных периодов',
        );

        $last = RequestStatusPeriod::query()
            ->where('repair_request_id', $this->request->id)
            ->latest('id')
            ->first();

        $this->assertSame($to, $last->stage);
        $this->assertNotNull($last->exited_at);
    }

    public function test_move_to_stage_fails_without_active_period(): void
    {
        // Если активного периода нет — firstOrFail() бросит
        // ModelNotFoundException. Это важно зафиксировать:
        // moveToStage не создаёт период «с нуля», а закрывает текущий.
        $this->expectException(ModelNotFoundException::class);

        $this->invokeMoveToStage(
            $this->request,
            $this->manager,
            RepairStage::StationApproval,
        );
    }

    public function test_move_to_stage_records_actor(): void
    {
        $other = User::query()->create([
            'organization_id' => $this->manager->organization_id,
            'login'           => 'other-' . Str::random(10),
            'display_name'    => 'Другой',
            'role'            => UserRole::Manager,
            'password'        => 'test-password-long',
            'active'          => true,
        ]);

        RequestStatusPeriod::query()->create([
            'repair_request_id' => $this->request->id,
            'stage'             => RepairStage::ManagerRouting->value,
            'entered_at'        => now()->subHour(),
            'actor_id'          => $this->manager->id,
        ]);

        $this->invokeMoveToStage($this->request, $other, RepairStage::StationApproval);

        $new = RequestStatusPeriod::query()
            ->where('repair_request_id', $this->request->id)
            ->latest('id')
            ->first();

        $this->assertSame(
            $other->id,
            $new->actor_id,
            'moveToStage должен записать actor_id переданного пользователя',
        );
    }

    /* ─────────────────── хелперы ─────────────────── */

    private function invokeMoveToStage(
        RepairRequest $request,
        User $user,
        RepairStage $to,
    ): void
    {
        $method = new ReflectionMethod(RepairRequestService::class, 'moveToStage');
        $method->setAccessible(true);
        $method->invoke($this->service(), $request, $user, $to);
    }

    private function service(): RepairRequestService
    {
        return app(RepairRequestService::class);
    }
}
