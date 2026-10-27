<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\OrganizationKind;
use App\Enums\UserRole;
use App\Events\Organizations\AgentContractRequested;
use App\Exceptions\ApiException;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OrganizationController
{
    public function sendAgentContract(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        // 1. Проверка прав: только менеджер УК может отправлять договор
        if ($user->role !== UserRole::Manager) {
            throw new ApiException(403, 'FORBIDDEN', 'Только УК может отправить агентский договор');
        }

        // 2. Находим организацию-заказчика
        $customer = Organization::query()
        ->where('tenant_id', $user->tenantId())
            ->where('kind', OrganizationKind::Customer) // Убедитесь, что у вас есть этот Enum
            ->where('uuid', $id)
            ->firstOrFail();

        // 3. Опционально: проверяем, заполнены ли ИНН/КПП для ЭДО
        if (empty($customer->inn)) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'У организации не заполнен ИНН, отправка невозможна');
        }

        // 4. Диспатчим событие (оно улетит в очередь и вызовет EdoAgentContractService)
        AgentContractRequested::dispatch($customer);

        return response()->json([
            'message' => 'Агентский договор поставлен в очередь на отправку в ЭДО',
        ]);
    }
}
