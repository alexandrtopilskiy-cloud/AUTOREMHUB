<?php

declare(strict_types=1);

namespace App\Enums\Edo;

enum EdoDocumentStatus: string
{
    case Draft                = 'draft';
    case ToSign               = 'to_sign';
    case Prepared             = 'prepared';
    case OnAgreement          = 'on_agreement';
    case Signed               = 'signed';
    case Rejected             = 'rejected';
    case Accepted             = 'accepted';
    case OnRevocation         = 'on_revocation';
    case Revoked              = 'revoked';
    case Sended               = 'sended';
    case Delivered            = 'delivered';
    case Received             = 'received';
    case RequestedAvoidance   = 'requested_avoidance';
    case RejectedAvoidance    = 'rejected_avoidance';
    case AcceptedAvoidance    = 'accepted_avoidance';
    case ClarificationRequested = 'clarification_requested';
    case WaitingForSend       = 'waiting_for_send';
    case Unknown              = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Draft                 => 'Черновик',
            self::ToSign                => 'Ожидает подписания',
            self::Prepared              => 'Подготовлен',
            self::OnAgreement           => 'На согласовании',
            self::Signed                => 'Подписан',
            self::Rejected              => 'Отклонён',
            self::Accepted              => 'Принят',
            self::OnRevocation          => 'На отзыве',
            self::Revoked               => 'Отозван',
            self::Sended                => 'Отправлен',
            self::Delivered             => 'Доставлен',
            self::Received              => 'Получен',
            self::RequestedAvoidance    => 'Предложено аннулирование',
            self::RejectedAvoidance     => 'Отклонено аннулирование',
            self::AcceptedAvoidance     => 'Аннулирован',
            self::ClarificationRequested => 'С уточнением',
            self::WaitingForSend        => 'Ожидает отправки',
            self::Unknown               => 'Неизвестный статус',
        };
    }

    /**
     * Привести статус СберКоруса (DocumentStatus enum) к нашему значению.
     * Значения — из перечисления DocumentStatus (страница 523).
     */
    public static function fromSberKorus(int|string $code): self
    {
        // Некоторые методы API возвращают строковое имя, некоторые — int
        $key = is_int($code) ? $code : (int) $code;

        return match ($key) {
            0  => self::Unknown,
            1  => self::Draft,
            2  => self::ToSign,
            3  => self::Prepared,
            4  => self::Prepared,   // IsRead — приравниваем к Prepared
            5  => self::OnAgreement,
            6  => self::Signed,
            7  => self::Rejected,
            9  => self::Accepted,
            10 => self::OnRevocation,
            11 => self::Revoked,
            12 => self::Sended,
            13 => self::Delivered,
            14 => self::Received,
            15 => self::Prepared,   // NoRoute — не наш кейс, но не Unknown
            16 => self::RequestedAvoidance,
            17 => self::RejectedAvoidance,
            18 => self::AcceptedAvoidance,
            19 => self::ClarificationRequested,
            20 => self::WaitingForSend,
            default => self::Unknown,
        };
    }
}