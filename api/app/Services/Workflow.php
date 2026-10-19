<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RepairStage;
use App\Enums\UserRole;
use App\Models\RepairRequest;
use App\Models\User;

final class Workflow
{
    /** Текстовые поля заявки, хранящиеся в JSON-колонке `data`. */
    public const TEXT_FIELDS = [
        'laborRateType',
        'contact',
        'description',
        'mileage',
        'slot',
        'stationSlot',
        'routeComment',
    ];

    /** Поля, доступные на этапе черновика. */
    public const DRAFT_FIELDS = [
        'laborRateType',
        'vehicleId',
        'mileage',
        'contactId',
        'driverId',
        'description',
        'slot',
        'reasonId',
        'routeComment',
    ];

    /** Полный набор полей, доступный при маршрутизации УК. */
    public const FIELDS = [
        'laborRateType',
        'vehicleId',
        'mileage',
        'contactId',
        'driverId',
        'description',
        'slot',
        'stationId',
        'reasonId',
        'reasonSubtypeId',
        'routeComment',
        'works',
        'parts',
    ];

    /**
     * Поля, которые УК может изменять при первичной проверке заявки.
     */
    public const REVIEW_FIELDS = [
        'laborRateType',
        'contactId',
        'driverId',
        'slot',
        'stationId',
        'routeComment',
        'works',
        'parts',
    ];

    /**
     * Совместимость с клиентом и тестами: владелец каждого номера этапа.
     * Доменная логика использует RepairStage::ownerRole().
     *
     * @var array<int, string|null>
     */
    public const OWNERS = [
        'customer',
        'manager',
        'station',
        'customer',
        'station',
        'station',
        'manager',
        'customer',
        'manager',
        'customer',
        'manager',
        null,
    ];

    /**
     * Совместимость с текущим контрактом reference-data.
     * Доменная логика использует RepairStage::label().
     *
     * @var array<int, string>
     */
    public const STAGES = [
        'Черновик',
        'Маршрутизация УК',
        'Согласование СТО',
        'Согласование Заказчика',
        'Ожидание ТС на СТО',
        'В работе',
        'Готово',
        'Выдача автомобиля',
        'Закрывающие документы',
        'Ожидает оплаты',
        'Оплата на проверке УК',
        'Заявка закрыта',
    ];

    /* Обратная совместимость для тестов и внешних интеграций на уровне PHP. */
    public const STAGE_DRAFT = 0;
    public const STAGE_MANAGER_ROUTING = 1;
    public const STAGE_STATION_APPROVAL = 2;
    public const STAGE_CUSTOMER_APPROVAL = 3;
    public const STAGE_WAITING_VEHICLE = 4;
    public const STAGE_REPAIR_IN_PROGRESS = 5;
    public const STAGE_READY = 6;
    public const STAGE_ISSUE = 7;
    public const STAGE_CLOSING_DOCS = 8;
    public const STAGE_AWAITING_PAYMENT = 9;
    public const STAGE_PAYMENT_REVIEW = 10;
    public const STAGE_CLOSED = 11;

    /**
     * @return array<string, mixed>
     */
    public static function permissions(RepairRequest $request, User $user): array
    {
        $stage = $request->stage;
        $role = $user->role;
        $ownerRole = $stage === RepairStage::Draft ? (($request->exists || $request->relationLoaded('creator') ? $request->creator?->role : null) ?? UserRole::Customer) : $stage->ownerRole();
        $isOwner = $ownerRole === $role;
        $isStationDecision = $isOwner
            && $role === UserRole::Station
            && $stage === RepairStage::StationApproval;
        $isStationCompletion = $isOwner
            && $role === UserRole::Station
            && $stage === RepairStage::RepairInProgress;
        $isCustomerSlotDecision = $isOwner
            && $role === UserRole::Customer
            && $stage === RepairStage::CustomerApproval;
        $isVehicleAcceptance = $isOwner
            && $role === UserRole::Station
            && $stage === RepairStage::WaitingVehicle;

        $editableFields = $isOwner ? self::editableFields($stage) : [];
        if ($isOwner && $stage === RepairStage::Draft && $role !== UserRole::Customer) {
            $editableFields = array_values(array_unique([...array_diff($editableFields, ['vehicleId', 'mileage']), 'works', 'parts']));
        }

        if ($stage === RepairStage::Draft && ($request->data['recommendationVehicleLocked'] ?? false)) {
            $editableFields = array_values(array_diff($editableFields, ['vehicleId']));
        }

        if ($role !== UserRole::Manager) {
            $editableFields = array_values(array_diff($editableFields, ['laborRateType']));
        }

        if ($role === UserRole::Station) {
            $editableFields = array_values(array_diff(
                $editableFields,
                ['vehicleId', 'mileage'],
            ));
        }

        return [
            'editableFields'   => $editableFields,
            'canDeleteDraft'   => $stage === RepairStage::Draft
                && $request->created_by === $user->id,
            'canAdvance'       => $isOwner
                && !$isStationDecision
                && !$isCustomerSlotDecision
                && !$isVehicleAcceptance
                && $stage !== RepairStage::Closed,
            'canReturn'        => $isOwner
                && !$isStationDecision
                && !$isCustomerSlotDecision
                && !$isVehicleAcceptance
                && !$isStationCompletion
                && $stage !== RepairStage::Draft,
            'canStationDecide' => $isStationDecision,
            'canCustomerSlotDecide' => $isCustomerSlotDecision,
            'canAcceptVehicle' => $isVehicleAcceptance,
            'canEditParts'     => $isOwner && (self::isPartsEditableStage($stage) || ($stage === RepairStage::Draft && $role !== UserRole::Customer)),
            'canDeferRecommendations' => $isOwner && in_array($stage, [RepairStage::ManagerRouting, RepairStage::StationApproval, RepairStage::CustomerApproval], true),
            'canRestoreRecommendations' => $isOwner && in_array($stage, [RepairStage::Draft, RepairStage::ManagerRouting, RepairStage::StationApproval, RepairStage::CustomerApproval], true),
            'requiresAction' => $isOwner && $stage !== RepairStage::Closed,
            'canDiagnose'      => $isOwner && $stage === RepairStage::RepairInProgress,
            'canUpload'        => $isOwner && (
                $role !== UserRole::Manager
                || $stage === RepairStage::ClosingDocuments
            ),
            'canManageParts'   => $isOwner
                && $stage === RepairStage::RepairInProgress,
            'canIssueInvoice'  => $isOwner && $stage === RepairStage::ClosingDocuments,
            'canSubmitPayment' => $isOwner && $stage === RepairStage::AwaitingPayment,
            'canVerifyPayment' => $isOwner && $stage === RepairStage::PaymentReview,
        ];
    }

    /**
     * Поля, доступные для редактирования на этапе.
     *
     * @return array<int, string>
     */
    private static function editableFields(RepairStage $stage): array
    {
        return match ($stage) {
            RepairStage::Draft => self::DRAFT_FIELDS,
            RepairStage::ManagerRouting => self::REVIEW_FIELDS,
            RepairStage::StationApproval => ['works', 'parts', 'routeComment'],
            RepairStage::CustomerApproval,
            RepairStage::WaitingVehicle => [],
            default => ['routeComment'],
        };
    }

    /**
     * Этапы, на которых доступно редактирование запчастей.
     */
    private static function isPartsEditableStage(RepairStage $stage): bool
    {
        return in_array($stage, [
            RepairStage::ManagerRouting,
            RepairStage::StationApproval,
        ], true);
    }
}
