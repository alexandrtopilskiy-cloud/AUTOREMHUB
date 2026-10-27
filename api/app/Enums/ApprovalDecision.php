<?php

declare(strict_types=1);

namespace App\Enums;

enum ApprovalDecision: string
{
    case Approved = 'approved';
    case Returned = 'returned';
    case Confirmed = 'confirmed';
    case Rescheduled = 'rescheduled';
    case Declined = 'declined';
    case Accepted = 'accepted';

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Согласовано',
            self::Returned => 'Возвращено',
            self::Confirmed => 'Время подтверждено',
            self::Rescheduled => 'Предложено другое время',
            self::Declined => 'СТО отказалась от выполнения',
            self::Accepted => 'Принято Заказчиком',
        };
    }

    public function isApproved(): bool
    {
        return $this === self::Approved;
    }

    public function isReturned(): bool
    {
        return $this === self::Returned;
    }

    public function isConfirmed(): bool
    {
        return $this === self::Confirmed;
    }

    public function isRescheduled(): bool
    {
        return $this === self::Rescheduled;
    }

    public function isDeclined(): bool
    {
        return $this === self::Declined;
    }

    public function isAccepted(): bool
    {
        return $this === self::Accepted;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
