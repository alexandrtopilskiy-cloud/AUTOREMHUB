<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Документы закрытия ЗР.
 *
 * Номер: латинский префикс + 6-значный номер ЗР; пара документов одного вида
 * различается суффиксом: /1 — СТО → УК, /2 — УК → Заказчик. Акт передачи ТС
 * получает APP-…/2 (APP-… без суффикса — акт приёма ТС на СТО).
 */
enum RequestDocumentType: string
{
    case TransferAct = 'transfer_act';
    case StationWorkOrder = 'station_work_order';
    case StationUpd = 'station_upd';
    case StationInvoice = 'station_invoice';
    case CustomerWorkOrder = 'customer_work_order';
    case CustomerUpd = 'customer_upd';
    case CustomerInvoice = 'customer_invoice';
    case AgentReport = 'agent_report';

    public function label(): string
    {
        return match ($this) {
            self::TransferAct => 'Лист приёма-передачи ТС (выдача)',
            self::StationWorkOrder, self::CustomerWorkOrder => 'Заказ-наряд',
            self::StationUpd, self::CustomerUpd => 'УПД',
            self::StationInvoice, self::CustomerInvoice => 'Счёт на оплату',
            self::AgentReport => 'Отчёт агента',
        };
    }

    public function prefix(): string
    {
        return match ($this) {
            self::TransferAct => 'APP',
            self::StationWorkOrder, self::CustomerWorkOrder => 'ZN',
            self::StationUpd, self::CustomerUpd => 'UPD',
            self::StationInvoice, self::CustomerInvoice => 'SCH',
            self::AgentReport => 'AO',
        };
    }

    public function suffix(): string
    {
        return match ($this) {
            self::StationWorkOrder, self::StationUpd, self::StationInvoice => '/1',
            self::TransferAct, self::CustomerWorkOrder, self::CustomerUpd, self::CustomerInvoice => '/2',
            self::AgentReport => '',
        };
    }

    /** Документ между СТО и УК (сумма с учётом агентской скидки). */
    public function isStationSide(): bool
    {
        return in_array($this, [self::StationWorkOrder, self::StationUpd, self::StationInvoice], true);
    }

    /** Документ между УК и Заказчиком (полная стоимость). */
    public function isCustomerSide(): bool
    {
        return in_array($this, [self::CustomerWorkOrder, self::CustomerUpd, self::CustomerInvoice, self::AgentReport], true);
    }

    public function visibleTo(UserRole $role): bool
    {
        return match ($role) {
            UserRole::Manager => true,
            UserRole::Station => $this === self::TransferAct || $this->isStationSide(),
            UserRole::Customer => $this === self::TransferAct || $this->isCustomerSide(),
        };
    }

    public function view(): string
    {
        return match ($this) {
            self::TransferAct => 'pdf.closing.transfer_act',
            self::StationWorkOrder, self::CustomerWorkOrder => 'pdf.closing.work_order',
            self::StationUpd, self::CustomerUpd => 'pdf.closing.upd',
            self::StationInvoice, self::CustomerInvoice => 'pdf.closing.invoice',
            self::AgentReport => 'pdf.closing.agent_report',
        };
    }

    /**
     * Документы, которые УК формирует на этапе «Выдан. Подготовка документов».
     *
     * @return array<int, self>
     */
    public static function closing(): array
    {
        return [
            self::StationWorkOrder,
            self::StationUpd,
            self::StationInvoice,
            self::CustomerWorkOrder,
            self::CustomerUpd,
            self::CustomerInvoice,
            self::AgentReport,
        ];
    }
}
