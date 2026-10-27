<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\RepairStage;
use App\Enums\TransitionAction;
use App\Exceptions\ApiException;
use App\Models\RepairRequest;
use App\Services\RepairRequestService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class RepairRequestTransitionTargetTest extends TestCase
{
    private ReflectionMethod $method;
    private RepairRequestService $service;

    protected function setUp(): void
    {
        $this->method = new ReflectionMethod(RepairRequestService::class, 'transitionTarget');
        $this->method->setAccessible(true);
        $this->service = (new ReflectionClass(RepairRequestService::class))
            ->newInstanceWithoutConstructor();
    }

    /**
     * @return array<string, array{RepairStage, TransitionAction, RepairStage}>
     */
    public static function linearTransitions(): array
    {
        return [
            'draft to manager' => [
                RepairStage::Draft,
                TransitionAction::Next,
                RepairStage::ManagerRouting,
            ],
            'customer approval to waiting vehicle' => [
                RepairStage::CustomerApproval,
                TransitionAction::Next,
                RepairStage::WaitingVehicle,
            ],
            'repair to ready' => [
                RepairStage::RepairInProgress,
                TransitionAction::Next,
                RepairStage::Ready,
            ],
            'ready back to repair' => [
                RepairStage::Ready,
                TransitionAction::Return,
                RepairStage::RepairInProgress,
            ],
            'payment review to closed' => [
                RepairStage::PaymentReview,
                TransitionAction::Next,
                RepairStage::Closed,
            ],
        ];
    }

    #[DataProvider('linearTransitions')]
    public function test_linear_transition(
        RepairStage $from,
        TransitionAction $action,
        RepairStage $expected,
    ): void
    {
        $request = new RepairRequest();
        $request->stage = $from;

        $this->assertSame(
            $expected,
            $this->method->invoke($this->service, $request, $from, $action),
        );
    }

    public function test_initial_manager_review_goes_to_station(): void
    {
        $request = $this->managerRequest('initial', 1, 1);

        $this->assertSame(
            RepairStage::StationApproval,
            $this->method->invoke(
                $this->service,
                $request,
                RepairStage::ManagerRouting,
                TransitionAction::Next,
            ),
        );
    }

    public function test_unchanged_station_estimate_goes_from_manager_to_customer(): void
    {
        $request = $this->managerRequest('station_changes', 4, 4);

        $this->assertSame(
            RepairStage::CustomerApproval,
            $this->method->invoke(
                $this->service,
                $request,
                RepairStage::ManagerRouting,
                TransitionAction::Next,
            ),
        );
    }

    public function test_manager_correction_with_next_goes_to_customer(): void
    {
        $request = $this->managerRequest('station_changes', 5, 4);

        $this->assertSame(
            RepairStage::CustomerApproval,
            $this->method->invoke(
                $this->service,
                $request,
                RepairStage::ManagerRouting,
                TransitionAction::Next,
            ),
        );
    }

    public function test_return_from_station_change_review_goes_to_station(): void
    {
        $request = $this->managerRequest('station_changes', 4, 4);

        $this->assertSame(
            RepairStage::StationApproval,
            $this->method->invoke(
                $this->service,
                $request,
                RepairStage::ManagerRouting,
                TransitionAction::Return,
            ),
        );
    }

    /**
     * @return array<string, array{RepairStage, TransitionAction}>
     */
    public static function routeBoundaries(): array
    {
        return [
            'before draft' => [RepairStage::Draft, TransitionAction::Return],
            'after closed' => [RepairStage::Closed, TransitionAction::Next],
        ];
    }

    #[DataProvider('routeBoundaries')]
    public function test_transition_rejects_route_boundary(
        RepairStage $from,
        TransitionAction $action,
    ): void
    {
        $request = new RepairRequest();
        $request->stage = $from;

        $this->expectException(ApiException::class);
        $this->method->invoke($this->service, $request, $from, $action);
    }

    private function managerRequest(string $context, int $version, int $baseline): RepairRequest
    {
        $request = new RepairRequest();
        $request->stage = RepairStage::ManagerRouting;
        $request->review_context = $context;
        $request->estimate_version = $version;
        $request->review_baseline_version = $baseline;

        return $request;
    }
}
