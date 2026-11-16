<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\StockMovement;
use App\Models\Tenant;
use App\Support\SchemaColumnCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Кэш колонок (sto:schema-cache): модели с $guarded проверяют поля по файлу,
 * а не запросом к метаданным PostgreSQL в каждом запросе.
 */
final class SchemaColumnCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        File::delete(SchemaColumnCache::path());
        SchemaColumnCache::forget();

        parent::tearDown();
    }

    public function test_command_writes_columns_of_all_tables(): void
    {
        $this->artisan('sto:schema-cache')->assertSuccessful();

        $this->assertFileExists(SchemaColumnCache::path());
        $this->assertContains('repair_request_id', SchemaColumnCache::columns('attachments') ?? []);
        $this->assertContains('catalog_part_id', SchemaColumnCache::columns('stock_movements') ?? []);
        $this->assertNull(SchemaColumnCache::columns('no_such_table'));

        $this->artisan('sto:schema-cache', ['--clear' => true])->assertSuccessful();
        $this->assertFileDoesNotExist(SchemaColumnCache::path());
    }

    public function test_guarded_models_fill_without_schema_queries(): void
    {
        $this->artisan('sto:schema-cache')->assertSuccessful();
        $this->resetGuardableColumns();

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $tenant = (new Tenant())->fill(['name' => 'Кэш', 'id' => 999, 'no_such_column' => 'x']);
        $movement = (new StockMovement())->fill(['quantity' => 2, 'id' => 5]);

        $this->assertSame('Кэш', $tenant->name);
        $this->assertNull($tenant->getAttribute('id'), 'id остаётся защищённым');
        $this->assertArrayNotHasKey('no_such_column', $tenant->getAttributes());
        $this->assertEquals(2, $movement->quantity);
        $this->assertNull($movement->getAttribute('id'));
        $this->assertSame([], array_filter($queries, fn (string $sql) => str_contains($sql, 'attname')));
    }

    private function resetGuardableColumns(): void
    {
        $property = new \ReflectionProperty(\Illuminate\Database\Eloquent\Model::class, 'guardableColumns');
        $property->setValue(null, []);
    }
}
