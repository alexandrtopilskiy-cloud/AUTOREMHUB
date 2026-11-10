<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\OrganizationKind;
use App\Models\Organization;
use App\Models\Station;
use App\Models\User;
use App\Support\TestParticipantRequisites;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\Concerns\CreatesTestData;
use Tests\Feature\Api\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * Агентская скидка СТО: из реквизитов СТО в каждую строку блока 04,
 * в заявке не редактируется, Заказчику не отдаётся.
 */
final class AgentDiscountTest extends TestCase
{
    use DatabaseTransactions, CreatesTestData, InteractsWithApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-20 09:00:00'));
        Storage::fake('media');
        $this->setUpTestData();
    }

    public function test_lines_get_default_discount_hidden_from_customer(): void
    {
        $request = $this->stationStage();

        $this->actingAs($this->actors['station']);
        $request = $this->patchRequest($request, [
            'works' => $this->asSent($request['works']),
            'parts' => [[
                'name' => 'Фильтр', 'article' => 'F-1', 'unit' => 'шт.',
                'supplierId' => $this->supplier->uuid, 'qty' => 2, 'price' => 250,
                'catalogPartId' => $this->catalogPart()->uuid,
            ]],
        ]);

        $work = $request['works'][0];
        $this->assertSame(10.0, (float) $work['agentDiscountPercent']);
        $this->assertSame(10.0, (float) $work['agentDiscountAmount']);
        $this->assertSame(90.0, (float) $work['stationAmount']);

        $part = $request['parts'][0];
        $this->assertSame(10.0, (float) $part['agentDiscountPercent']);
        $this->assertSame(50.0, (float) $part['agentDiscountAmount']);

        $this->assertSame(10.0, (float) $request['agentDiscount']['percent']);
        $this->assertSame(60.0, (float) $request['agentDiscount']['amount']);
        $this->assertSame(540.0, (float) $request['agentDiscount']['stationAmount']);

        $this->actingAs($this->actors['manager']);
        $manager = $this->api('GET', "/requests/{$request['id']}")->assertOk()->json('request');
        $this->assertSame(10.0, (float) $manager['works'][0]['agentDiscountPercent']);

        $this->actingAs($this->actors['customer']);
        $customer = $this->api('GET', "/requests/{$request['id']}")->assertOk()->json('request');
        $this->assertArrayNotHasKey('agentDiscount', $customer);
        foreach ([...$customer['works'], ...$customer['parts']] as $line) {
            $this->assertArrayNotHasKey('agentDiscountPercent', $line);
            $this->assertArrayNotHasKey('agentDiscountAmount', $line);
            $this->assertArrayNotHasKey('stationAmount', $line);
        }
    }

    public function test_discount_comes_from_station_and_ignores_client_value(): void
    {
        $this->station->forceFill(['agent_discount_percent' => 15])->save();
        $request = $this->stationStage();
        $this->assertSame(15.0, (float) $request['works'][0]['agentDiscountPercent']);

        $this->actingAs($this->actors['station']);
        $works = $this->asSent($request['works']);
        $works[0]['agentDiscountPercent'] = 50;
        $request = $this->patchRequest($request, ['works' => $works]);

        $this->assertSame(15.0, (float) $request['works'][0]['agentDiscountPercent']);
        $this->assertSame(15.0, (float) $request['works'][0]['agentDiscountAmount']);
    }

    public function test_test_participants_get_full_requisites_without_overwriting(): void
    {
        $manager = Organization::create([
            'tenant_id' => $this->tenant->id, 'kind' => OrganizationKind::Manager, 'name' => 'УК тест',
        ]);
        User::create([
            'organization_id' => $manager->id, 'login' => TestParticipantRequisites::MANAGER_LOGIN,
            'display_name' => 'УК', 'role' => 'manager', 'password' => 'test-password-long', 'active' => true,
        ]);
        $alfa = Organization::create([
            'tenant_id' => $this->tenant->id, 'kind' => OrganizationKind::Customer,
            'name' => 'ООО "Альфа"', 'inn' => '7700000001',
        ]);
        $kubOrganization = Organization::create([
            'tenant_id' => $this->tenant->id, 'kind' => OrganizationKind::Station, 'name' => 'ООО "КУБ"',
        ]);
        $kub = Station::create([
            'tenant_id' => $this->tenant->id, 'organization_id' => $kubOrganization->id,
            'name' => 'ООО "КУБ"', 'profile' => ['lifts' => 2],
        ]);

        $changed = TestParticipantRequisites::apply($this->tenant->id);

        $this->assertContains('ООО "Альфа"', $changed);
        $this->assertSame('7700000001', $alfa->refresh()->inn, 'ручное значение не перезаписано');
        $this->assertSame('044525593', $alfa->bik);
        $this->assertSame('7700133898', $manager->refresh()->inn);
        $this->assertSame('7756413953', $kubOrganization->refresh()->inn);

        $kub->refresh();
        $this->assertSame(2, $kub->profile['lifts']);
        $this->assertTrue($kub->profile['engineRepair']);
        $this->assertFalse($kub->profile['trucks']);
        $this->assertSame(10.0, (float) $kub->agent_discount_percent);
        $this->assertNotSame('', $kub->address);

        $this->assertSame([], TestParticipantRequisites::apply($this->tenant->id), 'повторный запуск ничего не меняет');

        // Заказчик «ООО "Тест"» и СТО «СТО-партнёр» (кавычки «ёлочки», регистр).
        $partnerOrganization = Organization::create([
            'tenant_id' => $this->tenant->id, 'kind' => OrganizationKind::Station, 'name' => 'Партнёр',
        ]);
        Station::create([
            'tenant_id' => $this->tenant->id, 'organization_id' => $partnerOrganization->id, 'name' => 'сто-партнёр',
        ]);
        Organization::query()->whereKey($this->actors['customer']->organization_id)->update(['name' => 'ООО «Тест»']);

        TestParticipantRequisites::apply($this->tenant->id);

        $this->assertSame('7852601815', Organization::query()->find($this->actors['customer']->organization_id)->inn);
        $this->assertSame('7790996039', $partnerOrganization->refresh()->inn);
    }

    /** Front не отправляет служебную подпись применимости (см. stripClientMetadata). */
    private function asSent(array $works): array
    {
        return array_map(static fn (array $work): array => \Illuminate\Support\Arr::except($work, ['normApplicabilityLabel']), $works);
    }

    private function catalogPart(): \App\Models\CatalogPart
    {
        return \App\Models\CatalogPart::query()->create([
            'tenant_id' => $this->tenant->id, 'name' => 'Фильтр', 'article' => 'F-1',
            'unit' => 'шт.', 'active' => true,
        ]);
    }
}
