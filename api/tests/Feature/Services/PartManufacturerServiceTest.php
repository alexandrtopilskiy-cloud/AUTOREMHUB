<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\DTO\UserDto;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\Organization;
use App\Models\PartManufacturer;
use App\Models\RequestNotification;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CatalogService;
use App\Services\PartManufacturerService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PartManufacturerServiceTest extends TestCase
{
    use DatabaseTransactions;

    private UserDto $managerDto;
    private UserDto $stationDto;
    private UserDto $customerDto;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = Tenant::query()->create(['name' => 'Manufacturer test']);

        $orgManager = Organization::query()->create(['tenant_id' => $tenant->id, 'kind' => 'manager', 'name'=> 'manager']);
        $manager = User::query()->create([
            'organization_id' => $orgManager->id,
            'display_name' => 'manager',
            'login' => 'manager' . Str::random(8),
            'password' => bcrypt('test'),
            'role' => 'manager',
            'active' => true
        ]);
        $this->managerDto = UserDto::fromUserModel($manager);

        $orgCustomer = Organization::query()->create(['tenant_id' => $tenant->id, 'kind' => 'customer', 'name'=> 'customer']);
        $customer = User::query()->create([
            'organization_id' => $orgCustomer->id,
            'display_name' => 'customer',
            'login' => 'customer' . Str::random(8),
            'password' => bcrypt('test'),
            'role' => 'customer',
            'active' => true
        ]);
        $this->customerDto = UserDto::fromUserModel($customer);

        $orgStation = Organization::query()->create(['tenant_id' => $tenant->id, 'kind' => 'station', 'name'=> 'station']);
        $station = User::query()->create([
            'organization_id' => $orgStation->id,
            'display_name' => 'station',
            'login' => 'station' . Str::random(8),
            'password' => bcrypt('test'),
            'role' => 'station',
            'active' => true
        ]);
        $this->stationDto = UserDto::fromUserModel($station);
    }

    public function test_station_proposal_is_unselectable_until_manager_approval(): void
    {
        $service = app(PartManufacturerService::class);
        $record = $service->write($this->stationDto, null, ['name' => 'Новый производитель']);
        $this->assertSame('pending', $record['status']);
        $this->assertCount(0, app(CatalogService::class)->reference($this->stationDto)['partManufacturers']);
        $this->assertDatabaseHas('request_notifications', ['user_id' => $this->managerDto->userId, 'kind' => 'manufacturer_approval']);
        $approved = $service->review($this->managerDto, $record['id'], ['decision' => 'approved', 'revision' => $record['revision']]);
        $this->assertSame('approved', $approved['status']);
        $this->assertCount(1, app(CatalogService::class)->reference($this->stationDto)['partManufacturers']);
        $this->assertSame(0, RequestNotification::query()->where('user_id', $this->managerDto->userId)->count());
        $this->assertDatabaseHas('catalog_change_events', ['catalog_kind' => 'partManufacturers', 'action' => 'approved']);
    }

    public function test_station_edit_preserves_current_name_until_review_and_rejection(): void
    {
        $service = app(PartManufacturerService::class);
        $record = $service->write($this->managerDto, null, ['name' => 'Original']);
        $model = PartManufacturer::query()->where('uuid', $record['id'])->firstOrFail();
        $proposal = $service->write($this->stationDto, $model, ['name' => 'Changed', 'active' => false]);
        $this->assertSame('Original', $proposal['name']);
        $this->assertSame('Changed', $proposal['proposedName']);
        $rejected = $service->review($this->managerDto, $record['id'], ['decision' => 'rejected', 'revision' => $proposal['revision']]);
        $this->assertSame('Original', $rejected['name']);
        $this->assertTrue($rejected['active']);
    }

    public function test_customer_cannot_create_manufacturer(): void
    {
        $this->expectException(ApiException::class);
        app(PartManufacturerService::class)->write($this->customerDto, null, ['name' => 'Denied']);
    }

    public function test_new_part_requires_all_fields_and_station_can_create_it(): void
    {
        $manufacturer = app(PartManufacturerService::class)->write($this->managerDto, null, ['name' => 'TEST']);
        $supplier = Supplier::query()->create(['tenant_id' => $this->managerDto->tenantId, 'name' => 'Supplier', 'active' => true]);
        $service = app(CatalogService::class);
        try {
            $service->create($this->stationDto, 'parts', ['name' => 'Filter', 'article' => 'F1', 'unit' => 'шт.']);
            $this->fail('Missing required fields must fail');
        } catch (ValidationException|ApiException $e) {
            $this->assertTrue(true);
        }
        $part = $service->create($this->stationDto, 'parts', ['name' => 'Filter', 'article' => 'F1', 'unit' => 'шт.', 'manufacturerId' => $manufacturer['id']]);
        $this->assertSame('TEST', $part['manufacturer']);
    }

    public function test_manager_cannot_approve_other_tenant_proposal(): void
    {
        $record = app(PartManufacturerService::class)->write($this->stationDto, null, ['name' => 'Isolated']);
        $other = Tenant::query()->create(['name' => 'Other']);
        $org = Organization::query()->create(['tenant_id' => $other->id, 'kind' => 'manager', 'name' => 'Other']);
        $manager = User::query()->create([
            'organization_id' => $org->id,
            'display_name' => 'Other',
            'login' => Str::random(12),
            'password' => bcrypt('test'),
            'role' => UserRole::Manager,
            'active' => true
        ]);
        $managerDto = UserDto::fromUserModel($manager);
        $this->expectException(ModelNotFoundException::class);
        app(PartManufacturerService::class)->review($managerDto, $record['id'], ['decision' => 'approved', 'revision' => $record['revision']]);
    }
}
