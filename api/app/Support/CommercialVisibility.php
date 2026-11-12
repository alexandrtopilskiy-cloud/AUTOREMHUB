<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\UserRole;

final class CommercialVisibility
{
    private const PRIVATE_KEYS = [
        'supplier',
        'supplierId',
        'supplierName',
        'supplier_id',
        'supplier_name',
        'purchasePrice',
        'purchase_price',
        'supplier_name_snapshot',
    ];

    public static function forUser(array $data, UserRole $role): array
    {
        if ($role !== UserRole::Customer) {
            return $data;
        }
        return self::redact($data);
    }

    private static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array($key, self::PRIVATE_KEYS, true)) {
                unset($data[$key]);
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }
        return $data;
    }
}
