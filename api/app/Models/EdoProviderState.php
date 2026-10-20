<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Edo\EdoProvider as EdoProviderEnum;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property EdoProviderEnum $provider
 * @property int $last_event_id
 * @property CarbonImmutable|null $last_pulled_at
 * @property array<string, mixed> $meta
 */
final class EdoProviderState extends Model
{
    protected $table = 'edo_provider_state';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider'       => EdoProviderEnum::class,
            'last_event_id'  => 'integer',
            'last_pulled_at' => 'immutable_datetime',
            'meta'           => 'array',
        ];
    }

    public static function forProvider(EdoProviderEnum $provider): self
    {
        return self::query()->firstOrCreate(
            ['provider' => $provider->value],
            ['last_event_id' => 0, 'meta' => []],
        );
    }
}
