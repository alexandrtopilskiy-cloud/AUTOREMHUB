<?php

declare(strict_types=1);

namespace App\DTO;

use App\Exceptions\EdoException;

/**
 * Подписант документа ЭДО (XML УПД/УКД).
 *
 * На первом этапе — только руководитель (authorityScope=1).
 * МЧД (authorityScope=3) — TODO, отдельный этап.
 */
final readonly class EdoSignerDto
{
    public function __construct(
        public string $lastName,
        public string $firstName,
        public ?string $middleName = null,
        public string $title = '',
        public string $authorityScope = '1',
        public string $signatureType = '1',
    ) {
        if (trim($this->lastName) === '' || trim($this->firstName) === '') {
            throw EdoException::validation('EdoSignerDto: lastName и firstName обязательны');
        }

        if (! in_array($this->authorityScope, ['1', '2', '3', '4', '5', '6'], true)) {
            throw EdoException::validation("EdoSignerDto: authorityScope={$this->authorityScope} недопустим");
        }

        if (! in_array($this->signatureType, ['1', '2', '3'], true)) {
            throw EdoException::validation("EdoSignerDto: signatureType={$this->signatureType} недопустим");
        }
    }

    public static function fromFullName(string $fullName, string $title = ''): self
    {
        $parts = preg_split('/\s+/', trim($fullName)) ?: [];

        if (count($parts) < 2) {
            throw EdoException::validation("EdoSignerDto: не удалось разобрать ФИО '{$fullName}'");
        }

        return new self(
            lastName:   $parts[0],
            firstName:  $parts[1],
            middleName: $parts[2] ?? null,
            title:      $title,
        );
    }
}
