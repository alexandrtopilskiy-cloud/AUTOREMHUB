<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Tenant extends Entity
{
    use HasFactory;

    protected $guarded = ['id'];

    public function organizations(): HasMany
    {
        return $this->hasMany(Organization::class);
    }
}
