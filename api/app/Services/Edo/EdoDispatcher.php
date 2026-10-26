<?php

declare(strict_types=1);

namespace App\Services\Edo;

use App\Contracts\Edo\EdoOutboundProviderInterface;
use App\Models\Organization;
use App\Services\Edo\Drivers\Diadoc\DiadocMapper;
use App\Services\Edo\Drivers\Diadoc\DiadocProvider;
use App\Services\Edo\Drivers\EdoRu\EdoRuClientFactory;
use App\Services\Edo\Drivers\EdoRu\EdoRuMapper;
use App\Services\Edo\Drivers\EdoRu\EdoRuProvider;
use App\Services\Edo\Xml\Upd503Builder;
use App\Services\Edo\Drivers\Diadoc\DiadocClientFactory;
use App\Services\Edo\Drivers\Null\NullEdoProvider;
use App\Services\Edo\Drivers\Taxcom\TaxcomClientFactory;
use App\Services\Edo\Drivers\Taxcom\TaxcomMapper;
use App\Services\Edo\Drivers\Taxcom\TaxcomProvider;

final class EdoDispatcher
{
    public function __construct(
        private readonly EdoRuClientFactory $edoRuClientFactory,
        private readonly Upd503Builder $upd503Builder,
        private readonly DiadocClientFactory $diadocClientFactory,
    ) {
    }

    public function forOrganization(Organization $org): EdoOutboundProviderInterface
    {
        // 👇 Если включен режим отладки в .env (EDO_MOCK=true), отдаем мок
        if (config('edo.mock', false)) {
            return new NullEdoProvider();
        }

        return match ($org->edo_provider) {
            'edoru' => new EdoRuProvider(
                $this->edoRuClientFactory->make($org),
                new EdoRuMapper(),
                $this->upd503Builder
            ),
             'taxcom' => new TaxcomProvider(
                 app(TaxcomClientFactory::class)->make($org),
                 new TaxcomMapper(),
             ),
            'diadoc' => new DiadocProvider(
                $this->diadocClientFactory->make($org),
                new DiadocMapper(),
            ),
            default => throw new \RuntimeException("Оператор ЭДО не настроен: {$org->edo_provider}"),
        };
    }
}