<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;

/** Literal, case-insensitive AND search; each fragment may match any supplied column. */
final class ReferenceSearch
{
    public static function apply(Builder $query, string $search, array $columns): Builder
    {
        $fragments = preg_split('/\s+/u', mb_strtolower(trim($search)), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($fragments as $fragment) {
            $query->where(function (Builder $inner) use ($columns, $fragment): void {
                foreach ($columns as $column) {
                    // Column expressions are supplied by application code, never user input.
                    $inner->orWhereRaw('STRPOS(LOWER(COALESCE(CAST(' . $column . ' AS TEXT), \'\')), ?) > 0', [$fragment]);
                }
            });
        }
        return $query;
    }
}
