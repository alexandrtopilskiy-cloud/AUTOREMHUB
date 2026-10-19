<?php

declare(strict_types=1);

namespace Tests\Feature;

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

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');

        $tenant = Tenant::query()->create(['name' => 'Тест чата']);
        $customerOrg = $this->organization($tenant, 'customer');
        $stationOrg = $this->organization($tenant, 'station');
        $managerOrg = $this->organization($tenant, 'manager');
        $otherOrg = $this->organization($tenant, 'customer');

        $this->customer = $this->user($customerOrg, 'customer', 'Иванов', 'Иван');
        $this->colleague = $this->user($customerOrg, 'customer', 'Петрова', 'Анна');
        $this->stationUser = $this->user($stationOrg, 'station', 'Сидоров', 'Сергей');
        $this->manager = $this->user($managerOrg, 'manager', 'Кузнецова', 'Ольга');
        $this->outsider = $this->user($otherOrg, 'customer', 'Другой', 'Пользователь');

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
            'stage'                    => 0,
            'revision'                 => 0,
        ]);
    }

    public function test_one_chat_is_shared_by_all_users_of_participating_organizations(): void
    {
        $url = '/api/requests/' . $this->document->uuid . '/chat';

        $this->actingAs($this->customer)->postJsonObject($url)->assertCreated();
        $this->actingAs($this->colleague)->postJsonObject($url)->assertOk();
        $this->assertDatabaseCount('request_chats', 1);

        $first = $this->actingAs($this->colleague)->postJson($url . '/messages', [
            'text' => 'Проверяю заявку',
        ])->assertCreated()
            ->assertJsonPath('message.sender.name', 'Петрова Анна')
            ->assertJsonPath('message.sender.role', 'customer')
            ->assertJsonPath('message.sender.roleMark', 'З')
            ->json('message');

        $this->actingAs($this->customer)->getJson('/api/chats/summary')
            ->assertOk()->assertJsonPath('unreadTotal', 1);
        $this->actingAs($this->colleague)->getJson('/api/chats/summary')
            ->assertJsonPath('unreadTotal', 0);

        // Загрузка истории не снимает уведомление; это делает только read в открытом чате.
        $this->actingAs($this->customer)->getJson($url)
            ->assertOk()->assertJsonPath('messages.0.sender.name', 'Петрова Анна');
        $this->getJson('/api/chats/summary')->assertJsonPath('unreadTotal', 1);
        $this->postJson($url . '/read', ['lastMessageId' => $first['sequence']])->assertOk();
        $this->getJson('/api/chats/summary')->assertJsonPath('unreadTotal', 0);

        $this->colleague->update(['last_name' => 'Новая']);
        $this->getJson($url)->assertJsonPath('messages.0.sender.name', 'Петрова Анна');

        $this->actingAs($this->outsider)->getJson($url)->assertNotFound();
        $this->postJson($url . '/messages', ['text' => 'Нет доступа'])->assertNotFound();
        $this->actingAs($this->manager)->getJson($url)->assertNotFound();

        $this->document->update(['stage' => 1, 'assigned_station_id' => $this->station->id]);
        $this->actingAs($this->manager)->getJson($url)->assertOk();
        $this->postJson($url . '/messages', ['text' => 'Проверено УК'])
            ->assertCreated()->assertJsonPath('message.sender.roleMark', 'У');
        $this->actingAs($this->stationUser)->postJson($url . '/messages', [
            'text' => 'Принято СТО',
        ])->assertCreated()->assertJsonPath('message.sender.roleMark', 'С');
        $this->actingAs($this->outsider)->getJson('/api/chats/summary')
            ->assertJsonPath('unreadTotal', 0);
    }

    public function test_files_are_private_and_draft_with_chat_cannot_be_deleted(): void
    {
        $url = '/api/requests/' . $this->document->uuid . '/chat';

        $this->actingAs($this->customer)->postJsonObject($url)->assertCreated();
        $file = $this->post($url . '/messages', [
            'files' => [UploadedFile::fake()->create('estimate.pdf', 20, 'application/pdf')],
        ])->assertCreated()->assertJsonPath('message.files.0.name', 'estimate.pdf')
            ->json('message.files.0');

        $download = '/api/chat-files/' . $file['id'] . '/download';
        $this->get($download)->assertOk();
        $this->actingAs($this->outsider)->get($download)->assertNotFound();
        $this->actingAs($this->customer)->postJson('/api/requests/drafts/delete', [
            'ids' => [$this->document->uuid],
        ])->assertStatus(409)->assertJsonPath('error.code', 'CHAT_HISTORY_EXISTS');

        $this->post($url . '/messages', [
            'files' => [UploadedFile::fake()->create('too-large.pdf', 30 * 1024 + 1, 'application/pdf')],
        ])->assertUnprocessable();
        $this->assertDatabaseCount('request_chat_messages', 1);
    }

    private function organization(Tenant $tenant, string $kind): Organization
    {
        return Organization::query()->create([
            'tenant_id' => $tenant->id,
            'kind' => $kind,
            'name' => $kind . '-' . Str::random(7),
        ]);
    }

    private function user(Organization $organization, string $role, string $last, string $first): User
    {
        return User::query()->create([
            'organization_id' => $organization->id,
            'login' => 'chat-' . Str::random(12),
            'display_name' => $last . ' ' . $first,
            'last_name' => $last,
            'first_name' => $first,
            'role' => $role,
            'password' => 'long-test-password',
            'active' => true,
        ]);
    }
}
