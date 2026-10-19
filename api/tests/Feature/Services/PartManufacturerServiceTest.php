<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

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
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PartManufacturerServiceTest extends TestCase
{
    use DatabaseTransactions;

    private User $manager;
    private User $station;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = Tenant::query()->create(['name' => 'Manufacturer test']);
        foreach (['manager', 'station', 'customer'] as $role) {
            $org = Organization::query()->create(['tenant_id' => $tenant->id, 'kind' => $role, 'name' => $role]);
            $this->{$role} = User::query()->create(['organization_id' => $org->id, 'display_name' => $role, 'login' => $role . Str::random(8), 'password' => bcrypt('test'), 'role' => $role, 'active' => true]);
        }
    }

    public function test_station_proposal_is_unselectable_until_manager_approval(): void
    {
        $service = app(PartManufacturerService::class);
        $record = $service->write($this->station, null, ['name' => 'Новый производитель']);
        $this->assertSame('pending', $record['status']);
        $this->assertCount(0, app(CatalogService::class)->reference($this->station)['partManufacturers']);
        $this->assertDatabaseHas('request_notifications', ['user_id' => $this->manager->id, 'kind' => 'manufacturer_approval']);
        $approved = $service->review($this->manager, $record['id'], ['decision' => 'approved', 'revision' => $record['revision']]);
        $this->assertSame('approved', $approved['status']);
        $this->assertCount(1, app(CatalogService::class)->reference($this->station)['partManufacturers']);
        $this->assertSame(0, RequestNotification::query()->where('user_id', $this->manager->id)->count());
        $this->assertDatabaseHas('catalog_change_events', ['catalog_kind' => 'partManufacturers', 'action' => 'approved']);
    }

    public function test_station_edit_preserves_current_name_until_review_and_rejection(): void
    {
        $service = app(PartManufacturerService::class);
        $record = $service->write($this->manager, null, ['name' => 'Original']);
        $model = PartManufacturer::query()->where('uuid', $record['id'])->firstOrFail();
        $proposal = $service->write($this->station, $model, ['name' => 'Changed', 'active' => false]);
        $this->assertSame('Original', $proposal['name']);
        $this->assertSame('Changed', $proposal['proposedName']);
        $rejected = $service->review($this->manager, $record['id'], ['decision' => 'rejected', 'revision' => $proposal['revision']]);
        $this->assertSame('Original', $rejected['name']);
        $this->assertTrue($rejected['active']);
    }

    public function test_customer_cannot_create_manufacturer(): void
    {
        $this->expectException(ApiException::class);
        app(PartManufacturerService::class)->write($this->customer, null, ['name' => 'Denied']);
    }

    public function test_new_part_requires_all_fields_and_station_can_create_it(): void
    {
        $manufacturer = app(PartManufacturerService::class)->write($this->manager, null, ['name' => 'TEST']);
        $supplier = Supplier::query()->create(['tenant_id' => $this->manager->tenantId(), 'name' => 'Supplier', 'active' => true]);
        $service = app(CatalogService::class);
        try {
            $service->create($this->station, 'parts', ['name' => 'Filter', 'article' => 'F1', 'unit' => 'шт.']);
            $this->fail('Missing required fields must fail');
        } catch (ValidationException|ApiException $e) {
            $this->assertTrue(true);
        }
        $part = $service->create($this->station, 'parts', ['name' => 'Filter', 'article' => 'F1', 'unit' => 'шт.', 'manufacturerId' => $manufacturer['id'], 'supplierId' => $supplier->uuid, 'purchasePrice' => 100, 'salePrice' => 150]);
        $this->assertSame('TEST', $part['manufacturer']);
        $this->assertSame(150.0, $part['salePrice']);
        $this->assertSame($supplier->uuid, $part['supplierId']);
    }

    public function test_manager_cannot_approve_other_tenant_proposal(): void
    {
        $record = app(PartManufacturerService::class)->write($this->station, null, ['name' => 'Isolated']);
        $other = Tenant::query()->create(['name' => 'Other']);
        $org = Organization::query()->create(['tenant_id' => $other->id, 'kind' => 'manager', 'name' => 'Other']);
        $manager = User::query()->create(['organization_id' => $org->id, 'display_name' => 'Other', 'login' => Str::random(12), 'password' => bcrypt('test'), 'role' => UserRole::Manager, 'active' => true]);
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(PartManufacturerService::class)->review($manager, $record['id'], ['decision' => 'approved', 'revision' => $record['revision']]);
    }
}
