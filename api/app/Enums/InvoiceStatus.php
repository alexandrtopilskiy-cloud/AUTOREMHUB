<?php

declare(strict_types=1);

namespace App\Enums;

enum InvoiceStatus: string
{
    case Issued = 'issued';
    case Submitted = 'submitted';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Issued => 'Ожидает оплаты',
            self::Submitted => 'Оплата передана на проверку',
            self::Paid => 'Оплата проверена УК',
        };
    }

    public function isIssued(): bool
    {
        return $this === self::Issued;
    }

    public function isSubmitted(): bool
    {
        return $this === self::Submitted;
    }

    public function isPaid(): bool
    {
        return $this === self::Paid;
    }

    /**
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Issued => [self::Submitted],
            self::Submitted => [self::Issued, self::Paid],
            self::Paid => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
