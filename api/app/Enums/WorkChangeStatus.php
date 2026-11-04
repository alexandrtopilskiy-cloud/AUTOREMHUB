<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Изменение работ и запчастей заявки на стадии «В работе».
 *
 * СТО вносит изменения (editing) и отправляет их на согласование: сначала УК
 * (manager), затем Заказчику (customer). Несогласованные изменения
 * возвращаются в СТО с обязательным комментарием (rejected).
 */
enum WorkChangeStatus: string
{
    case Editing = 'editing';
    case ManagerReview = 'manager';
    case CustomerReview = 'customer';
    case Rejected = 'rejected';

    public const STATUS_LABEL = 'Согласование работ / з/ч';

    public function label(): string
    {
        return match ($this) {
            self::Editing => 'Изменения не отправлены на согласование',
            self::ManagerReview => 'Согласование изменений в УК',
            self::CustomerReview => 'Согласование изменений Заказчиком',
            self::Rejected => 'Изменения не согласованы',
        };
    }

    /** Чья очередь действовать по изменению. */
    public function ownerRole(): UserRole
    {
        return match ($this) {
            self::Editing, self::Rejected => UserRole::Station,
            self::ManagerReview => UserRole::Manager,
            self::CustomerReview => UserRole::Customer,
        };
    }

    public function isReview(): bool
    {
        return $this === self::ManagerReview || $this === self::CustomerReview;
    }

    public function ownerLabel(): string
    {
        return match ($this->ownerRole()) {
            UserRole::Manager => 'УК',
            UserRole::Customer => 'Заказчик',
            default => 'СТО-партнёр',
        };
    }

    public static function forRequest(\App\Models\RepairRequest $request): ?self
    {
        if ($request->stage !== RepairStage::RepairInProgress) {
            return null;
        }

        $status = $request->work_change['status'] ?? null;

        return is_string($status) ? self::tryFrom($status) : null;
    }
}
