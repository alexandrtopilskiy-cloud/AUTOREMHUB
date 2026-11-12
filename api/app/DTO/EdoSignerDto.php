<?php

declare(strict_types=1);

namespace App\DTO;

use App\Exceptions\EdoException;
use App\Models\Organization;

/**
 * Подписант документа ЭДО (XML УПД/УКД).
 */
final readonly class EdoSignerDto
{
    public function __construct(
        public string $lastName,
        public string $firstName,
        public ?string $middleName = null,
        public string $title = '',
        public string $inn = '',
        public string $authorityScope = '1',
        public string $signatureType = '1',
        public bool $isIndividual = false,
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

    /**
     * Создает DTO подписанта из модели организации (БД).
     * Автоматически определяет ИП или ЮЛ по длине ИНН.
     */
    public static function fromOrganization(Organization $org): self
    {
        $isIp = strlen($org->inn) === 12;

        // Для ИП и ЮЛ ФИО хранится в director_name
        $fullName = $org->director_name;

        // Если для ИП director_name пустой, можно попробовать взять из name (например, "ИП Иванов И.И.")
        if (empty($fullName) && $isIp) {
            $fullName = str_replace('ИП ', '', $org->name);
        }

        return self::fromFullName(
            fullName: $fullName,
            title: $isIp ? 'Индивидуальный предприниматель' : 'Генеральный директор',
            inn: $org->inn,
            isIndividual: $isIp
        );
    }

    /**
     * Универсальный метод парсинга ФИО.
     */
    public static function fromFullName(string $fullName, string $title, string $inn, bool $isIndividual = false): self
    {
        $parts = preg_split('/\s+/', trim($fullName)) ?: [];

        if (count($parts) < 2) {
            throw EdoException::validation("EdoSignerDto: не удалось разобрать ФИО '{$fullName}'");
        }

        return new self(
            lastName:     $parts[0],
            firstName:    $parts[1],
            middleName:   $parts[2] ?? null,
            title:        $title,
            inn:          $inn,
            isIndividual: $isIndividual,
        );
    }
}
