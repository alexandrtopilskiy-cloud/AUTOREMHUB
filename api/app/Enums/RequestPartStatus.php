<?php

declare(strict_types=1);

namespace App\Enums;

enum RequestPartStatus: string
{
    case Proposed = 'proposed';
    case Approved = 'approved';
    case Ordered = 'ordered';
    case Received = 'received';
    case Installed = 'installed';
    case Returned = 'returned';
    case Exchanged = 'exchanged';

    public function label(): string
    {
        return match ($this) {
            self::Proposed => 'На согласовании',
            self::Approved => 'Согласована',
            self::Ordered => 'Заказана',
            self::Received => 'Получена',
            self::Installed => 'Установлена',
            self::Returned => 'Возвращена',
            self::Exchanged => 'Обменена',
        };
    }

    public function isEditable(): bool
    {
        return in_array($this, [self::Proposed, self::Approved], true);
    }

    public function isOrderedOrReceived(): bool
    {
        return in_array($this, [self::Ordered, self::Received], true);
    }

    public function isReadyForRepair(): bool
    {
        return in_array($this, [self::Received, self::Installed], true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Installed, self::Returned, self::Exchanged], true);
    }

    /**
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Proposed => [self::Approved],
            self::Approved => [self::Ordered],
            self::Ordered => [self::Received, self::Returned, self::Exchanged],
            self::Received => [self::Installed, self::Returned, self::Exchanged],
            self::Installed, self::Returned, self::Exchanged => [],
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
