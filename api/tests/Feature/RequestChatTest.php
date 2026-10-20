<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\RepairStage;
use App\Models\Organization;
use App\Models\RepairRequest;
use App\Models\Station;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Workflow;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RequestChatTest extends TestCase
{
    use DatabaseTransactions;

    private User $customer;
    private User $colleague;
    private User $stationUser;
    private User $manager;
    private User $outsider;
    private RepairRequest $document;
    private Station $station;
    private string $chatUrl;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');

        $tenant = Tenant::query()->create(['name' => 'Тест чата']);
        $customerOrg = $this->createOrganization($tenant, 'customer');
        $stationOrg = $this->createOrganization($tenant, 'station');
        $managerOrg = $this->createOrganization($tenant, 'manager');
        $otherOrg = $this->createOrganization($tenant, 'customer');

        $this->customer = $this->createUser($customerOrg, UserRole::Customer, 'Иванов', 'Иван');
        $this->colleague = $this->createUser($customerOrg, UserRole::Customer, 'Петрова', 'Анна');
        $this->stationUser = $this->createUser($stationOrg, UserRole::Station, 'Сидоров', 'Сергей');
        $this->manager = $this->createUser($managerOrg, UserRole::Manager, 'Кузнецова', 'Ольга');
        $this->outsider = $this->createUser($otherOrg, UserRole::Customer, 'Другой', 'Пользователь');

        $this->station = Station::query()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $stationOrg->id,
            'name' => 'СТО',
        ]);

        $this->document = RepairRequest::query()->create([
            'uuid'                     => (string) Str::uuid(),
            'public_id'                => (string) Str::uuid(),
            'tenant_id'                => $tenant->id,
            'customer_organization_id' => $customerOrg->id,
            'created_by'               => $this->customer->id,
            'data'                     => array_fill_keys(Workflow::TEXT_FIELDS, ''),
            'works'                    => [],
            'history'                  => [],
            'stage'                    => RepairStage::Draft->value,
            'revision'                 => 0,
        ]);

        $this->chatUrl = '/api/requests/' . $this->document->uuid . '/chat';
    }

    public function test_one_chat_is_shared_by_colleagues_and_notifies_unread(): void
    {
        // 1. Создание чата и отправка сообщения коллегой
        $this->actingAs($this->customer)->postJsonObject($this->chatUrl)->assertCreated();
        $this->actingAs($this->colleague)->postJsonObject($this->chatUrl)->assertOk();
        $this->assertDatabaseCount('request_chats', 1);

        $first = $this->actingAs($this->colleague)->postJson($this->chatUrl . '/messages', [
            'text' => 'Проверяю заявку',
        ])->assertCreated()
            ->assertJsonPath('message.sender.name', 'Петрова Анна')
            ->assertJsonPath('message.sender.role', UserRole::Customer->value)
            ->assertJsonPath('message.sender.roleMark', 'З')
            ->json('message');

        // 2. Проверка счетчика непрочитанных
        $this->actingAs($this->customer)->getJson('/api/chats/summary')
            ->assertOk()->assertJsonPath('unreadTotal', 1);
        $this->actingAs($this->colleague)->getJson('/api/chats/summary')
            ->assertJsonPath('unreadTotal', 0);

        // 3. Чтение истории не снимает уведомление, это делает только /read
        $this->actingAs($this->customer)->getJson($this->chatUrl)->assertOk();
        $this->getJson('/api/chats/summary')->assertJsonPath('unreadTotal', 1);

        $this->postJson($this->chatUrl . '/read', ['lastMessageId' => $first['sequence']])->assertOk();
        $this->getJson('/api/chats/summary')->assertJsonPath('unreadTotal', 0);
    }

    public function test_chat_message_sender_data_is_snapshotted(): void
    {
        $this->actingAs($this->customer)->postJsonObject($this->chatUrl)->assertCreated();
        $this->actingAs($this->colleague)->postJson($this->chatUrl . '/messages', ['text' => 'Тест'])->assertCreated();

        // Изменяем фамилию пользователя
        $this->colleague->update(['last_name' => 'Новая']);

        // Имя в сообщении не должно поменяться (снапшот)
        $this->actingAs($this->customer)->getJson($this->chatUrl)
            ->assertJsonPath('messages.0.sender.name', 'Петрова Анна');
    }

    public function test_outsiders_and_unassigned_roles_cannot_access_chat(): void
    {
        $this->actingAs($this->customer)->postJsonObject($this->chatUrl)->assertCreated();

        // Outsider не может читать или писать
        $this->actingAs($this->outsider)->getJson($this->chatUrl)->assertNotFound();
        $this->postJson($this->chatUrl . '/messages', ['text' => 'Нет доступа'])->assertNotFound();

        // Manager и Station не видят черновик
        $this->actingAs($this->manager)->getJson($this->chatUrl)->assertNotFound();
        $this->actingAs($this->stationUser)->getJson($this->chatUrl)->assertNotFound();
    }

    public function test_manager_and_station_can_join_chat_after_routing(): void
    {
        $this->actingAs($this->customer)->postJsonObject($this->chatUrl)->assertCreated();

        // Направляем заявку в УК и на СТО
        $this->document->update([
            'stage' => RepairStage::ManagerRouting->value,
            'assigned_station_id' => $this->station->id
        ]);

        $this->actingAs($this->manager)->getJson($this->chatUrl)->assertOk();
        $this->postJson($this->chatUrl . '/messages', ['text' => 'Проверено УК'])
            ->assertCreated()->assertJsonPath('message.sender.roleMark', 'У');

        $this->actingAs($this->stationUser)->postJson($this->chatUrl . '/messages', [
            'text' => 'Принято СТО',
        ])->assertCreated()->assertJsonPath('message.sender.roleMark', 'С');
    }

    public function test_files_are_private_and_draft_with_chat_cannot_be_deleted(): void
    {
        $this->actingAs($this->customer)->postJsonObject($this->chatUrl)->assertCreated();

        $file = $this->post($this->chatUrl . '/messages', [
            'files' => [UploadedFile::fake()->create('estimate.pdf', 20, 'application/pdf')],
        ])->assertCreated()->assertJsonPath('message.files.0.name', 'estimate.pdf')
            ->json('message.files.0');

        $downloadUrl = '/api/chat-files/' . $file['id'] . '/download';

        // Автор может скачать, посторонний — нет
        $this->get($downloadUrl)->assertOk();
        $this->actingAs($this->outsider)->get($downloadUrl)->assertNotFound();

        // Заявку с чатом нельзя удалить
        $this->actingAs($this->customer)->postJson('/api/requests/drafts/delete', [
            'ids' => [$this->document->uuid],
        ])->assertStatus(409)->assertJsonPath('error.code', 'CHAT_HISTORY_EXISTS');
    }

    public function test_oversized_file_is_rejected(): void
    {
        $this->actingAs($this->customer)->postJsonObject($this->chatUrl)->assertCreated();

        $this->post($this->chatUrl . '/messages', [
            'files' => [UploadedFile::fake()->create('too-large.pdf', 30 * 1024 + 1, 'application/pdf')],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('request_chat_messages', 0);
    }

    /* ─────────────────── Хелперы ─────────────────── */

    private function createOrganization(Tenant $tenant, string $kind): Organization
    {
        return Organization::query()->create([
            'tenant_id' => $tenant->id,
            'kind' => $kind,
            'name' => $kind . '-' . Str::random(7),
        ]);
    }

    private function createUser(Organization $organization, UserRole $role, string $last, string $first): User
    {
        return User::query()->create([
            'organization_id' => $organization->id,
            'login' => 'chat-' . Str::random(12),
            'display_name' => $last . ' ' . $first,
            'last_name' => $last,
            'first_name' => $first,
            'role' => $role->value,
            'password' => 'long-test-password',
            'active' => true,
        ]);
    }
}
