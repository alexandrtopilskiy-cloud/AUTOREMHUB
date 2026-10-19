<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Базовая модель сущностей с авто-генерацией UUID.
 *
 * @property string $uuid
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