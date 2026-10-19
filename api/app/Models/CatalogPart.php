<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Общая запчасть контура, редактируемая УК и СТО; сметы хранят отдельные снимки.
 *
 * @property int $tenant_id
 * @property string $name
 * @property string $article
 * @property string $manufacturer
 * @property string $unit
 * @property bool $active
 */
final class CatalogPart extends Entity
{
    protected $with = ['manufacturerRecord', 'supplierRecord', 'creatorOrganization'];

    public function creatorOrganization(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Organization::class, 'created_by_organization_id');
    }

    public function manufacturerRecord(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(PartManufacturer::class, 'part_manufacturer_id');
    }

    public function supplierRecord(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'purchase_price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'created_by_organization_id' => 'integer',
        ];
    }
}
