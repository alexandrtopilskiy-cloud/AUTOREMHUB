<?php

declare(strict_types=1);

namespace App\Contracts\Edo;

use App\Models\Organization;

interface EdoAccountProviderInterface
{
    /**
     * Подключение организации к ЭДО.
     *
     * @param Organization $org Модель организации
     * @param array $credentials Массив с кредами (login/password или client_id/refresh_token)
     * @throws \Throwable Если креды неверные или произошла ошибка сети
     */
    public function connect(Organization $org, array $credentials): void;
}
