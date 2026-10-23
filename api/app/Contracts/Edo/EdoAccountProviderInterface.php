<?php

declare(strict_types=1);

namespace App\Contracts\Edo;

use App\DTO\EdoAccountInfoDto;

interface EdoAccountProviderInterface
{
    /**
     * Информация о состоянии ЛК: баланс, счётчики, блокировка.
     */
    public function getAccountInfo(): EdoAccountInfoDto;

    /**
     * Проверка, авторизован ли ЛК.
     */
    public function isAuthenticated(): bool;
}
