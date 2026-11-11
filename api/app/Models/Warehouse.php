<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Склад организации (УК или СТО).
 *
 * @property int $tenant_id
 * @property int $organization_id
 * @property string $name
 * @property string|null $system_code from_manager — системный «Склад от УК»
 */
final class Warehouse extends Entity
{
    /** Системный склад СТО для ЗЧ, перемещённых со склада УК («ЗЧ от УК»). */
    public const FROM_MANAGER = 'from_manager';
    public const FROM_MANAGER_NAME = 'Склад от УК';

    public function isSystem(): bool
    {
        return $this->system_code !== null;
    }
}
