<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Базовая модель сущностей с авто-генерацией UUID.
 *
 * @property int $id
 * @property string $uuid
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
abstract class Entity extends Model
{
    /**
     * Поля, недоступные для массового присваивания.
     *
     * @var array<int, string>
     */
    protected $guarded = ['id', 'number'];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if ($model->uuid === null || $model->uuid === '') {
                $model->uuid = (string) Str::uuid();
            }
        });
    }
}
