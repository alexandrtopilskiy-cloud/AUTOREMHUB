<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\DTO\TransitionDto;
use App\DTO\UpdateRepairRequestDto;
use App\Enums\ApprovalDecision;
use App\Enums\RepairStage;
use App\Enums\TransitionAction;
use App\Exceptions\ApiException;
use App\Models\CatalogPart;
use App\Models\Organization;
use App\Models\RecommendationItem;
use App\Models\RepairRequest;
use App\Models\RequestApproval;
use App\Models\RequestPart;
use App\Models\RequestStatusPeriod;
use App\Models\RequestWork;
use App\Models\Station;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkType;
use App\Services\RecommendationService;
use App\Services\RepairRequestService;
use App\Services\WorkChangeService;
use App\Support\ApiPresenter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Комментарии к строкам ЗР и изменение работ/ЗЧ на стадии «В работе»
 * с согласованием УК → Заказчик и возвратом в СТО.
 */
final class WorkChangeInProgressTest extends TestCase
{
    use DatabaseTransactions;

    private User $manager;
    private User $customer;
    private User $station;
    private RepairRequest $request;
    private Supplier $supplier;
    private CatalogPart $catalog;
    private WorkType $workType;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = Tenant::query()->create(['name' => 'Work change test']);
        foreach (['manager', 'customer', 'station'] as $role) {
            $org = Organization::query()->create(['tenant_id' => $tenant->id, 'kind' => $role, 'name' => $role]);
            $this->{$role} = User::query()->create([
                'organization_id' => $org->id,
                'display_name'    => 'Иван ' . $role,
                'login'           => $role . Str::random(8),
                'password'        => bcrypt('test'),
                'role'            => $role,
                'active'          => true,
            ]);
        }
        $vehicle = Vehicle::query()->create([
            'tenant_id'        => $tenant->id,
            'organization_id'  => $this->customer->organization_id,
            'name'             => 'Test Car',
            'vin'              => 'TESTVIN1234567891',
            'plate'            => 'A112AA78',
            'plate_normalized' => 'A112AA78',
            'labor_hour_rate'  => 1000,
        ]);
        $station = Station::query()->create([
            'tenant_id'       => $tenant->id,
            'organization_id' => $this->station->organization_id,
            'name'            => 'Station',
        ]);
        $this->request = RepairRequest::query()->create([
            'public_id'                => (string) Str::uuid(),
            'tenant_id'                => $tenant->id,
            'customer_organization_id' => $this->customer->organization_id,
            'created_by'               => $this->customer->id,
            'vehicle_id'               => $vehicle->id,
            'assigned_station_id'      => $station->id,
            'stage'                    => RepairStage::RepairInProgress,
            'revision'                 => 0,
            'estimate_version'         => 3,
            'data'                     => ['mileage' => '1000', 'laborRateType' => 'general'],
            'works'                    => [],
            'history'                  => [],
        ]);
        RequestStatusPeriod::query()->create([
            'repair_request_id' => $this->request->id,
            'stage'             => RepairStage::RepairInProgress->value,
            'entered_at'        => now(),
            'actor_id'          => $this->station->id,
        ]);
        $this->supplier = Supplier::query()->create(['tenant_id' => $tenant->id, 'name' => 'Supplier', 'active' => true]);
        $this->catalog = CatalogPart::query()->create([
            'tenant_id'    => $tenant->id,
            'name'         => 'Filter',
            'article'      => 'F-1',
            'unit'         => 'шт.',
            'manufacturer' => 'Test',
            'supplier_id'  => $this->supplier->id,
            'sale_price'   => 200,
            'active'       => true,
        ]);
        $this->workType = WorkType::query()->create([
            'tenant_id'  => $tenant->id,
            'name'       => 'Замена масла',
            'group_name' => 'Прочее',
            'active'     => true,
        ]);
        RequestWork::query()->create([
            'repair_request_id'    => $this->request->id,
            'name'                 => 'Замена масла',
            'work_type_id'         => $this->workType->id,
            'quantity'             => 1,
            'price'                => 1500,
            'hourly_rate'          => 1000,
            'norm_hours'           => 1.5,
            'norm_is_manual'       => true,
            'norm_override_reason' => 'Ручная норма',
            'line_amount'          => 1500,
        ]);
        RequestPart::query()->create([
            'repair_request_id' => $this->request->id,
            'catalog_part_id'   => $this->catalog->id,
            'name'              => 'Filter',
            'article'           => 'F-1',
            'unit'              => 'шт.',
            'supplier_id'       => $this->supplier->id,
            'quantity'          => 2,
            'price'             => 250,
            'status'            => 'approved',
            'approved_version'  => 3,
        ]);
    }

    private function document(User $user): array
    {
        $this->actingAs($user);

        return app(ApiPresenter::class)->document($this->request->fresh(), $user);
    }

    private function patchLines(User $user, array $patch): array
    {
        $this->actingAs($user);
        // Как фронт: служебные поля строк не отправляются.
        if (isset($patch['works'])) {
            $patch['works'] = array_map(static function (array $work): array {
                unset($work['normApplicabilityLabel']);

                return $work;
            }, $patch['works']);
        }
        if (isset($patch['parts'])) {
            $keys = array_flip(['id', 'catalogPartId', 'name', 'article', 'unit', 'supplierId', 'qty', 'price', 'comment', 'changeNote']);
            $patch['parts'] = array_map(static fn (array $part): array => array_intersect_key($part, $keys), $patch['parts']);
        }
        $dto = new UpdateRepairRequestDto(
            requestKey: $this->request->uuid,
            revision: $this->revision(),
            rawPatch: $patch,
            works: $patch['works'] ?? null,
            parts: $patch['parts'] ?? null,
        );
        app(RepairRequestService::class)->update($user, $dto);

        return $this->document($user);
    }

    private function revision(): int
    {
        return $this->request->fresh()->revision;
    }

    public function test_line_comments_stay_in_request_and_do_not_touch_catalogs(): void
    {
        $doc = $this->document($this->station);
        $doc['works'][0]['comment'] = 'Масло клиента';
        $doc['parts'][0]['comment'] = 'Оригинал';
        $doc = $this->patchLines($this->station, ['works' => $doc['works'], 'parts' => $doc['parts']]);

        $this->assertSame('Масло клиента', $doc['works'][0]['comment']);
        $this->assertSame('Оригинал', $doc['parts'][0]['comment']);
        // Комментарий — не изменение сметы: согласование не требуется.
        $this->assertNull($doc['workChange']);
        $this->assertSame('approved', $doc['parts'][0]['status']);
        $this->assertSame('Замена масла', $this->workType->fresh()->name);
        $this->assertSame('Filter', $this->catalog->fresh()->name);
    }

    public function test_changes_require_notes_and_go_through_manager_and_customer(): void
    {
        $service = app(WorkChangeService::class);
        $doc = $this->document($this->station);
        $this->assertTrue(in_array('works', $doc['permissions']['editableFields'], true));
        $this->assertTrue($doc['permissions']['canEditParts']);

        $doc['works'][0]['qty'] = 2;
        $doc = $this->patchLines($this->station, ['works' => $doc['works']]);
        $this->assertSame('editing', $doc['workChange']['status']);
        $this->assertSame('changed', $doc['workChange']['items'][0]['action']);
        // Пока изменения не согласованы, версия сметы не меняется.
        $this->assertSame(3, $this->request->fresh()->estimate_version);

        try {
            $service->submit($this->station, $this->request->uuid, $this->revision(), []);
            $this->fail('Изменение без примечания не должно отправляться');
        } catch (ApiException) {
        }

        $doc['works'][0]['changeNote'] = 'Нужна вторая замена';
        $this->patchLines($this->station, ['works' => $doc['works']]);
        $service->submit($this->station, $this->request->uuid, $this->revision(), []);

        $doc = $this->document($this->manager);
        $this->assertSame('Согласование работ / з/ч', $doc['statusLabel']);
        $this->assertSame('УК', $doc['ownerLabel']);
        $this->assertTrue($doc['permissions']['canDecideWorkChange']);
        $this->assertTrue($doc['permissions']['requiresAction']);
        $stationDoc = $this->document($this->station);
        $this->assertSame([], $stationDoc['permissions']['editableFields']);
        $this->assertFalse($stationDoc['permissions']['canAdvance']);

        try {
            $service->decide($this->manager, $this->request->uuid, $this->revision(), false, '');
            $this->fail('Отказ без комментария недопустим');
        } catch (ApiException) {
        }

        $service->decide($this->manager, $this->request->uuid, $this->revision(), false, 'Слишком дорого');
        $stationDoc = $this->document($this->station);
        $this->assertSame('rejected', $stationDoc['workChange']['status']);
        $this->assertSame('Слишком дорого', $stationDoc['workChange']['rejection']['comment']);
        $this->assertTrue($stationDoc['permissions']['canSubmitWorkChange']);

        $service->submit($this->station, $this->request->uuid, $this->revision(), []);
        $service->decide($this->manager, $this->request->uuid, $this->revision(), true, '');
        $customerDoc = $this->document($this->customer);
        $this->assertSame('customer', $customerDoc['workChange']['status']);
        $this->assertTrue($customerDoc['permissions']['canDecideWorkChange']);
        $this->assertCount(1, $customerDoc['workChange']['items']);

        $service->decide($this->customer, $this->request->uuid, $this->revision(), true, '');
        $request = $this->request->fresh();
        $this->assertNull($request->work_change);
        $this->assertSame(4, $request->estimate_version);
        $this->assertSame('', $this->document($this->station)['works'][0]['changeNote']);
        $this->assertSame(4, RequestPart::query()->where('repair_request_id', $request->id)->value('approved_version'));
        $this->assertTrue(RequestApproval::query()
            ->where('repair_request_id', $request->id)
            ->where('stage', RepairStage::CustomerApproval->value)
            ->where('decision', ApprovalDecision::Accepted->value)
            ->where('estimate_version', 4)
            ->exists());
    }

    public function test_customer_rejection_returns_to_station_and_discard_restores_estimate(): void
    {
        $service = app(WorkChangeService::class);
        $doc = $this->document($this->station);
        $partId = $doc['parts'][0]['id'];
        $doc['parts'] = [];
        $this->patchLines($this->station, ['parts' => $doc['parts']]);
        $service->submit($this->station, $this->request->uuid, $this->revision(), [$partId => 'Клиент привёз свой фильтр']);
        $service->decide($this->manager, $this->request->uuid, $this->revision(), true, '');
        $service->decide($this->customer, $this->request->uuid, $this->revision(), false, 'Фильтр нужен');

        $stationDoc = $this->document($this->station);
        $this->assertSame('rejected', $stationDoc['workChange']['status']);
        $this->assertSame('Заказчик', $stationDoc['workChange']['rejection']['by']);
        $this->assertSame('В работе', $stationDoc['statusLabel']);

        $service->discard($this->station, $this->request->uuid, $this->revision());
        $doc = $this->document($this->station);
        $this->assertNull($doc['workChange']);
        $this->assertSame($partId, $doc['parts'][0]['id']);
        $this->assertSame('approved', $doc['parts'][0]['status']);
        $this->assertSame(3, $doc['parts'][0]['approvedVersion']);
    }

    public function test_completion_is_blocked_while_changes_are_not_approved(): void
    {
        $doc = $this->document($this->station);
        $doc['works'][0]['qty'] = 3;
        $doc['works'][0]['changeNote'] = 'Объём работ вырос';
        $this->patchLines($this->station, ['works' => $doc['works']]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Изменения работ и запчастей не согласованы');
        app(RepairRequestService::class)->transition($this->station, new TransitionDto(
            requestKey: $this->request->uuid,
            revision: $this->revision(),
            action: TransitionAction::Next,
            comment: 'Готово',
            attachmentIds: [],
        ));
    }

    public function test_station_can_move_lines_to_recommendations_in_work(): void
    {
        $doc = $this->document($this->station);
        $this->assertTrue($doc['permissions']['canDeferRecommendations']);

        $result = app(RecommendationService::class)->defer(
            $this->station,
            $this->request->uuid,
            $this->revision(),
            ['workIds' => [], 'partIds' => [$doc['parts'][0]['id']]],
        );

        $this->assertSame([], $result['request']['parts']);
        $item = $result['request']['workChange']['items'][0];
        $this->assertSame('removed', $item['action']);
        $this->assertStringStartsWith('Перенесено в Рекомендации REC-', $item['note']);

        // Отмена изменений возвращает строку и убирает её из рекомендаций.
        app(WorkChangeService::class)->discard($this->station, $this->request->uuid, $this->revision());
        $this->assertSame(1, $this->request->parts()->count());
        $this->assertSame(0, RecommendationItem::query()->whereIn('recommendation_id', \App\Models\Recommendation::query()->select('id')->where('source_request_id', $this->request->id))->count());
    }
}
