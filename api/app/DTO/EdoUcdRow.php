<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class EdoUcdRow
{
    public function __construct(
        public string $name,
        public ?string $unitBefore,
        public ?string $unitAfter,
        public ?string $amountBefore,
        public ?string $amountAfter,
        public ?string $priceBefore,
        public ?string $priceAfter,
        public ?string $netSumBefore,
        public ?string $netSumAfter,
        public ?string $vatSumBefore,
        public ?string $vatSumAfter,
        public string $vatTypeBefore,
        public string $vatTypeAfter,
        public string $totalSumBefore,
        public string $totalSumAfter,
    ) {
    }
}
