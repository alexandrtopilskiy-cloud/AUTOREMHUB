<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property bool $is_maintenance
 * @property bool $active
 * @property string $code
 * @property string $group_code
 * @property string $group_name
 * @property CarbonImmutable|null $archived_at
 */
final class WorkType extends Entity
{
    protected static function booted(): void
    {
        parent::booted();

        static::creating(function (self $model): void {
            $group = (string) ($model->group_name ?: 'Прочее');
            $prefix = self::groupCodeFor($group);

            if (empty($model->code)) {
                $lastCode = self::query()
                    ->where('tenant_id', $model->tenant_id)
                    ->where('code', 'like', $prefix . '-%')
                    ->orderByDesc('code')
                    ->value('code');
                $next = $lastCode === null
                    ? 1
                    : ((int) substr((string) $lastCode, 3)) + 1;
                $model->code = sprintf('%s-%05d', $prefix, $next);
            }

            $model->group_code = $model->group_code ?: $prefix;
            $model->group_name = $group;
            $model->catalog_version = $model->catalog_version ?: 'manual';
            $model->source_name = $model->source_name ?: 'Ручное создание';
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_maintenance' => 'boolean',
            'active'         => 'boolean',
            'source_row'     => 'integer',
            'archived_at'    => 'immutable_datetime',
        ];
    }

    public function timeNorms(): HasMany
    {
        return $this->hasMany(TimeNorm::class);
    }

    public function catalogImport(): BelongsTo
    {
        return $this->belongsTo(WorkCatalogImport::class, 'catalog_import_id');
    }

    public static function groupCodeFor(string $group): string
    {
        return match ($group) {
            'Кузов' => 'BD',
            'Салон' => 'IN',
            'Двигатель' => 'EN',
            'Трансмиссия' => 'TR',
            'Шасси' => 'CH',
            'Подвеска' => 'SU',
            'Тормозная система' => 'BR',
            'Рулевое управление' => 'ST',
            'Техническое обслуживание' => 'MT',
            'Регулировочные' => 'AD',
            'Электрика' => 'EL',
            'Шиномонтаж' => 'TY',
            default => 'OT',
        };
    }
}
