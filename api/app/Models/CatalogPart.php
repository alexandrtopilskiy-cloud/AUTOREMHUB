<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Общая запчасть контура, редактируемая УК и СТО; сметы хранят отдельные снимки.
 *
 * @property int $tenant_id
 * @property string $name
 * @property string $article
 * @property string $manufacturer
 * @property string $unit
 * @property bool $active
 * @property int|null $created_by_organization_id
 *
 * @property-read Organization|null $creatorOrganization
 * @property-read PartManufacturer|null $manufacturerRecord
 * @property-read Supplier|null $supplierRecord
 */
final class CatalogPart extends Entity
{
    protected $with = [
        'manufacturerRecord',
        'supplierRecord',
        'creatorOrganization',
    ];

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

    /** @return BelongsTo<Organization, $this> */
    public function creatorOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'created_by_organization_id');
    }

    /** @return BelongsTo<PartManufacturer, $this> */
    public function manufacturerRecord(): BelongsTo
    {
        return $this->belongsTo(PartManufacturer::class, 'part_manufacturer_id');
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplierRecord(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }
}
