<?php

declare(strict_types=1);

namespace Tests\Feature\Edo;

use App\Enums\Edo\EdoDocumentStatus;
use App\Enums\Edo\EdoDocumentType;
use App\Enums\Edo\EdoProvider;
use App\Models\EdoDocument;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Edo\Drivers\Sbis\SbisChangesPuller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Подписание УКЕП документа СБИС (ПодготовитьДействие → подпись в браузере →
 * ВыполнитьДействие) и получение входящего у получателя (СписокИзменений).
 */
final class SbisSigningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['edo.signing' => 'cryptopro']);
    }

    public function test_stub_mode_checks_request_in_sbis_without_signing(): void
    {
        config(['edo.signing' => 'stub']);
        [$sender, $receiver, $senderUser] = $this->participants();
        $document = $this->document($sender, $receiver);

        Http::fake(fn (HttpRequest $request) => match ($request['method']) {
            'СБИС.ПодготовитьДействие' => Http::response(['jsonrpc' => '2.0', 'result' => [
                'Этап' => [[
                    'Идентификатор' => 'stage-1',
                    'Название'      => 'Отправка',
                    'Действие'      => [['Название' => 'Отправить']],
                    'Вложение'      => [['Идентификатор' => 'att-1', 'Файл' => ['Имя' => 'ZN-000001-1.pdf', 'ДвоичныеДанные' => 'MTIz']]],
                ]],
            ]]),
            default => Http::response(['jsonrpc' => '2.0', 'error' => ['message' => 'unexpected ' . $request['method']]], 500),
        });

        $this->actingAs($senderUser)->postJson("/api/edo/documents/{$document->uuid}/sbis/prepare")
            ->assertOk()
            ->assertJsonPath('stub', true)
            ->assertJsonPath('check.action', 'Отправить')
            ->assertJsonPath('check.attachments.0.name', 'ZN-000001-1.pdf')
            ->assertJsonPath('document.status', 'draft')
            ->assertJsonPath('document.signLabel', 'Проверить в СБИС')
            ->assertJsonPath('document.signCheck.action', 'Отправить');

        Http::assertNotSent(fn (HttpRequest $request) => $request['method'] === 'СБИС.ВыполнитьДействие');
        $this->assertArrayNotHasKey('sign', $document->fresh()->meta);

        $this->actingAs($senderUser)
            ->postJson("/api/edo/documents/{$document->uuid}/sbis/execute", ['signatures' => [['id' => 'att-1', 'signature' => 'U0lH']]])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'EDO_SIGNING_STUB');
    }

    public function test_sender_prepares_signs_and_sends_document(): void
    {
        [$sender, $receiver, $senderUser] = $this->participants();
        $document = $this->document($sender, $receiver);

        Http::fake(fn (HttpRequest $request) => match ($request['method']) {
            'СБИС.ПодготовитьДействие' => Http::response(['jsonrpc' => '2.0', 'result' => [
                'Идентификатор' => 'sbis-1',
                'Этап' => [[
                    'Идентификатор' => 'stage-1',
                    'Название'      => 'Отправка',
                    'Действие'      => [['Название' => 'Отправить']],
                    'Вложение'      => [['Идентификатор' => 'att-1', 'Файл' => ['Имя' => 'ZN-000001-1.pdf', 'ДвоичныеДанные' => 'MTIz']]],
                ]],
            ]]),
            'СБИС.ВыполнитьДействие' => Http::response(['jsonrpc' => '2.0', 'result' => [
                'Идентификатор' => 'sbis-1',
                'Состояние'     => ['Код' => '2', 'Название' => 'Отправлен'],
            ]]),
            default => Http::response(['jsonrpc' => '2.0', 'error' => ['message' => 'unexpected ' . $request['method']]], 500),
        });

        $prepared = $this->actingAs($senderUser)->postJson("/api/edo/documents/{$document->uuid}/sbis/prepare");
        $prepared->assertOk()
            ->assertJsonPath('side', 'sender')
            ->assertJsonPath('action', 'Отправить')
            ->assertJsonPath('attachments.0.id', 'att-1')
            ->assertJsonPath('attachments.0.content', 'MTIz');

        $this->actingAs($senderUser)
            ->postJson("/api/edo/documents/{$document->uuid}/sbis/execute", ['signatures' => [['id' => 'att-1', 'signature' => 'U0lH']]])
            ->assertOk()
            ->assertJsonPath('data.status', 'sended')
            ->assertJsonPath('data.expectedAction', 'Ожидается подпись получателя')
            ->assertJsonPath('data.canSign', false);

        Http::assertSent(fn (HttpRequest $request) => $request['method'] === 'СБИС.ВыполнитьДействие'
            && $request['params']['Документ']['Идентификатор'] === 'sbis-1'
            && $request['params']['Документ']['Этап']['Идентификатор'] === 'stage-1'
            && $request['params']['Документ']['Этап']['Вложение'][0]['Подпись'][0]['Файл']['ДвоичныеДанные'] === 'U0lH');
        $this->assertNotNull($document->fresh()->sent_at);
    }

    public function test_execute_requires_signature_of_every_prepared_attachment(): void
    {
        [$sender, $receiver, $senderUser] = $this->participants();
        $document = $this->document($sender, $receiver, ['sign' => [
            'side' => 'sender', 'documentId' => 'sbis-1', 'stageId' => 's', 'stageName' => 'Отправка',
            'action' => 'Отправить', 'attachments' => ['att-1', 'att-2'],
        ]]);
        Http::fake();

        $this->actingAs($senderUser)
            ->postJson("/api/edo/documents/{$document->uuid}/sbis/execute", ['signatures' => [['id' => 'att-1', 'signature' => 'U0lH']]])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'EDO_SIGNATURE_MISSING');
        Http::assertNothingSent();
    }

    public function test_receiver_gets_incoming_document_from_changes_and_can_sign_it(): void
    {
        [$sender, $receiver, , $receiverUser] = $this->participants();
        $document = $this->document($sender, $receiver, [], EdoDocumentStatus::Sended);

        Http::fake(fn (HttpRequest $request) => match ($request['method']) {
            'СБИС.СписокИзменений' => Http::response(['jsonrpc' => '2.0', 'result' => [
                'Документ' => [[
                    'Идентификатор' => 'receiver-sbis-1',
                    'Номер'         => 'ZN-000001/1',
                    'Направление'   => 'Входящий',
                    'Состояние'     => ['Код' => '3', 'Название' => 'Требует утверждения'],
                    'Событие'       => [['Идентификатор' => 'event-1', 'Название' => 'Получение', 'ДатаВремя' => '16.11.2026 10.00.00']],
                ]],
                'Навигация' => ['ЕстьЕще' => 'Нет'],
            ]]),
            'СБИС.ПрочитатьДокумент' => Http::response(['jsonrpc' => '2.0', 'result' => [
                'Этап' => [[
                    'Идентификатор' => 'stage-r',
                    'Название'      => 'Утверждение',
                    'Действие'      => [['Название' => 'Отклонить'], ['Название' => 'Утвердить']],
                ]],
            ]]),
            'СБИС.ПодготовитьДействие' => Http::response(['jsonrpc' => '2.0', 'result' => [
                'Этап' => [[
                    'Идентификатор' => 'stage-r',
                    'Название'      => 'Утверждение',
                    'Действие'      => [['Название' => 'Утвердить']],
                    'Вложение'      => [['Идентификатор' => 'att-r', 'Файл' => ['Имя' => 'receipt.xml', 'ДвоичныеДанные' => 'UkVD']]],
                ]],
            ]]),
            default => Http::response(['jsonrpc' => '2.0', 'error' => ['message' => 'unexpected']], 500),
        });

        $result = app(SbisChangesPuller::class)->pull($receiver);
        $this->assertSame(['documents' => 1, 'matched' => 1], $result);

        $document->refresh();
        $this->assertSame('receiver-sbis-1', $document->meta['receiver_external_id']);
        $this->assertSame(EdoDocumentStatus::Received, $document->status);

        $this->actingAs($receiverUser)->getJson('/api/edo/documents')
            ->assertOk()
            ->assertJsonPath('documents.0.direction', 'incoming')
            ->assertJsonPath('documents.0.canSign', true)
            ->assertJsonPath('documents.0.expectedAction', 'Подписать (получатель)');

        $this->actingAs($receiverUser)->postJson("/api/edo/documents/{$document->uuid}/sbis/prepare")
            ->assertOk()
            ->assertJsonPath('side', 'receiver')
            ->assertJsonPath('action', 'Утвердить')
            ->assertJsonPath('attachments.0.id', 'att-r');

        Http::assertSent(fn (HttpRequest $request) => $request['method'] === 'СБИС.ПодготовитьДействие'
            && $request['params']['Документ']['Идентификатор'] === 'receiver-sbis-1'
            && $request['params']['Документ']['Этап']['Действие'][0]['Название'] === 'Утвердить');
    }

    public function test_paper_copy_renders_pdf_with_sbis_info(): void
    {
        config(['edo.mock' => false]);
        [$sender, $receiver, $senderUser] = $this->participants();
        $document = $this->document($sender, $receiver, [], EdoDocumentStatus::Signed);

        Http::fake(fn (HttpRequest $request) => match ($request['method']) {
            'СБИС.ПрочитатьДокумент' => Http::response(['jsonrpc' => '2.0', 'result' => [
                'Название'  => 'Заказ-наряд ZN-000001/1',
                'Номер'     => 'ZN-000001/1',
                'Дата'      => '16.11.2026',
                'Сумма'     => '1200.00',
                'Состояние' => ['Код' => '7', 'Название' => 'Выполнение завершено успешно'],
                'Событие'   => [['ДатаВремя' => '16.11.2026 10.00.00', 'Название' => 'Отправка']],
                'Вложение'  => [[
                    'Служебный' => 'Нет',
                    'Файл'      => ['Имя' => 'ZN-000001-1.pdf'],
                    'Подпись'   => [['Сертификат' => ['ФИО' => 'Иванов Иван Иванович', 'Должность' => 'Директор', 'Название' => 'СТО', 'ИНН' => '7713026678']]],
                ]],
            ]]),
            default => Http::response(['jsonrpc' => '2.0', 'error' => ['message' => 'unexpected']], 500),
        });

        $response = $this->actingAs($senderUser)->get("/api/edo/documents/{$document->uuid}/paper?download=1");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());

        $read = $document->fresh()->meta['sbis_read'];
        $this->assertSame('Иванов Иван Иванович', $read['signatures'][0]['owner']);
        $this->assertSame('Выполнение завершено успешно', $read['state']);
    }

    public function test_paper_copy_works_without_sbis(): void
    {
        [$sender, $receiver, , $receiverUser] = $this->participants();
        $document = $this->document($sender, $receiver, [], EdoDocumentStatus::Sended);
        Http::fake();

        $response = $this->actingAs($receiverUser)->get("/api/edo/documents/{$document->uuid}/paper");

        $response->assertOk();
        $this->assertStringStartsWith('inline;', (string) $response->headers->get('Content-Disposition'));
        Http::assertNothingSent();
    }

    public function test_receiver_does_not_see_sender_draft(): void
    {
        [$sender, $receiver, , $receiverUser] = $this->participants();
        $this->document($sender, $receiver);

        $this->actingAs($receiverUser)->getJson('/api/edo/documents')
            ->assertOk()
            ->assertJsonCount(0, 'documents');
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function document(Organization $sender, Organization $receiver, array $meta = [], EdoDocumentStatus $status = EdoDocumentStatus::Draft): EdoDocument
    {
        return EdoDocument::query()->create([
            'tenant_id'       => $sender->tenant_id,
            'organization_id' => $sender->id,
            'counterparty_id' => $receiver->id,
            'document_type'   => EdoDocumentType::WorkOrder->value,
            'status'          => $status->value,
            'provider'        => EdoProvider::Sbis->value,
            'external_id'     => 'sbis-1',
            'number'          => 'ZN-000001/1',
            'document_date'   => now()->format('Y-m-d'),
            'meta'            => ['state' => 'written', ...$meta],
        ]);
    }

    /**
     * @return array{0: Organization, 1: Organization, 2: User, 3: User}
     */
    private function participants(): array
    {
        $tenant = Tenant::query()->create(['name' => 'Test Tenant']);
        $sender = $this->organization($tenant, 'station', 'СТО', '7713026678');
        $receiver = $this->organization($tenant, 'manager', 'УК', '7701234567');

        return [$sender, $receiver, $this->user($sender, 'station'), $this->user($receiver, 'manager')];
    }

    private function organization(Tenant $tenant, string $kind, string $name, string $inn): Organization
    {
        $organization = Organization::query()->create([
            'tenant_id' => $tenant->id, 'kind' => $kind,
            'name' => $name, 'short_name' => $name,
            'inn' => $inn, 'kpp' => '771301001', 'ogrn' => '1157746123456',
            'contact' => 'Test', 'legal_address' => 'Moscow',
            'actual_address' => 'Moscow', 'postal_address' => 'Moscow',
            'director_name' => 'Ivanov', 'phone' => '+7', 'email' => strtolower($kind) . '@e.com',
            'bank_name' => 'T', 'bik' => '044525225',
            'settlement_account' => '40817810000000000001',
            'correspondent_account' => '30101810400000000225',
            'tax_regime' => 'osn', 'vat_rate' => 20.00,
        ]);

        $organization->forceFill([
            'edo_provider'         => EdoProvider::Sbis,
            'edo_client_id'        => 'login-' . $kind,
            'edo_refresh_token'    => 'password',
            'edo_token'            => 'session-' . $kind,
            'edo_token_expires_at' => now()->addHour(),
            'edo_edi_code'         => '2BE-' . $inn,
        ])->save();

        return $organization;
    }

    private function user(Organization $organization, string $role): User
    {
        return User::query()->create([
            'organization_id' => $organization->id,
            'login'           => $role . '_' . uniqid(),
            'display_name'    => $role,
            'role'            => $role,
            'password'        => bcrypt('x'),
            'active'          => true,
        ]);
    }
}
