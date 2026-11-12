<?php

declare(strict_types=1);

namespace App\Services\Edo;

use App\Contracts\Edo\EdoOutboundProviderInterface;
use App\Enums\Edo\EdoProvider;
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
use App\Services\Edo\Drivers\Sbis\SbisProvider;
use App\Services\Edo\Drivers\Sbis\SbisClientFactory;
use Illuminate\Support\Facades\Log;

final class EdoDispatcher
{
    public function __construct(
        private readonly DiadocClientFactory $diadocClientFactory,
        private readonly EdoRuClientFactory $edoRuClientFactory,
        private readonly TaxcomClientFactory $taxcomClientFactory,
        private readonly SbisClientFactory $sbisClientFactory,
        private readonly Upd503Builder $upd503Builder,
    ) {}

    public function forOrganization(Organization $org): EdoOutboundProviderInterface
    {
        $logger = Log::channel('edo');
        $logger->info('GET PROVIDER BY ORG');

        return match (config('edo.default')) {
            EdoProvider::EdoRu->value => new EdoRuProvider(
                client: $this->edoRuClientFactory->make($org),
                mapper: new EdoRuMapper(),
                updBuilder: $this->upd503Builder
            ),
             EdoProvider::Taxcom->value => new TaxcomProvider(
                 client: $this->taxcomClientFactory->make($org),
                 mapper: new TaxcomMapper(),
             ),
            EdoProvider::Diadoc->value => new DiadocProvider(
                client: $this->diadocClientFactory->make($org),
                mapper: new DiadocMapper(),
            ),
            EdoProvider::Sbis->value => new SbisProvider(
                client: $this->sbisClientFactory->make($org)
            ),
        };
    }
}