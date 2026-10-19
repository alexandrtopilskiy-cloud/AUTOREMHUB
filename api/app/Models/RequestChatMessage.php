<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property UserRole $sender_role
 */
final class RequestChatMessage extends Entity
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'sender_role' => UserRole::class,
            'created_at'  => 'immutable_datetime',
        ];
    }

    public function files(): HasMany
    {
        return $this->hasMany(RequestChatFile::class);
    }
}
