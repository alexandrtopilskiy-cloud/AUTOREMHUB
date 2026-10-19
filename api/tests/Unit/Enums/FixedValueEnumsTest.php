<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\ApprovalDecision;
use App\Enums\AttachmentMime;
use App\Enums\AttachmentPurpose;
use App\Enums\CatalogKind;
use App\Enums\InvoiceStatus;
use App\Enums\OrganizationKind;
use App\Enums\PartMovementKind;
use App\Enums\PersonKind;
use App\Enums\RequestPartStatus;
use App\Enums\TransitionAction;
use App\Enums\UserRole;
use App\Enums\WorkOrderItemKind;
use BackedEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FixedValueEnumsTest extends TestCase
{
    /**
     * @return array<string, array{class-string<BackedEnum>, array<int, string>}>
     */
    public static function enumValuesProvider(): array
    {
        return [
            ApprovalDecision::class => [ApprovalDecision::class, [
                'approved',
                'returned',
                'confirmed',
                'rescheduled',
                'declined',
                'accepted',
            ]],
            AttachmentMime::class => [AttachmentMime::class, [
                'image/jpeg',
                'image/png',
                'image/webp',
                'video/mp4',
                'video/webm',
                'video/quicktime',
                'application/pdf',
            ]],
            AttachmentPurpose::class => [AttachmentPurpose::class, [
                'evidence',
                'diagnostic',
                'issued_invoice',
                'paid_invoice',
                'acceptance_act',
            ]],
            CatalogKind::class => [CatalogKind::class, [
                'vehicles',
                'vehicleModels',
                'drivers',
                'contactPersons',
                'workTypes',
                'timeNorms',
                'normApplicabilities',
                'parts',
                'partManufacturers',
                'reasons',
                'regulations',
                'suppliers',
                'stations',
                'customers',
                'managers',
                'users',
            ]],
            InvoiceStatus::class => [InvoiceStatus::class, ['issued', 'submitted', 'paid']],
            OrganizationKind::class => [OrganizationKind::class, [
                'customer',
                'station',
                'manager',
            ]],
            PartMovementKind::class => [PartMovementKind::class, ['return', 'exchange']],
            PersonKind::class => [PersonKind::class, ['contact', 'driver']],
            RequestPartStatus::class => [RequestPartStatus::class, [
                'proposed',
                'approved',
                'ordered',
                'received',
                'installed',
                'returned',
                'exchanged',
            ]],
            TransitionAction::class => [TransitionAction::class, ['next', 'return']],
            UserRole::class => [UserRole::class, ['customer', 'station', 'manager']],
            WorkOrderItemKind::class => [WorkOrderItemKind::class, ['work', 'part']],
        ];
    }

    /**
     * @param class-string<BackedEnum> $enumClass
     * @param array<int, string> $expected
     */
    #[DataProvider('enumValuesProvider')]
    public function test_values_match_the_persisted_contract(
        string $enumClass,
        array $expected,
    ): void
    {
        $this->assertSame($expected, $enumClass::values());
        $this->assertCount(count($expected), array_unique($enumClass::values()));
    }
}
