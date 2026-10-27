<?php

declare(strict_types=1);

namespace App\Exceptions;

final class EdoException extends ApiException
{
    public static function validation(string $message): self
    {
        return new self(
            422,
            'EDO_VALIDATION_ERROR',
            $message,
        );
    }

    public static function notConfigured(): self
    {
        return new self(
            503,
            'EDO_NOT_CONFIGURED',
            'ЭДО не настроен. Укажите EDO_PROVIDER в .env',
        );
    }

    public static function providerUnavailable(string $provider): self
    {
        return new self(
            503,
            'EDO_PROVIDER_UNAVAILABLE',
            sprintf('ЭДО-провайдер «%s» недоступен', $provider),
        );
    }

    public static function operationNotSupported(string $operation, string $provider): self
    {
        return new self(
            501,
            'EDO_OPERATION_NOT_SUPPORTED',
            sprintf('Операция «%s» не поддерживается провайдером «%s»', $operation, $provider),
        );
    }

    public static function remoteError(string $provider, string $code, string $message): self
    {
        return new self(
            502,
            'EDO_REMOTE_ERROR',
            sprintf('[%s] %s: %s', $provider, $code, $message),
        );
    }
}