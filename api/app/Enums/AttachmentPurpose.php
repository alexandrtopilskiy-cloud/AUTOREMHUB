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

    public function label(): string
    {
        return match ($this) {
            self::Evidence      => 'Фото, видео и документы',
            self::Diagnostic    => 'Материалы диагностики',
            self::IssuedInvoice => 'Выставленный счёт',
            self::PaidInvoice   => 'Закрытый счёт',
            self::AcceptanceAct => 'Акт приёмки ТС',
            self::EdoPayload    => 'XML для ЭДО',
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
            self::AcceptanceAct => [
                AttachmentMime::Jpeg,
                AttachmentMime::Png,
                AttachmentMime::Webp,
                AttachmentMime::Pdf,
            ],
            self::EdoPayload => [
                AttachmentMime::Xml,
                AttachmentMime::XmlAlt,
            ],
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
        return $this === self::EdoPayload;
    }

    /**
     * Системные purpose (создаются бэкендом, не пользователем).
     */
    public function isSystem(): bool
    {
        return $this === self::EdoPayload;
    }

    public function requiredStage(): ?RepairStage
    {
        return match ($this) {
            self::Diagnostic    => RepairStage::RepairInProgress,
            self::IssuedInvoice => RepairStage::ClosingDocuments,
            self::PaidInvoice   => RepairStage::AwaitingPayment,
            self::AcceptanceAct => RepairStage::WaitingVehicle,
            self::Evidence      => null,
            self::EdoPayload    => null,   // этап проверяет EdoService
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
