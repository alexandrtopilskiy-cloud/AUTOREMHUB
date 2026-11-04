<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RepairStage;
use App\Enums\UserRole;
use App\Enums\WorkChangeStatus;
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
        // Стадия «В работе»: изменения работ/ЗЧ согласуют УК, затем Заказчик.
        // Пока изменения на согласовании, переходы и правка сметы недоступны.
        $workChange = WorkChangeStatus::forRequest($request);
        $workChangeReview = $workChange?->isReview() ?? false;
        $isWorkChangeReviewer = $workChangeReview && $workChange->ownerRole() === $role;
        $isStationInWork = $isOwner && $role === UserRole::Station && $stage === RepairStage::RepairInProgress;
        $canChangeInWork = $isStationInWork && !$workChangeReview;
        // Изменённые в ходе ремонта работы/ЗЧ: «Далее» недоступна до полного
        // согласования УК и Заказчиком (или отмены изменений СТО).
        $advanceBlockedByWorkChange = $isStationInWork && $workChange !== null;
        if ($workChangeReview) {
            $isOwner = false;
        }
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

        if ($canChangeInWork) {
            $editableFields = array_values(array_unique([...$editableFields, 'works', 'parts']));
        }

        return [
            'editableFields'   => $editableFields,
            'canDeleteDraft'   => $stage === RepairStage::Draft
                && $request->created_by === $user->id
                && !self::wasRouted($request),
            'canAdvance'       => $isOwner
                && !$advanceBlockedByWorkChange
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
            'canEditParts'     => $canChangeInWork || ($isOwner && (self::isPartsEditableStage($stage) || ($stage === RepairStage::Draft && $role !== UserRole::Customer))),
            'canDeferRecommendations' => $canChangeInWork || ($isOwner && in_array($stage, [RepairStage::ManagerRouting, RepairStage::StationApproval, RepairStage::CustomerApproval], true)),
            'canRestoreRecommendations' => $isOwner && in_array($stage, [RepairStage::Draft, RepairStage::ManagerRouting, RepairStage::StationApproval, RepairStage::CustomerApproval], true),
            'requiresAction' => ($isOwner && $stage !== RepairStage::Closed) || $isWorkChangeReviewer,
            'canDiagnose'      => $isStationInWork,
            'canUpload'        => $isStationInWork || ($isOwner && (
                $role !== UserRole::Manager
                || $stage === RepairStage::ClosingDocuments
            )),
            'canManageParts'   => $isStationInWork,
            // Изменение работ/ЗЧ на стадии «В работе».
            'canSubmitWorkChange' => $canChangeInWork && $workChange !== null,
            'canDiscardWorkChange' => $canChangeInWork && $workChange !== null,
            'canDecideWorkChange' => $isWorkChangeReviewer,
            'advanceBlockedByWorkChange' => $advanceBlockedByWorkChange,
            'canIssueInvoice'  => $isOwner && $stage === RepairStage::ClosingDocuments,
            'canSubmitPayment' => $isOwner && $stage === RepairStage::AwaitingPayment,
            'canVerifyPayment' => $isOwner && $stage === RepairStage::PaymentReview,
        ];
    }

    /**
     * Название статуса заявки с учётом согласований внутри этапа.
     */
    public static function statusLabel(RepairRequest $request): string
    {
        if ($request->stage === RepairStage::ManagerRouting) {
            return match ($request->review_context) {
                'station_changes' => WorkChangeStatus::STATUS_LABEL,
                'station_declined' => 'Маршрутизация УК (отказ СТО)',
                default => $request->stage->label(),
            };
        }

        if (WorkChangeStatus::forRequest($request)?->isReview()) {
            return WorkChangeStatus::STATUS_LABEL;
        }

        return $request->stage->label();
    }

    /**
     * Кто сейчас действует по заявке, если это не владелец этапа.
     */
    public static function ownerLabel(RepairRequest $request): ?string
    {
        $workChange = WorkChangeStatus::forRequest($request);

        return $workChange?->isReview() ? $workChange->ownerLabel() : null;
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

    /**
     * Документ хоть раз покидал черновик (есть период другого этапа).
     */
    private static function wasRouted(RepairRequest $request): bool
    {
        if ($request->relationLoaded('periods')) {
            return $request->periods->contains(
                fn ($period): bool => $period->stage !== RepairStage::Draft,
            );
        }

        return $request->periods()
            ->where('stage', '<>', RepairStage::Draft->value)
            ->exists();
    }
}
