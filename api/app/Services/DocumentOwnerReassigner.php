<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class DocumentOwnerReassigner
{
    /**
     * Переносит документы старых учётных записей на выбранного пользователя.
     * Исторические события и авторы действий не переписываются.
     *
     * @param array<int, int> $formerUserIds
     */
    public function reassign(
        Organization $organization,
        User $owner,
        array $formerUserIds,
    ): int {
        $formerUserIds = array_values(array_unique(array_map(
            'intval',
            $formerUserIds,
        )));

        if (
            $owner->organization_id !== $organization->id
            || $formerUserIds === []
        ) {
            return 0;
        }

        $requests = DB::table('repair_requests')
            ->whereIn('created_by', $formerUserIds)
            ->update(['created_by' => $owner->id]);
        $acceptanceActs = DB::table('vehicle_acceptance_acts')
            ->whereIn('created_by', $formerUserIds)
            ->update(['created_by' => $owner->id]);

        return $requests + $acceptanceActs;
    }
}
