<?php

declare(strict_types=1);

namespace App\Models;

final class RecommendationItem extends Entity
{
    protected function casts(): array
    {
        return ['snapshot' => 'array', 'display' => 'array', 'moved_at' => 'immutable_datetime'];
    }
}
