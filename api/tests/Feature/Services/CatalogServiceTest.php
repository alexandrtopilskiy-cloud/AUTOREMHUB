<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Exceptions\ApiException;
use App\Models\CatalogChangeEvent;
use App\Models\CatalogPart;
use App\Models\Organization;
use App\Models\ServiceReason;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\TimeNorm;
use App\Models\TimeNormApplicability;
use App\Models\User;
use App\Models\VehicleMake;
use App\Models\WorkType;
use App\Services\CatalogService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature-тест для CatalogService.
 *
 * Покрывает:
 *   - reference: customer (без suppliers/parts), manager (всё)
 *   - create: workTypes, parts, reasons, suppliers, timeNorms, regulations
 *   - createParticipant: customer + station
 *   - update: workType, participant, parts; unknown → 404
 *   - history: empty, with events, filter by kind, customer → 403
 *   - present: workType fields, permissions
 */
final class CatalogServiceTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;
    private Organization $customerOrg;
    private Organization $managerOrg;
    private User $customer;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::query()->create(['name' => 'Тестовая УК']);

        $this->customerOrg = Organization::query()->create([
            'tenant_id' => $this->tenant->id,
            'kind'      => 'customer',
            'name'      => 'ООО «Заказчик»',
        ]);
        $this->managerOrg = Organization::query()->create([
            'tenant_id' => $this->tenant->id,
            'kind'      => 'manager',
            'name'      => 'УК',
        ]);

        $this->customer = $this->makeUser($this->customerOrg, 'customer');
        $this->manager  = $this->makeUser($this->managerOrg, 'manager');
    }

    /* ─────────────────── reference ─────────────────── */

    public function test_reference_customer_hides_suppliers_and_parts(): void
    {
        $this->makeWorkType('Работа ТО');
        $this->makeCatalogPart('Фильтр', 'F-1');
        $this->makeSupplier('Поставщик');
        $this->makeReason('Диагностика', 'diagnostics');

        $result = $this->service()->reference($this->customer);

        $this->assertCount(1, $result['workTypes']);
        $this->assertSame([], $result['suppliers']);
        $this->assertSame([], $result['parts']);
        $this->assertSame([], $result['organizations']);
        $this->assertSame([], $result['accessRights']);
        $this->assertNotEmpty($result['reasons']);
    }

    public function test_reference_customer_sees_only_maintenance_and_diagnostics_reasons(): void
    {
        $this->makeReason('Диагностика', 'diagnostics');
        $this->makeReason('ТО', 'maintenance');
        $this->makeReason('Прочее', 'other');

        $result = $this->service()->reference($this->customer);

        $codes = array_column($result['reasons'], 'code');
        $this->assertContains('diagnostics', $codes);
        $this->assertContains('maintenance', $codes);
        $this->assertNotContains('other', $codes);
    }

    public function test_reference_manager_sees_all(): void
    {
        $this->makeWorkType('Работа ТО');
        $this->makeCatalogPart('Фильтр', 'F-1');
        $this->makeSupplier('Поставщик');

        $result = $this->service()->reference($this->manager);

        $this->assertCount(1, $result['workTypes']);
        $this->assertCount(1, $result['suppliers']);
        $this->assertCount(1, $result['parts']);
        $this->assertCount(2, $result['organizations']);
        $this->assertNotEmpty($result['accessRights']);
    }

    /* ─────────────────── create ─────────────────── */

    public function test_create_work_type(): void
    {
        $result = $this->service()->create($this->manager, 'workTypes', [
            'name'          => 'Новая работа',
            'isMaintenance' => true,
            'active'        => true,
        ]);

        $this->assertSame('workTypes', $result['kind']);
        $this->assertSame('Новая работа', $result['name']);
        $this->assertTrue($result['isMaintenance']);
        $this->assertSame(1, WorkType::query()->count());
        $this->assertSame(1, CatalogChangeEvent::query()
        ->where('catalog_kind', 'workTypes')
            ->where('action', 'create')
            ->count());
    }

    public function test_create_work_type_with_inline_time_norms(): void
    {
        $general = $this->makeApplicability('general', 'Общая');

        $result = $this->service()->create($this->manager, 'workTypes', [
            'name'          => 'Замена масла',
            'group'         => 'Техническое обслуживание',
            'isMaintenance' => true,
            'active'        => true,
            'timeNorms'     => [[
                'applicabilityId' => $general->uuid,
                'vehicleMakeId'   => '',
                'vehicleModelId'  => '',
                'hours'           => 0.8,
                'source'          => 'Технологическая карта',
            ]],
        ]);

        $this->assertSame('Техническое обслуживание', $result['group']);
        $this->assertCount(1, $result['timeNorms']);
        $this->assertSame('Общая', $result['timeNorms'][0]['applicability']);
        $this->assertSame(0.8, $result['timeNorms'][0]['hours']);
        $this->assertDatabaseHas('work_types', [
            'name'       => 'Замена масла',
            'group_name' => 'Техническое обслуживание',
        ]);
    }

    public function test_update_work_type_archives_deleted_time_norm(): void
    {
        $general = $this->makeApplicability('general', 'Общая');
        $work = $this->service()->create($this->manager, 'workTypes', [
            'name'      => 'Удаляемая норма',
            'group'     => 'Прочее',
            'timeNorms' => [[
                'applicabilityId' => $general->uuid,
                'hours'           => 1.25,
                'source'          => 'Тест',
            ]],
        ]);
        $normId = $work['timeNorms'][0]['id'];

        $updated = $this->service()->update($this->manager, $work['id'], [
            'timeNorms'          => [],
            'deletedTimeNormIds' => [$normId],
        ]);

        $this->assertSame([], $updated['timeNorms']);
        $this->assertDatabaseHas('time_norms', [
            'uuid'   => $normId,
            'active' => false,
        ]);
        $this->assertNotNull(
            TimeNorm::query()->where('uuid', $normId)->value('archived_at'),
        );
        $this->assertDatabaseHas('catalog_change_events', [
            'catalog_kind' => 'timeNorms',
            'record_uuid'  => $normId,
            'action'       => 'delete',
        ]);
    }

    public function test_create_vehicle_norm_requires_make_or_model(): void
    {
        $vehicle = $this->makeApplicability('vehicle', 'По авто');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('выберите марку и/или модель ТС');

        $this->service()->create($this->manager, 'workTypes', [
            'name'      => 'Диагностика автомобиля',
            'group'     => 'Прочее',
            'timeNorms' => [[
                'applicabilityId' => $vehicle->uuid,
                'vehicleMakeId'   => '',
                'vehicleModelId'  => '',
                'hours'           => 1.5,
                'source'          => '',
            ]],
        ]);
    }

    public function test_create_part(): void
    {
        $manufacturer = app(\App\Services\PartManufacturerService::class)->write($this->manager, null, ['name' => 'Bosch']);
        $supplier = \App\Models\Supplier::query()->create(['tenant_id' => $this->manager->tenantId(), 'name' => 'Supplier', 'active' => true]);
        $result = $this->service()->create($this->manager, 'parts', [
            'name'         => 'Фильтр',
            'article'      => 'F-1',
            'manufacturerId' => $manufacturer['id'],
            'supplierId' => $supplier->uuid,
            'purchasePrice' => 100,
            'salePrice' => 150,
            'unit'         => 'шт.',
            'active'       => true,
        ]);

        $this->assertSame('parts', $result['kind']);
        $this->assertSame('F-1', $result['article']);
    }

    public function test_create_reason(): void
    {
        $result = $this->service()->create($this->manager, 'reasons', [
            'name'              => 'Новая причина',
            'code'              => 'new_reason',
            'requiresDiagnosis' => false,
            'isMaintenance'     => false,
            'active'            => true,
        ]);

        $this->assertSame('reasons', $result['kind']);
        $this->assertSame('new_reason', $result['code']);
    }

    public function test_create_supplier(): void
    {
        $result = $this->service()->create($this->manager, 'suppliers', array_replace($this->supplierPayload(), ['name' => 'Новый поставщик']));

        $this->assertSame('suppliers', $result['kind']);
        $this->assertSame('Новый поставщик', $result['name']);
    }

    public function test_create_time_norm(): void
    {
        $workType = $this->makeWorkType('Замена масла');

        $result = $this->service()->create($this->manager, 'timeNorms', [
            'workTypeId'   => $workType->uuid,
            'vehicleModel' => 'Lada Vesta',
            'hours'        => 0.5,
            'source'       => 'Согласованный норматив',
        ]);

        $this->assertSame('timeNorms', $result['kind']);
        $this->assertSame($workType->uuid, $result['workTypeId']);
        $this->assertSame(0.5, $result['hours']);
    }

    public function test_create_regulation_with_items(): void
    {
        $workType = $this->makeWorkType('ТО-1');
        $norm = $this->makeTimeNorm($workType, 'Lada', 1.5);

        $result = $this->service()->create($this->manager, 'regulations', [
            'name'           => 'Регламент ТО',
            'vehicleModel'   => 'Lada Vesta',
            'intervalKm'     => 15000,
            'intervalMonths' => 12,
            'source'         => 'Регламент',
            'active'         => true,
            'items'          => [[
                'workTypeId' => $workType->uuid,
                'timeNormId' => $norm->uuid,
                'qty'        => 1,
            ]],
        ]);

        $this->assertSame('regulations', $result['kind']);
        $this->assertCount(1, $result['items']);
        $this->assertSame($workType->uuid, $result['items'][0]['workTypeId']);
    }

    public function test_create_forbidden_for_customer(): void
    {
        $this->expectException(ApiException::class);

        $this->service()->create($this->customer, 'workTypes', [
            'name' => 'Нельзя',
        ]);
    }

    /* ─────────────────── createParticipant ─────────────────── */

    public function test_create_participant_customer(): void
    {
        $result = $this->service()->create($this->manager, 'customers', [
            'name'    => 'Новый Заказчик',
            'contact' => 'Иванов',
        ]);

        $this->assertSame('customers', $result['kind']);
        $this->assertSame('Новый Заказчик', $result['name']);
        $this->assertArrayNotHasKey('login', $result);
        $this->assertArrayNotHasKey('password', $result);
    }

    public function test_create_participant_station(): void
    {
        $result = $this->service()->create($this->manager, 'stations', [
            'name'         => 'Новая СТО',
            'address'      => 'Москва',
            'contact'      => 'Пётр',
            'active'       => true,
            'workdayStart' => '10:00',
            'workdayEnd'   => '20:00',
            'workingDays'  => [1, 2, 3, 4, 5],
        ]);

        $this->assertSame('stations', $result['kind']);
        $this->assertSame('Новая СТО', $result['name']);
        $this->assertSame('10:00', $result['workdayStart']);
        $this->assertSame('20:00', $result['workdayEnd']);
        $this->assertSame([1, 2, 3, 4, 5], $result['workingDays']);
        $this->assertArrayNotHasKey('login', $result);
        $this->assertArrayNotHasKey('password', $result);
    }

    public function test_update_participant_station_schedule(): void
    {
        $station = $this->service()->create($this->manager, 'stations', [
            'name'         => 'Новая СТО',
            'address'      => 'Москва',
            'workdayStart' => '10:00',
            'workdayEnd'   => '20:00',
            'workingDays'  => [1, 2, 3, 4, 5, 6, 7],
        ]);

        $result = $this->service()->update($this->manager, $station['id'], [
            'workdayStart' => '08:30',
            'workdayEnd'   => '18:30',
            'workingDays'  => [5, 1, 3, 2, 4],
        ]);

        $this->assertSame('08:30', $result['workdayStart']);
        $this->assertSame('18:30', $result['workdayEnd']);
        $this->assertSame([1, 2, 3, 4, 5], $result['workingDays']);
    }

    /* ─────────────────── update ─────────────────── */

    public function test_update_work_type(): void
    {
        $workType = $this->makeWorkType('Старое');

        $result = $this->service()->update($this->manager, $workType->uuid, [
            'name' => 'Новое',
        ]);

        $this->assertSame('Новое', $result['name']);
        $this->assertSame(1, CatalogChangeEvent::query()
        ->where('action', 'update')
            ->count());
    }

    public function test_update_participant(): void
    {
        $result = $this->service()->update(
            $this->manager,
            $this->customerOrg->uuid,
            ['name' => 'Обновлённое имя'],
        );

        $this->assertSame('Обновлённое имя', $result['name']);
        $this->assertSame('Обновлённое имя', $this->customerOrg->refresh()->name);
    }

    public function test_update_part_is_allowed_for_manager(): void
    {
        $manufacturer = app(\App\Services\PartManufacturerService::class)->write($this->manager, null, ['name' => 'Bosch']);
        $supplier = $this->makeSupplier('Поставщик');
        $part = $this->service()->create($this->manager, 'parts', [
            'name' => 'Фильтр', 'article' => 'F-1', 'manufacturerId' => $manufacturer['id'],
            'supplierId' => $supplier->uuid, 'purchasePrice' => 100, 'salePrice' => 150, 'unit' => 'шт.',
        ]);
        $updated = $this->service()->update($this->manager, $part['id'], ['name' => 'Фильтр обновлённый']);
        $this->assertSame('Фильтр обновлённый', $updated['name']);
        $this->assertSame(150.0, $updated['salePrice']);
    }

    public function test_update_forbidden_for_customer(): void
    {
        $workType = $this->makeWorkType('Работа');

        $this->expectException(ApiException::class);

        $this->service()->update($this->customer, $workType->uuid, [
            'name' => 'Нельзя',
        ]);
    }

    /* ─────────────────── history ─────────────────── */

    public function test_history_returns_empty_initially(): void
    {
        $this->assertSame([], $this->service()->history($this->manager));
    }

    public function test_history_returns_events(): void
    {
        $this->service()->create($this->manager, 'workTypes', [
            'name'          => 'Работа',
            'isMaintenance' => false,
            'active'        => true,
        ]);

        $result = $this->service()->history($this->manager);

        $this->assertCount(1, $result);
        $this->assertSame('workTypes', $result[0]['kind']);
        $this->assertSame('create', $result[0]['action']);
    }

    public function test_history_filters_by_kind(): void
    {
        $this->service()->create($this->manager, 'workTypes', [
            'name'          => 'Работа',
            'isMaintenance' => false,
            'active'        => true,
        ]);
        $this->service()->create($this->manager, 'suppliers', $this->supplierPayload());

        $result = $this->service()->history($this->manager, 'workTypes');

        $this->assertCount(1, $result);
        $this->assertSame('workTypes', $result[0]['kind']);
    }

    public function test_history_forbidden_for_customer(): void
    {
        $this->expectException(ApiException::class);

        $this->service()->history($this->customer);
    }

    /* ─────────────────── present ─────────────────── */

    public function test_present_work_type_permissions_for_manager(): void
    {
        $workType = $this->makeWorkType('Работа');

        $result = $this->service()->present('workTypes', $workType, $this->manager);

        $this->assertSame($workType->uuid, $result['id']);
        $this->assertSame('workTypes', $result['kind']);
        $this->assertTrue($result['permissions']['canEdit']);
        $this->assertFalse($result['permissions']['canCreateOnly']);
    }

    public function test_present_part_permissions_allow_editing(): void
    {
        $part = $this->makeCatalogPart('Фильтр', 'F-1');

        $result = $this->service()->present('parts', $part, $this->manager);

        $this->assertTrue($result['permissions']['canEdit']);
        $this->assertFalse($result['permissions']['canCreateOnly']);
    }

    public function test_present_for_customer_has_no_edit_permissions(): void
    {
        $workType = $this->makeWorkType('Работа');

        $result = $this->service()->present('workTypes', $workType, $this->customer);

        $this->assertFalse($result['permissions']['canEdit']);
        $this->assertFalse($result['permissions']['canCreateOnly']);
    }

    /* ─────────────────── helpers ─────────────────── */

    private function service(): CatalogService
    {
        return app(CatalogService::class);
    }

    private function makeWorkType(string $name): WorkType
    {
        return WorkType::query()->create([
            'tenant_id'      => $this->tenant->id,
            'name'           => $name,
            'is_maintenance' => false,
            'active'         => true,
        ]);
    }

    private function makeCatalogPart(string $name, string $article): CatalogPart
    {
        return CatalogPart::query()->create([
            'tenant_id'    => $this->tenant->id,
            'name'         => $name,
            'article'      => $article,
            'manufacturer' => '',
            'unit'         => 'шт.',
            'active'       => true,
        ]);
    }

    private function supplierPayload(bool $individual = false): array
    {
        return ['legalForm' => $individual ? 'ip' : 'ooo', 'name' => $individual ? 'ИП Иванов' : 'ООО Поставщик',
            'taxId' => $individual ? '500100732259' : '7707083893', 'ogrn' => $individual ? '304500116000157' : '1027700132195',
            'kpp' => $individual ? '' : '773601001', 'registrationDate' => '2020-01-15',
            'lastName' => $individual ? 'Иванов' : '', 'firstName' => $individual ? 'Иван' : '',
            'directorName' => $individual ? '' : 'Иванов Иван Иванович',
            'legalAddress' => 'Москва', 'actualAddress' => 'Москва', 'postalAddress' => 'Москва',
            'bankName' => 'Банк', 'bik' => '044525225', 'settlementAccount' => '40702810900000002851',
            'correspondentAccount' => '30101810400000000225', 'taxRegime' => 'ОСНО', 'vatRate' => 'Без НДС',
            'contacts' => [['lastName' => 'Иванов', 'firstName' => 'Иван', 'phone' => '+7 (999) 123 45 67', 'email' => '']]];
    }

    private function makeSupplier(string $name): Supplier
    {
        return Supplier::query()->create([
            'tenant_id' => $this->tenant->id,
            'name'      => $name,
            'contact'   => '',
            'tax_id'    => '',
            'active'    => true,
        ]);
    }

    private function makeReason(string $name, string $code): ServiceReason
    {
        return ServiceReason::query()->create([
            'tenant_id' => $this->tenant->id,
            'name'      => $name,
            'code'      => $code,
            'active'    => true,
        ]);
    }

    private function makeTimeNorm(WorkType $workType, string $model, float $hours): TimeNorm
    {
        return TimeNorm::query()->create([
            'work_type_id'  => $workType->id,
            'vehicle_model' => $model,
            'hours'         => $hours,
            'source'        => '',
        ]);
    }

    private function makeApplicability(
        string $code,
        string $name,
    ): TimeNormApplicability {
        return TimeNormApplicability::query()->create([
            'tenant_id'       => $this->tenant->id,
            'code'            => $code,
            'name'            => $name,
            'normalized_name' => mb_strtolower($name),
            'is_system'       => true,
            'active'          => true,
        ]);
    }

    private function makeUser(Organization $organization, string $role): User
    {
        return User::query()->create([
            'organization_id' => $organization->id,
            'login'           => $role . '-' . Str::random(10),
            'display_name'    => ucfirst($role),
            'role'            => $role,
            'password'        => 'test-password-long',
            'active'          => true,
        ]);
    }
}
