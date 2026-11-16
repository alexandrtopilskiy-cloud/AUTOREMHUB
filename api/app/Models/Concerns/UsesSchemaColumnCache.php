<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\SchemaColumnCache;

/**
 * Проверка $guarded по списку колонок из кэша схемы вместо запроса
 * к information_schema в каждом запросе (см. SchemaColumnCache).
 */
trait UsesSchemaColumnCache
{
    /**
     * @param string $key
     */
    protected function isGuardableColumn($key): bool
    {
        $columns = SchemaColumnCache::columns($this->getTable());

        if ($columns === null) {
            return parent::isGuardableColumn($key);
        }

        if ($this->hasSetMutator($key) || $this->hasAttributeSetMutator($key) || $this->isClassCastable($key)) {
            return true;
        }

        return in_array($key, $columns, true);
    }
}
