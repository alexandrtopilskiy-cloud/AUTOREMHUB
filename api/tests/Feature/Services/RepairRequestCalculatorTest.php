<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Organization;
use App\Models\RepairRequest;
use App\Models\RequestPart;
use App\Models\RequestWork;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RepairRequestCalculator;
use App\Services\Workflow;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature-тест для RepairRequestCalculator.
 *
 * Формула total():
 *   работ:     Σ(quantity × price)
 *   запчастей: Σ((quantity − returned_quantity) × price)
 *   округление ROUND_HALF_UP до 2 знаков
 *
 * Проверяет: пустая заявка, только работы, только запчасти,
 * возвраты запчастей, округление, комбинация.
 */
final class RepairRequestCalculatorTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;
    private Organization $customerOrg;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::query()->create(['name' => 'Тестовая УК']);

        $this->customerOrg = Organization::query()->create([
            'tenant_id' => $this->tenant->id,
            'kind'      => 'customer',
            'name'      => 'ООО «Заказчик»',
        ]);

        $this->customer = User::query()->create([
            'organization_id' => $this->customerOrg->id,
            'login'           => 'customer-' . Str::random(10),
            'display_name'    => 'Заказчик',
            'role'            => 'customer',
            'password'        => 'test-password-long',
            'active'          => true,
        ]);
    }

    /* ─────────────────── пустая заявка ─────────────────── */

    public function test_empty_request_returns_zero(): void
    {
        $request = $this->makeRequest();

        $this->assertSame('0.00', $this->calculator()->total($request));
    }

    /* ─────────────────── работы ─────────────────── */

    public function test_single_work(): void
    {
        $request = $this->makeRequest();
        $this->makeWork($request, quantity: '1.000', price: '1000.00');

        $this->assertSame('1000.00', $this->calculator()->total($request));
    }

    public function test_multiple_works_sum(): void
    {
        $request = $this->makeRequest();
        $this->makeWork($request, quantity: '1.000', price: '1000.00');
        $this->makeWork($request, quantity: '2.000', price: '500.00');

        // 1000 + 1000 = 2000
        $this->assertSame('2000.00', $this->calculator()->total($request));
    }

    public function test_work_with_fractional_quantity(): void
    {
        $request = $this->makeRequest();
        $this->makeWork($request, quantity: '1.500', price: '1000.00');

        // 1.5 × 1000 = 1500
        $this->assertSame('1500.00', $this->calculator()->total($request));
    }

    public function test_work_rounds_half_up(): void
    {
        $request = $this->makeRequest();
        // 1.005 × 1 = 1.005 → округляем до 1.01 (ROUND_HALF_UP)
        $this->makeWork($request, quantity: '1.005', price: '1.00');

        $this->assertSame('1.01', $this->calculator()->total($request));
    }

    /* ─────────────────── запчасти ─────────────────── */

    public function test_single_part(): void
    {
        $request = $this->makeRequest();
        $this->makePart($request, quantity: '1.000', price: '500.00');

        $this->assertSame('500.00', $this->calculator()->total($request));
    }

    public function test_part_with_returned_quantity_is_subtracted(): void
    {
        $request = $this->makeRequest();
        $this->makePart(
            $request,
            quantity: '3.000',
            price: '500.00',
            returned: '1.000',
        );

        // (3 - 1) × 500 = 1000
        $this->assertSame('1000.00', $this->calculator()->total($request));
    }

    public function test_part_fully_returned_adds_zero(): void
    {
        $request = $this->makeRequest();
        $this->makePart(
            $request,
            quantity: '2.000',
            price: '500.00',
            returned: '2.000',
        );

        $this->assertSame('0.00', $this->calculator()->total($request));
    }

    public function test_multiple_parts_sum(): void
    {
        $request = $this->makeRequest();
        $this->makePart($request, quantity: '1.000', price: '500.00');
        $this->makePart($request, quantity: '2.000', price: '300.00');

        // 500 + 600 = 1100
        $this->assertSame('1100.00', $this->calculator()->total($request));
    }

    /* ─────────────────── комбинация ─────────────────── */

    public function test_works_and_parts_sum(): void
    {
        $request = $this->makeRequest();
        $this->makeWork($request, quantity: '1.000', price: '1000.00');
        $this->makePart($request, quantity: '2.000', price: '500.00');

        // 1000 + 1000 = 2000
        $this->assertSame('2000.00', $this->calculator()->total($request));
    }

    public function test_combination_with_returns_and_rounding(): void
    {
        $request = $this->makeRequest();
        $this->makeWork($request, quantity: '1.500', price: '1000.00');   // 1500
        $this->makePart($request, quantity: '3.000', price: '333.33', returned: '1.000');   // 2 × 333.33 = 666.66

        // 1500.00 + 666.66 = 2166.66
        $this->assertSame('2166.66', $this->calculator()->total($request));
    }

    public function test_uses_bcmath_not_float(): void
    {
        $request = $this->makeRequest();
        // 0.1 + 0.2 в float = 0.30000000000000004, в bcmath = 0.30
        $this->makeWork($request, quantity: '0.100', price: '1.00');
        $this->makeWork($request, quantity: '0.200', price: '1.00');

        $this->assertSame('0.30', $this->calculator()->total($request));
    }

    /* ─────────────────── helpers ─────────────────── */

    private function calculator(): RepairRequestCalculator
    {
        return app(RepairRequestCalculator::class);
    }

    private function makeRequest(): RepairRequest
    {
        $uuid = (string) Str::uuid();

        return RepairRequest::query()->create([
            'uuid'                     => $uuid,
            'public_id'                => $uuid,
            'tenant_id'                => $this->tenant->id,
            'customer_organization_id' => $this->customerOrg->id,
            'data'                     => array_fill_keys(Workflow::TEXT_FIELDS, ''),
            'works'                    => [],
            'history'                  => [],
            'stage'                    => 1,
            'revision'                 => 1,
            'created_by'               => $this->customer->id,
        ]);
    }

    private function makeWork(
        RepairRequest $request,
        string $quantity,
        string $price,
    ): RequestWork {
        return RequestWork::query()->create([
            'uuid'              => (string) Str::uuid(),
            'repair_request_id' => $request->id,
            'name'              => 'Работа',
            'quantity'          => $quantity,
            'price'             => $price,
            'is_maintenance'    => false,
        ]);
    }

    private function makePart(
        RepairRequest $request,
        string $quantity,
        string $price,
        string $returned = '0.000',
    ): RequestPart {
        return RequestPart::query()->create([
            'uuid'              => (string) Str::uuid(),
            'repair_request_id' => $request->id,
            'name'              => 'Запчасть',
            'quantity'          => $quantity,
            'price'             => $price,
            'returned_quantity' => $returned,
            'status'            => 'proposed',
            'unit'              => 'шт.',
        ]);
    }
}
