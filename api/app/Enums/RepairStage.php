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
    case VehicleIssue = 7;
    case ClosingDocuments = 8;
    case AwaitingPayment = 9;
    case PaymentReview = 10;
    case Closed = 11;

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Черновик',
            self::ManagerRouting => 'Маршрутизация УК',
            self::StationApproval => 'Согласование СТО',
            self::CustomerApproval => 'Согласование Заказчика',
            self::WaitingVehicle => 'Ожидание ТС на СТО',
            self::RepairInProgress => 'В работе',
            self::Ready => 'Готово',
            self::VehicleIssue => 'Выдача автомобиля',
            self::ClosingDocuments => 'Закрывающие документы',
            self::AwaitingPayment => 'Ожидает оплаты',
            self::PaymentReview => 'Оплата на проверке УК',
            self::Closed => 'Заявка закрыта',
        };
    }

    public function title(): string
    {
        return $this->label();
    }

    public function ownerRole(): ?UserRole
    {
        return match ($this) {
            self::Draft, self::CustomerApproval, self::VehicleIssue,
            self::AwaitingPayment => UserRole::Customer,
            self::ManagerRouting, self::Ready, self::ClosingDocuments,
            self::PaymentReview => UserRole::Manager,
            self::StationApproval, self::WaitingVehicle,
            self::RepairInProgress => UserRole::Station,
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
