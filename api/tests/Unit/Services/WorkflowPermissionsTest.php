<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\RepairStage;
use App\Enums\UserRole;
use App\Models\RepairRequest;
use App\Models\User;
use App\Services\Workflow;
use PHPUnit\Framework\TestCase;

final class WorkflowPermissionsTest extends TestCase
{
    private const ROLES = [
        UserRole::Customer,
        UserRole::Manager,
        UserRole::Station,
    ];

    public function test_workflow_uses_the_agreed_operational_statuses(): void
    {
        $this->assertSame([
            'Черновик',
            'Маршрутизация УК',
            'Согласование СТО',
            'Согласование Заказчика',
            'Ожидание ТС на СТО',
            'В работе',
            'Готово',
            'Выдача автомобиля',
            'Закрывающие документы',
            'Ожидает оплаты',
            'Оплата на проверке УК',
            'Заявка закрыта',
        ], Workflow::STAGES);
        $this->assertCount(count(Workflow::STAGES), Workflow::OWNERS);
    }

    public function test_only_stage_owner_can_edit(): void
    {
        foreach (Workflow::OWNERS as $stage => $owner) {
            foreach (self::ROLES as $role) {
                $fields = $this->permissions($stage, $role)['editableFields'];

                if (
                    $owner !== $role->value
                    || in_array($stage, [
                        RepairStage::CustomerApproval->value,
                        RepairStage::WaitingVehicle->value,
                        RepairStage::Closed->value,
                    ], true)
                ) {
                    $this->assertSame([], $fields);
                }
            }
        }
    }

    public function test_special_decision_permissions_match_new_route(): void
    {
        $this->assertTrue(
            $this->permissions(
                RepairStage::StationApproval->value,
                UserRole::Station,
            )['canStationDecide'],
        );
        $this->assertTrue(
            $this->permissions(
                RepairStage::CustomerApproval->value,
                UserRole::Customer,
            )['canCustomerSlotDecide'],
        );
        $this->assertTrue(
            $this->permissions(
                RepairStage::WaitingVehicle->value,
                UserRole::Station,
            )['canAcceptVehicle'],
        );
        $this->assertFalse(
            $this->permissions(
                RepairStage::WaitingVehicle->value,
                UserRole::Manager,
            )['canAcceptVehicle'],
        );
    }

    public function test_manager_and_station_can_edit_estimate_in_their_approval_stages(): void
    {
        $this->assertTrue(
            $this->permissions(
                RepairStage::ManagerRouting->value,
                UserRole::Manager,
            )['canEditParts'],
        );
        $this->assertTrue(
            $this->permissions(
                RepairStage::StationApproval->value,
                UserRole::Station,
            )['canEditParts'],
        );
        $this->assertContains(
            'works',
            $this->permissions(
                RepairStage::StationApproval->value,
                UserRole::Station,
            )['editableFields'],
        );
    }

    public function test_station_manages_repair_only_after_acceptance(): void
    {
        $permissions = $this->permissions(
            RepairStage::RepairInProgress->value,
            UserRole::Station,
        );

        $this->assertTrue($permissions['canDiagnose']);
        $this->assertTrue($permissions['canManageParts']);
        $this->assertTrue($permissions['canAdvance']);
        $this->assertFalse($permissions['canReturn']);
    }

    public function test_closed_request_has_no_route_action(): void
    {
        foreach (self::ROLES as $role) {
            $permissions = $this->permissions(RepairStage::Closed->value, $role);

            $this->assertFalse($permissions['canAdvance']);
            $this->assertFalse($permissions['canReturn']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function permissions(int $stage, UserRole $role): array
    {
        $request = new RepairRequest();
        $request->stage = RepairStage::from($stage);
        $request->created_by = 999;

        $user = new User();
        $user->role = $role;
        $user->id = 1;

        return Workflow::permissions($request, $user);
    }
}
