<?php

declare(strict_types=1);

namespace App\Enums;

enum AttachmentPurpose: string
{
    case Evidence      = 'evidence';
    case Diagnostic    = 'diagnostic';
    case IssuedInvoice = 'issued_invoice';
    case PaidInvoice   = 'paid_invoice';
    case AcceptanceAct = 'acceptance_act';
    case EdoPayload    = 'edo_payload';
    case TransferAct   = 'transfer_act';
    case GeneratedDocument = 'generated_document';

    public function label(): string
    {
        return match ($this) {
            self::Evidence      => 'Фото, видео и документы',
            self::Diagnostic    => 'Материалы диагностики',
            self::IssuedInvoice => 'Выставленный счёт',
            self::PaidInvoice   => 'Закрытый счёт',
            self::AcceptanceAct => 'Акт приёмки ТС',
            self::EdoPayload    => 'XML для ЭДО',
            self::TransferAct   => 'Подписанный акт передачи ТС',
            self::GeneratedDocument => 'Документ по заявке',
        };
    }

    /**
     * @return array<int, AttachmentMime>
     */
    public function allowedMimeTypes(): array
    {
        return match ($this) {
            self::Evidence,
            self::Diagnostic,
            self::IssuedInvoice,
            self::PaidInvoice => AttachmentMime::cases(),
            self::AcceptanceAct,
            self::TransferAct => [
                AttachmentMime::Jpeg,
                AttachmentMime::Png,
                AttachmentMime::Webp,
                AttachmentMime::Pdf,
            ],
            self::EdoPayload => [
                AttachmentMime::Xml,
                AttachmentMime::XmlAlt,
            ],
            self::GeneratedDocument => [AttachmentMime::Pdf],
        };
    }

    public function allowsMime(AttachmentMime $mime): bool
    {
        return in_array($mime, $this->allowedMimeTypes(), true);
    }

    public function isEvidence(): bool
    {
        return $this === self::Evidence;
    }

    public function isDiagnostic(): bool
    {
        return $this === self::Diagnostic;
    }

    public function isInvoice(): bool
    {
        return in_array($this, [self::IssuedInvoice, self::PaidInvoice], true);
    }

    public function isEdoPayload(): bool
    {
        return in_array($this, [self::EdoPayload, self::GeneratedDocument], true);
    }

    /**
     * Системные purpose (создаются бэкендом, не пользователем).
     */
    public function isSystem(): bool
    {
        return in_array($this, [self::EdoPayload, self::GeneratedDocument], true);
    }

    public function requiredStage(): ?RepairStage
    {
        return match ($this) {
            self::Diagnostic    => RepairStage::RepairInProgress,
            // Счета прежнего маршрута больше не загружаются вручную.
            self::IssuedInvoice => RepairStage::Issued,
            self::PaidInvoice   => RepairStage::CustomerPayment,
            self::AcceptanceAct => RepairStage::WaitingVehicle,
            self::TransferAct   => RepairStage::Ready,
            self::Evidence      => null,
            self::EdoPayload,
            self::GeneratedDocument => null,   // этап проверяет сервис
        };
    }

    public function allowsStage(RepairStage $stage): bool
    {
        return $this->requiredStage() === null || $this->requiredStage() === $stage;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
