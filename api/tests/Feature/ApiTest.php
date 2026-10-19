<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ApprovalDecision;
use App\Enums\RepairStage;
use App\Enums\UserRole;
use App\Models\Attachment;
use App\Models\Organization;
use App\Models\OrganizationPerson;
use App\Models\RepairRequest;
use App\Models\RequestApproval;
use App\Models\RequestEvent;
use App\Models\RequestStatusPeriod;
use App\Models\ServiceReason;
use App\Models\Station;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContact;
use App\Models\WorkType;
use App\Services\ReferenceSeeder;
use App\Services\VehicleImporter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ApiTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<string, User> */
    private array $actors = [];
    private Vehicle $vehicle;
    private VehicleContact $contact;
    private VehicleContact $driver;
    private Station $station;
    private Supplier $supplier;
    private ServiceReason $reason;
    private ServiceReason $subtype;
    private WorkType $work;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-09-20 09:00:00'));

        Storage::fake('media');

        $tenant = Tenant::create(['name' => 'Тестовая УК']);

        foreach (['customer', 'manager', 'station'] as $role) {
            $org = Organization::create([
                'tenant_id' => $tenant->id,
                'kind'      => $role,
                'name'      => $role === 'customer' ? 'ООО "Тест"' : $role,
            ]);

            $this->actors[$role] = User::create([
                'organization_id' => $org->id,
                'login'           => $role . '-' . Str::random(10),
                'display_name'    => $role,
                'role'            => $role,
                'password'        => 'test-password-long',
                'active'          => true,
            ]);
        }

        $this->vehicle = Vehicle::create([
            'tenant_id'        => $tenant->id,
            'organization_id'  => $this->actors['customer']->organization_id,
            'name'             => 'Автомобиль',
            'vin'              => 'LVUDB21B2RA042817',
            'plate'            => 'А123АА178',
            'plate_normalized' => 'А123АА178',
            'year'             => 2026,
            'labor_hour_rate'  => 100,
        ]);

        $contactPerson = OrganizationPerson::create([
            'tenant_id'       => $tenant->id,
            'organization_id' => $this->actors['customer']->organization_id,
            'kind'            => 'contact',
            'last_name'       => 'Иванов',
            'first_name'      => 'Иван',
            'middle_name'     => 'Иванович',
            'phone'           => '+79991234567',
        ]);

        $driverPerson = OrganizationPerson::create([
            'tenant_id'       => $tenant->id,
            'organization_id' => $this->actors['customer']->organization_id,
            'kind'            => 'driver',
            'last_name'       => 'Петров',
            'first_name'      => 'Пётр',
            'middle_name'     => 'Петрович',
            'phone'           => '+79997654321',
        ]);

        $this->contact = VehicleContact::create([
            'vehicle_id'             => $this->vehicle->id,
            'organization_person_id' => $contactPerson->id,
            'kind'                   => 'contact',
            'last_name'              => 'Иванов',
            'first_name'             => 'Иван',
            'middle_name'            => 'Иванович',
            'phone'                  => '+79991234567',
        ]);

        $this->driver = VehicleContact::create([
            'vehicle_id'             => $this->vehicle->id,
            'organization_person_id' => $driverPerson->id,
            'kind'                   => 'driver',
            'last_name'              => 'Петров',
            'first_name'             => 'Пётр',
            'middle_name'            => 'Петрович',
            'phone'                  => '+79997654321',
        ]);

        $this->station = Station::create([
            'tenant_id'       => $tenant->id,
            'organization_id' => $this->actors['station']->organization_id,
            'name'            => 'СТО',
            'address'         => 'Адрес',
        ]);

        $this->supplier = Supplier::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Поставщик',
        ]);

        $this->work = WorkType::create([
            'tenant_id'  => $tenant->id,
            'name'       => 'Диагностика',
            'group_name' => 'Прочее',
            'active'     => true,
        ]);

        app(ReferenceSeeder::class)->seed($tenant->id);

        $this->reason = ServiceReason::where('tenant_id', $tenant->id)
        ->where('code', 'diagnostics')
            ->firstOrFail();

        $this->subtype = ServiceReason::where('parent_id', $this->reason->id)
        ->firstOrFail();
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    private function api(string $method, string $path, array $body = [], array $headers = [])
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'  => 'application/json',
        ];

        foreach ($headers as $key => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $key))] = $value;
        }

        return $this->call(
            $method,
            '/api' . $path,
            [],
            [],
            [],
            $server,
            json_encode((object) $body, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function draft(): array
    {
        $this->actingAs($this->actors['customer']);

        return $this->api('POST', '/requests')
            ->assertCreated()
            ->json('request');
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed> $patch
     *
     * @return array<string, mixed>
     */
    private function patchRequest(array $request, array $patch): array
    {
        $body = [
            'revision' => $request['revision'],
            'patch'    => (object) $patch,
        ];
        $method = 'PATCH';
        $id = $request['id'];

        $response = $this->api($method, "/requests/$id", $body);

        return $response->assertOk()->json('request');
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>
     */
    private function media(array $request, string $purpose = 'evidence'): array
    {
        $file = $purpose === 'evidence'
            ? UploadedFile::fake()->createWithContent(
            'photo.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6QmcAAAAASUVORK5CYII='),
        )
            : UploadedFile::fake()->createWithContent(
            'invoice.pdf',
            "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF",
        );

        return $this->call(
            'POST',
            '/api/requests/' . $request['id'] . '/attachments',
            [
                'revision' => (string) $request['revision'],
                'purpose'  => $purpose,
            ],
            [],
            ['file' => $file],
            ['HTTP_ACCEPT' => 'application/json'],
        )->assertCreated()->json('request');
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>
     */
    private function next(array $request, string $action = 'next'): array
    {
        $role = auth()->user()?->role;

        if ($role === UserRole::Manager) {
            return $this->api('POST', '/requests/' . $request['id'] . '/' . $action, [
                'revision'      => $request['revision'],
                'comment'       => '',
                'attachmentIds' => [],
            ])->assertOk()->json('request');
        }

        if ($role === UserRole::Station) {
            return $this->api('POST', '/requests/' . $request['id'] . '/' . $action, [
                'revision'      => $request['revision'],
                'comment'       => 'Комментарий решения',
                'attachmentIds' => [],
            ])->assertOk()->json('request');
        }

        $request = $this->media($request);
        $files = $request['attachments'];
        $file = end($files);

        return $this->api('POST', '/requests/' . $request['id'] . '/' . $action, [
            'revision'      => $request['revision'],
            'comment'       => 'Комментарий решения',
            'attachmentIds' => [$file['id']],
        ])->assertOk()->json('request');
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>
     */
    private function stationDecision(array $request, string $decision, string $comment = 'Решение СТО'): array
    {
        return $this->api('POST', '/requests/' . $request['id'] . '/station-decision', [
            'revision' => $request['revision'],
            'decision' => $decision,
            'comment'  => $comment,
            'stationSlot'  => $request['stationSlot'] ?? '',
        ])->assertOk()->json('request');
    }

    /**
     * @return array<string, mixed>
     */
    private function stationStage(): array
    {
        $request = $this->patchRequest($this->draft(), [
            'vehicleId'        => $this->vehicle->uuid,
            'contactId'        => $this->contact->uuid,
            'reasonId'         => $this->reason->uuid,
            'mileage'          => '15000',
            'slot'             => '2026-10-20T11:00',
        ]);

        $request = $this->next($request);

        $this->actingAs($this->actors['manager']);

        $request = $this->patchRequest($request, [
            'stationId' => $this->station->uuid,
            'works'     => [[
                'name'        => 'Диагностика',
                'type'        => 'Работа',
                'workTypeId'  => $this->work->uuid,
                'qty'         => 1,
                'price'       => 100,
                'normHours'   => 1,
                'actualHours' => 0.5,
                'normIsManual'       => true,
                'normOverrideReason' => 'Ручная норма для теста',
            ]],
        ]);

        return $this->next($request);
    }

    public function test_new_requests_are_empty_and_numbers_use_global_integer_ids(): void
    {
        $first = $this->draft();
        $second = $this->draft();

        $this->assertSame(
            'RP-' . str_pad((string) $first['systemId'], 6, '0', STR_PAD_LEFT),
            $first['number'],
        );
        $this->assertSame($first['systemId'] + 1, $second['systemId']);

        foreach ([
            'vehicleId', 'vehicle', 'vin', 'plate', 'company', 'contactId',
            'contact', 'driverId', 'driver', 'reasonId', 'reasonSubtypeId',
            'mileage', 'slot',
        ] as $key) {
            $this->assertSame('', $first[$key]);
        }

        $this->assertNull($first['previousServiceMileage']);
        $this->assertNull($first['previousServiceAt']);
        $this->assertSame([], $first['works']);
        $this->assertSame([], $first['parts']);

        $this->api('GET', '/requests')->assertJsonCount(2, 'requests');
        $this->api('GET', '/requests/' . $first['number'])
            ->assertOk()
            ->assertJsonPath('request.uuid', $first['id']);
    }

    public function test_customer_identity_and_session_are_separate_from_empty_company(): void
    {
        $request = $this->draft();

        $this->api('GET', '/session')
            ->assertJsonPath('user.organization', 'ООО "Тест"')
            ->assertJsonPath('user.organizationId', $this->actors['customer']->organization->uuid);

        $this->assertSame('', $request['company']);

        $this->actingAs($this->actors['manager']);
        $this->api('POST', '/requests')->assertForbidden();
    }

    public function test_xlsx_import_contains_23_cars_and_is_idempotent(): void
    {
        $importer = app(VehicleImporter::class);
        $file = base_path('../imports/vehicles-test.xlsx');

        $dry = $importer->import($this->actors['customer']->organization, $file, true);
        $this->assertSame(23, $dry['total']);
        $this->assertSame(1, Vehicle::count());

        $result = $importer->import($this->actors['customer']->organization, $file);
        $this->assertSame(23, $result['created']);

        $again = $importer->import($this->actors['customer']->organization, $file);
        $this->assertSame(23, $again['updated']);

        $this->assertSame(24, Vehicle::count());
        $this->assertSame(0, RepairRequest::count());
        $this->assertNull(
            Vehicle::where('vin', 'LVTDB21B4PE905103')->firstOrFail()->baseline_service_mileage,
        );
    }

    public function test_lookup_and_selection_pull_vehicle_data_and_previous_mileage(): void
    {
        $this->vehicle->update(['baseline_service_mileage' => 12000]);

        $request = $this->draft();

        $this->api('GET', '/vehicles/lookup?vin=' . $this->vehicle->vin)
            ->assertOk()
            ->assertJsonPath('vehicle.previousServiceMileage', 12000);

        $this->api('GET', '/vehicles/lookup?plate=A123AA178')
            ->assertOk()
            ->assertJsonPath('vehicle.id', $this->vehicle->uuid);

        $request = $this->patchRequest($request, ['vehicleId' => $this->vehicle->uuid]);
        $this->assertSame($this->vehicle->vin, $request['vin']);
        $this->assertSame(12000, $request['previousServiceMileage']);

        $this->api('PATCH', '/requests/' . $request['id'], [
            'revision' => $request['revision'],
            'patch'    => ['mileage' => '11999'],
        ])->assertUnprocessable();
    }

    public function test_vehicle_contacts_and_previous_request_mileage_are_returned(): void
    {
        $this->actingAs($this->actors['customer']);

        $this->api('GET', '/vehicles/' . $this->vehicle->uuid . '/contacts')
            ->assertOk()
            ->assertJsonPath('contacts.0.id', $this->contact->uuid)
            ->assertJsonPath('contacts.0.fullName', 'Иванов Иван Иванович')
            ->assertJsonPath('contacts.0.phone', '+7 (999) 123 45 67');

        $this->api('GET', '/vehicles/' . $this->vehicle->uuid . '/drivers')
            ->assertOk()
            ->assertJsonPath('drivers.0.id', $this->driver->uuid)
            ->assertJsonPath('drivers.0.phone', '+7 (999) 765 43 21');

        $first = $this->patchRequest($this->draft(), [
            'vehicleId'  => $this->vehicle->uuid,
            'contactId'  => $this->contact->uuid,
            'driverId'   => $this->driver->uuid,
            'reasonId'   => $this->reason->uuid,
            'mileage'    => '15000',
        ]);

        $this->assertSame($this->contact->uuid, $first['contactId']);
        $this->assertSame($this->driver->uuid, $first['driverId']);

        $second = $this->patchRequest($this->draft(), ['vehicleId' => $this->vehicle->uuid]);
        $this->assertSame(15000, $second['previousServiceMileage']);
        $this->assertNotEmpty($second['previousServiceAt']);
    }

    public function test_customer_cannot_add_work_and_revision_conflict_does_not_write(): void
    {
        $request = $this->draft();
        $new = $this->patchRequest($request, ['description' => 'Версия 1']);

        $this->api('PATCH', '/requests/' . $request['id'], [
            'revision' => $request['revision'],
            'patch'    => ['description' => 'Потерянное обновление'],
        ])->assertConflict();

        $this->api('PATCH', '/requests/' . $request['id'], [
            'revision' => $new['revision'],
            'patch'    => ['works' => []],
        ])->assertForbidden();

        $this->api('PATCH', '/requests/' . $request['id'], [
            'revision' => $new['revision'],
            'patch'    => ['stage' => 13],
        ])->assertUnprocessable();

        $this->api('GET', '/requests/' . $request['id'])
            ->assertJsonPath('request.description', 'Версия 1');
    }

    public function test_vehicle_and_request_are_hidden_from_another_customer(): void
    {
        $request = $this->draft();

        $org = Organization::create([
            'tenant_id' => $this->actors['customer']->tenantId(),
            'kind'      => 'customer',
            'name'      => 'Другой заказчик',
        ]);

        $other = User::create([
            'organization_id' => $org->id,
            'role'            => 'customer',
            'login'           => 'other-' . Str::random(8),
            'display_name'    => 'Другой',
            'password'        => 'test-password-long',
            'active'          => true,
        ]);

        $this->actingAs($other);

        $this->api('GET', '/requests/' . $request['id'])->assertNotFound();
        $this->api('GET', '/vehicles/lookup?vin=' . $this->vehicle->vin)->assertNotFound();

        $own = $this->api('POST', '/requests')->assertCreated()->json('request');

        $this->api('PATCH', '/requests/' . $own['id'], [
            'revision' => 0,
            'patch'    => ['vehicleId' => $this->vehicle->uuid],
        ])->assertUnprocessable();
    }

    public function test_station_cannot_view_unassigned_drafts(): void
    {
        $request = $this->draft();

        $this->actingAs($this->actors['station']);

        $this->api('GET', '/requests/' . $request['id'])->assertNotFound();
        $this->api('GET', '/vehicles/' . $this->vehicle->uuid . '/statistics')->assertNotFound();
    }

    public function test_initial_transition_requires_form_fields_but_not_media_or_comment(): void
    {
        $request = $this->draft();

        $this->api('POST', '/requests/' . $request['id'] . '/next', [
            'revision'      => 0,
            'comment'       => '',
            'attachmentIds' => [],
        ])->assertUnprocessable();

        $request = $this->patchRequest($request, [
            'vehicleId' => $this->vehicle->uuid,
            'contactId' => $this->contact->uuid,
            'reasonId'  => $this->reason->uuid,
            'mileage'   => '15000',
        ]);

        $request = $this->api('POST', '/requests/' . $request['id'] . '/next', [
            'revision'      => $request['revision'],
            'comment'       => '',
            'attachmentIds' => [],
        ])->assertOk()->json('request');

        $this->assertSame(1, $request['stage']);
        $this->assertSame(1, RequestApproval::count());
        $this->assertSame(2, RequestStatusPeriod::count());
        $this->assertNotNull(RequestStatusPeriod::where('stage', 0)->first()->exited_at);
        $this->assertSame(RepairStage::Draft, RequestApproval::first()->stage);
        $this->assertSame(ApprovalDecision::Approved, RequestApproval::first()->decision);
        $this->assertSame('', RequestApproval::first()->comment);
        $this->assertGreaterThan(0, RequestEvent::count());
        $this->assertSame([], RepairRequest::first()->history);
    }

    public function test_visit_time_must_be_at_least_next_half_hour_after_thirty_minutes(): void
    {
        $request = $this->draft();

        $serviceTimezone = (string) config('sto.service_timezone');
        $now = \Carbon\CarbonImmutable::now($serviceTimezone);

        // 1. Слишком рано — на 1 минуту до минимума.
        $tooEarly = $now->addMinutes(30)->startOfMinute()->subMinute();

        $this->api('PATCH', '/requests/' . $request['id'], [
            'revision' => $request['revision'],
            'patch'    => ['slot' => $tooEarly->format('Y-m-d\TH:i')],
        ])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_ERROR');

        // 2. Валидно — через час, кратно получасу.
        $valid = $now->addMinutes(60)->startOfMinute();
        $minuteRemainder = (int) $valid->format('i') % 30;
        if ($minuteRemainder !== 0) {
            $valid = $valid->addMinutes(30 - $minuteRemainder);
        }

        if ($valid->format('H:i') < (string) config('sto.visit_time_start')) {
            $valid = $valid->setTime(8, 0);
        }

        if ($valid->format('H:i') > (string) config('sto.visit_time_end')) {
            $valid = $valid->addDay()->setTime(8, 0);
        }

        $this->api('PATCH', '/requests/' . $request['id'], [
            'revision' => $request['revision'],
            'patch'    => ['slot' => $valid->format('Y-m-d\TH:i')],
        ])->assertOk()->assertJsonPath('request.slot', $valid->format('Y-m-d\TH:i'));
    }

    public function test_visit_time_must_be_inside_service_window(): void
    {
        $serviceTimezone = (string) config('sto.service_timezone');
        $date = \Carbon\CarbonImmutable::now($serviceTimezone)
            ->addDays(2)
            ->format('Y-m-d');
        $message = 'Время визита должно быть с 08:00 до 22:00';

        foreach (['07:30', '22:30'] as $time) {
            $request = $this->draft();

            $this->api('PATCH', '/requests/' . $request['id'], [
                'revision' => $request['revision'],
                'patch'    => ['slot' => $date . 'T' . $time],
            ])
                ->assertUnprocessable()
                ->assertJsonPath('error.fields.slot', $message);
        }

        foreach (['08:00', '22:00'] as $time) {
            $request = $this->draft();
            $value = $date . 'T' . $time;

            $this->api('PATCH', '/requests/' . $request['id'], [
                'revision' => $request['revision'],
                'patch'    => ['slot' => $value],
            ])->assertOk()->assertJsonPath('request.slot', $value);
        }
    }

    public function test_station_decline_keeps_request_visible_in_read_only_mode(): void
    {
        $request = $this->stationStage();

        $this->actingAs($this->actors['station']);
        $request = $this->stationDecision($request, 'declined', 'Нет свободного поста');

        $this->assertSame(1, $request['stage']);

        $this->api('GET', '/requests/' . $request['id'])
            ->assertOk()
            ->assertJsonPath('request.permissions.editableFields', [])
            ->assertJsonPath('request.permissions.canAdvance', false);
    }

    public function test_wrong_revision_upload_is_removed_from_storage_and_db(): void
    {
        $request = $this->draft();
        $request = $this->patchRequest($request, ['description' => 'new']);

        $file = UploadedFile::fake()->createWithContent(
            'photo.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6QmcAAAAASUVORK5CYII='),
        );

        $this->call(
            'POST',
            '/api/requests/' . $request['id'] . '/attachments',
            ['revision' => '0', 'purpose' => 'evidence'],
            [],
            ['file' => $file],
            ['HTTP_ACCEPT' => 'application/json'],
        )->assertConflict();

        $this->assertSame(0, Attachment::count());
        $this->assertSame([], Storage::disk('media')->allFiles());
    }

    public function test_manager_can_create_reasons_and_norms_but_customer_cannot(): void
    {
        $this->actingAs($this->actors['manager']);

        $this->api('POST', '/catalogs/reasons', [
            'record' => ['name' => 'Новая причина', 'code' => 'new_reason', 'parentId' => ''],
        ])->assertCreated();

        $work = $this->api('POST', '/catalogs/workTypes', [
            'record' => ['name' => 'Работа ТО', 'isMaintenance' => true],
        ])->assertCreated()->json('record');

        $norm = $this->api('POST', '/catalogs/timeNorms', [
            'record' => [
                'workTypeId'   => $work['id'],
                'hours'        => 0.5,
                'vehicleModel' => 'Модель',
                'source'       => 'Согласованный норматив',
            ],
        ])->assertCreated()->json('record');

        $this->api('PATCH', '/catalogs/' . $norm['id'], [
            'patch' => ['source' => 'Уточнённый источник'],
        ])->assertOk();

        $this->actingAs($this->actors['customer']);
        $this->api('POST', '/catalogs/workTypes', [
            'record' => ['name' => 'Нельзя'],
        ])->assertForbidden();
    }

    public function test_login_requires_trusted_origin_and_returns_uuid_identity(): void
    {
        $user = $this->actors['customer'];
        $body = ['login' => $user->login, 'password' => 'test-password-long'];

        $this->api('POST', '/login', $body)->assertForbidden();

        $this->api('POST', '/login', $body, ['Origin' => 'http://localhost:5173'])
            ->assertOk()
            ->assertJsonPath('user.id', $user->uuid);
    }

    public function test_bearer_token_authenticates_mutations(): void
    {
        $user = $this->actors['customer'];

        $login = $this->api('POST', '/login', [
            'login'    => $user->login,
            'password' => 'test-password-long',
        ], ['Origin' => 'http://localhost:5173'])->assertOk();

        Auth::forgetGuards();

        $this->api('POST', '/requests')->assertUnauthorized();

        $this->api('POST', '/requests', [], [
            'Authorization' => 'Bearer ' . $login->json('token'),
        ])->assertCreated();
    }

    public function test_only_one_token_session_is_active_and_force_login_replaces_it(): void
    {
        $user = $this->actors['customer'];
        $body = ['login' => $user->login, 'password' => 'test-password-long'];

        $first = $this->api('POST', '/login', $body, ['Origin' => 'http://localhost:5173'])
            ->assertOk()
            ->json('token');

        Auth::forgetGuards();

        $draft = $this->api('POST', '/requests', [], [
            'Authorization' => 'Bearer ' . $first,
        ])->assertCreated()->json('request');

        $this->api('POST', '/document-locks', [
            'resourceType' => 'request',
            'resourceKey'  => $draft['id'],
            'label'        => $draft['number'],
        ], ['Authorization' => 'Bearer ' . $first])->assertCreated();

        $this->assertDatabaseCount('document_locks', 1);

        Auth::forgetGuards();

        $this->api('POST', '/login', $body, ['Origin' => 'http://localhost:5173'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'SESSION_ACTIVE');

        $second = $this->api('POST', '/login', [...$body, 'force' => true], [
            'Origin' => 'http://localhost:5173',
        ])->assertOk()->json('token');

        $this->assertDatabaseCount('document_locks', 0);

        Auth::forgetGuards();
        $this->api('GET', '/session', [], ['Authorization' => 'Bearer ' . $first])
            ->assertUnauthorized();

        Auth::forgetGuards();
        $this->api('GET', '/session', [], ['Authorization' => 'Bearer ' . $second])
            ->assertOk();
    }

    public function test_only_author_can_bulk_delete_unsent_drafts(): void
    {
        $first = $this->draft();
        $second = $this->draft();

        $this->assertTrue($first['permissions']['canDeleteDraft']);

        $other = User::create([
            'organization_id' => $this->actors['customer']->organization_id,
            'login'           => $this->actors['customer']->login . '-other',
            'display_name'    => 'Другой кабинет',
            'role'            => 'customer',
            'password'        => 'test-password-long',
            'active'          => true,
        ]);

        $this->actingAs($other);

        $this->api('GET', '/requests')->assertOk()->assertJsonCount(0, 'requests');

        $this->api('POST', '/requests/drafts/delete', ['ids' => [$first['id']]])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'DRAFT_DELETE_FORBIDDEN');

        $this->actingAs($this->actors['customer']);

        $this->api('POST', '/requests/drafts/delete', ['ids' => [$first['id'], $second['id']]])
            ->assertOk()
            ->assertJsonCount(2, 'deletedIds');

        $this->assertDatabaseMissing('repair_requests', ['uuid' => $first['id']]);
        $this->assertDatabaseMissing('repair_requests', ['uuid' => $second['id']]);

        $sent = $this->patchRequest($this->draft(), [
            'vehicleId' => $this->vehicle->uuid,
            'contactId' => $this->contact->uuid,
            'reasonId'  => $this->reason->uuid,
            'mileage'   => '15000',
        ]);

        $sent = $this->api('POST', '/requests/' . $sent['id'] . '/next', [
            'revision'      => $sent['revision'],
            'comment'       => '',
            'attachmentIds' => [],
        ])->assertOk()->json('request');

        $this->assertFalse($sent['permissions']['canDeleteDraft']);

        $this->api('DELETE', '/requests/' . $sent['id'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'DRAFT_DELETE_FORBIDDEN');
    }

    public function test_manager_does_not_see_customer_drafts(): void
    {
        $request = $this->draft();

        $this->actingAs($this->actors['manager']);
        $this->api('GET', '/requests')->assertOk()->assertJsonCount(0, 'requests');

        $this->actingAs($this->actors['customer']);

        $request = $this->patchRequest($request, [
            'vehicleId' => $this->vehicle->uuid,
            'contactId' => $this->contact->uuid,
            'reasonId'  => $this->reason->uuid,
            'mileage'   => '15000',
        ]);

        $this->api('POST', '/requests/' . $request['id'] . '/next', [
            'revision'      => $request['revision'],
            'comment'       => '',
            'attachmentIds' => [],
        ])->assertOk();

        $this->actingAs($this->actors['manager']);
        $this->api('GET', '/requests')->assertOk()->assertJsonCount(1, 'requests');
    }
}
