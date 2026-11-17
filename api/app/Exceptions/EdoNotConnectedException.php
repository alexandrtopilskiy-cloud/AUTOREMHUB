<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\Edo\EdoProvider;
use App\Models\Organization;
use RuntimeException;

/**
 * Организация не подключена к оператору ЭДО, выбранному в EDO_PROVIDER
 * (нет логина/ключа в «Документы ЭДО» → ⚙). Повторять отправку бесполезно,
 * пока организацию не подключат, поэтому слушатели очереди её пропускают.
 */
final class EdoNotConnectedException extends RuntimeException
{
    public static function for(Organization $organization, EdoProvider $provider): self
    {
        if ($provider === EdoProvider::None) {
            return new self('ЭДО отключено: в .env не указан EDO_PROVIDER');
        }

        return new self(sprintf(
            'Организация «%s» не подключена к оператору ЭДО «%s»: укажите логин и пароль в «Документы ЭДО» → ⚙',
            $organization->name,
            $provider->label(),
        ));
    }
}
