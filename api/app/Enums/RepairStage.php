<?php

declare(strict_types=1);

namespace App\Enums;

enum RepairStage: int
{
    case Draft = 0;
    case ManagerRouting = 1;
    case StationApproval = 2;
    case CustomerApproval = 3;
    case WaitingVehicle = 4;
    case RepairInProgress = 5;
    case Ready = 6;
    case Issued = 7;
    case CustomerPayment = 8;
    case StationPayment = 9;
    case Closed = 10;

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Черновик',
            self::ManagerRouting => 'Маршрутизация УК',
            self::StationApproval => 'Согласование СТО',
            self::CustomerApproval => 'Согласование Заказчика',
            self::WaitingVehicle => 'Ожидание ТС на СТО',
            self::RepairInProgress => 'В работе',
            self::Ready => 'ТС готово к выдаче Заказчику',
            self::Issued => 'Выдан. Подготовка документов',
            self::CustomerPayment => 'Подтверждение оплаты Заказчика',
            self::StationPayment => 'Оплата в СТО',
            self::Closed => 'Заявка на ремонт закрыта',
        };
    }

    public function title(): string
    {
        return $this->label();
    }

    public function ownerRole(): ?UserRole
    {
        return match ($this) {
            self::Draft, self::CustomerApproval => UserRole::Customer,
            self::ManagerRouting, self::Issued, self::CustomerPayment,
            self::StationPayment => UserRole::Manager,
            self::StationApproval, self::WaitingVehicle,
            self::RepairInProgress, self::Ready => UserRole::Station,
            self::Closed => null,
        };
    }

    public function next(): ?self
    {
        return self::tryFrom($this->value + 1);
    }

    public function isDraft(): bool
    {
        return $this === self::Draft;
    }

    public function isClosed(): bool
    {
        return $this === self::Closed;
    }

    public function canReturn(): bool
    {
        return !$this->isDraft();
    }

    public function previous(): ?self
    {
        return self::tryFrom($this->value - 1);
    }

    public function isBefore(self $stage): bool
    {
        return $this->value < $stage->value;
    }

    public function isAfter(self $stage): bool
    {
        return $this->value > $stage->value;
    }

    /**
     * @return array<int, int>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
