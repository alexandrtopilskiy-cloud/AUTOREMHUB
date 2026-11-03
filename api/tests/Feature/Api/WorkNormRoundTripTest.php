<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\TimeNorm;
use App\Models\WorkType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\Concerns\CreatesTestData;
use Tests\Feature\Api\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * Строки работ, полученные из API, должны сохраняться повторно без изменений:
 * Front отправляет всю таблицу работ целиком при добавлении новых строк.
 */
final class WorkNormRoundTripTest extends TestCase
{
    use DatabaseTransactions, CreatesTestData, InteractsWithApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-20 09:00:00'));
        Storage::fake('media');
        $this->setUpTestData();
    }

    public function test_station_adds_manual_works_to_catalog_norm_rows(): void
    {
        $oil = WorkType::create([
            'tenant_id'  => $this->tenant->id,
            'name'       => 'Замена масла',
            'group_name' => 'Прочее',
            'active'     => true,
        ]);
        $norm = TimeNorm::query()->create([
            'tenant_id'    => $this->tenant->id,
            'work_type_id' => $oil->id,
            'hours'        => 1.25,
            'source'       => '',
            'active'       => true,
        ]);
        $cabin = WorkType::create([
            'tenant_id'  => $this->tenant->id,
            'name'       => 'Салонный фильтр - замена',
            'group_name' => 'Прочее',
            'active'     => true,
        ]);

        $request = $this->stationStage();

        $this->actingAs($this->actors['station']);

        $request = $this->patchRequest($request, [
            'works' => [...$this->asSent($request['works']), [
                'name' => $oil->name, 'type' => 'Работа', 'workTypeId' => $oil->uuid,
                'timeNormId' => $norm->uuid, 'qty' => 1, 'normHours' => 1.25,
            ]],
        ]);

        // Повторное чтение отдаёт идентификаторы вида работы и нормы.
        $request = $this->api('GET', "/requests/{$request['id']}")->assertOk()->json('request');
        $oilRow = collect($request['works'])->firstWhere('name', $oil->name);
        $this->assertSame($oil->uuid, $oilRow['workTypeId']);
        $this->assertSame($norm->uuid, $oilRow['timeNormId']);
        $this->assertFalse($oilRow['normIsManual']);

        // Добавление ручной работы к строкам «как пришли из API» сохраняется.
        $request = $this->patchRequest($request, [
            'works' => [...$this->asSent($request['works']), [
                'name' => $cabin->name, 'type' => 'Работа', 'workTypeId' => $cabin->uuid,
                'timeNormId' => '', 'qty' => 1, 'price' => null, 'normHours' => 0.5,
                'normIsManual' => true, 'normOverrideReason' => 'Нет нормы в справочнике',
            ]],
        ]);

        $this->assertCount(3, $request['works']);
        $this->assertFalse(collect($request['works'])->firstWhere('name', $oil->name)['normIsManual']);
        $this->assertTrue(collect($request['works'])->firstWhere('name', $cabin->name)['normIsManual']);
    }

    /** Front не отправляет служебную подпись применимости (см. stripClientMetadata). */
    private function asSent(array $works): array
    {
        return array_map(static fn (array $work): array => Arr::except($work, ['normApplicabilityLabel']), $works);
    }

    public function test_manual_norm_error_names_the_work(): void
    {
        $request = $this->stationStage();
        $this->actingAs($this->actors['station']);

        $this->api('PATCH', "/requests/{$request['id']}", [
            'revision' => $request['revision'],
            'works'    => [[
                'name' => 'Диагностика', 'type' => 'Работа', 'workTypeId' => $this->work->uuid,
                'qty' => 1, 'normHours' => 1, 'normIsManual' => true, 'normOverrideReason' => '',
            ]],
        ])->assertUnprocessable()->assertJsonPath(
            'error.message',
            'Для ручной нормы укажите положительное значение и причину корректировки (работа «Диагностика»)',
        );
    }
}
