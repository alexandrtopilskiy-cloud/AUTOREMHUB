<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\DTO\UserDto;
use App\Enums\UserRole;

final class StoreCatalogVehicleReferenceRequest extends ApiValidationRequest
{
    public function authorize(): bool
    {
        $userDto = UserDto::fromUserModel($this->user());

        // Возвращаем true, только если роль Manager.
        // Если false, Laravel вернет 403 Forbidden автоматически.
        return $userDto->role === UserRole::Manager;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Валидируем, что поле record обязательно и является массивом
            'record' => ['required', 'array'],
        ];
    }

    /**
     * Переопределяем сообщение об ошибке для 403.
     */
    public function failedAuthorization(): void
    {
        abort(403, 'Добавлять записи справочника может только УК');
    }
}
