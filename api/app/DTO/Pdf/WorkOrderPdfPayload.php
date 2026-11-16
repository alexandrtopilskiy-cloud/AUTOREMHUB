<?php

declare(strict_types=1);

namespace App\DTO\Pdf;

final readonly class WorkOrderPdfPayload
{
    /**
     * @param PdfRowDto[] $rows
     */
    public function __construct(
        public PdfPartyDto $seller,
        public PdfPartyDto $buyer,
        public string $vehicleInfo,
        public array $rows,
        public PdfTotalsDto $totals,
        public ?string $completedAtText = null,
        public ?string $discountText = null,
    ) {}
}
