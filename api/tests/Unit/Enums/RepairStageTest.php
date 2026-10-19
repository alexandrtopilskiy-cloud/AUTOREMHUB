<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\RepairStage;
use App\Enums\UserRole;
use App\Services\Workflow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RepairStageTest extends TestCase
{
    /**
     * @return array<string, array{RepairStage, UserRole|null}>
     */
    public static function ownerRoleProvider(): array
    {
        return [
            'draft' => [RepairStage::Draft, UserRole::Customer],
            'manager routing' => [RepairStage::ManagerRouting, UserRole::Manager],
            'station approval' => [RepairStage::StationApproval, UserRole::Station],
            'customer approval' => [RepairStage::CustomerApproval, UserRole::Customer],
            'waiting vehicle' => [RepairStage::WaitingVehicle, UserRole::Station],
            'repair in progress' => [RepairStage::RepairInProgress, UserRole::Station],
            'ready' => [RepairStage::Ready, UserRole::Manager],
            'vehicle issue' => [RepairStage::VehicleIssue, UserRole::Customer],
            'closing documents' => [RepairStage::ClosingDocuments, UserRole::Manager],
            'awaiting payment' => [RepairStage::AwaitingPayment, UserRole::Customer],
            'payment review' => [RepairStage::PaymentReview, UserRole::Manager],
            'closed' => [RepairStage::Closed, null],
        ];
    }

    #[DataProvider('ownerRoleProvider')]
    public function test_owner_role_matches_workflow(
        RepairStage $stage,
        ?UserRole $expected,
    ): void
    {
        $this->assertSame($expected, $stage->ownerRole());
    }

    public function test_stage_values_are_contiguous_and_complete(): void
    {
        $this->assertSame(range(0, 11), RepairStage::values());
        $this->assertCount(count(self::ownerRoleProvider()), RepairStage::cases());
    }

    public function test_labels_and_owners_match_legacy_workflow_contract(): void
    {
        $this->assertSame(
            Workflow::STAGES,
            array_map(
                static fn (RepairStage $stage): string => $stage->label(),
                RepairStage::cases(),
            ),
        );
        $this->assertSame(
            Workflow::OWNERS,
            array_map(
                static fn (RepairStage $stage): ?string => $stage->ownerRole()?->value,
                RepairStage::cases(),
            ),
        );
    }

    public function test_next_and_previous_respect_route_boundaries(): void
    {
        $this->assertNull(RepairStage::Draft->previous());
        $this->assertSame(RepairStage::ManagerRouting, RepairStage::Draft->next());
        $this->assertSame(RepairStage::PaymentReview, RepairStage::Closed->previous());
        $this->assertNull(RepairStage::Closed->next());
    }
}
