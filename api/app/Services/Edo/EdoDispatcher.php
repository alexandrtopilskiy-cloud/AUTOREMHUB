<?php

declare(strict_types=1);

namespace App\Services\Edo;

use App\Contracts\Edo\EdoOutboundProviderInterface;
use App\Enums\Edo\EdoProvider;
use App\Exceptions\EdoNotConnectedException;
use App\Models\Organization;
use App\Services\Edo\Drivers\Diadoc\DiadocMapper;
use App\Services\Edo\Drivers\Diadoc\DiadocProvider;
use App\Services\Edo\Drivers\EdoRu\EdoRuClientFactory;
use App\Services\Edo\Drivers\EdoRu\EdoRuMapper;
use App\Services\Edo\Drivers\EdoRu\EdoRuProvider;
use App\Services\Edo\Xml\Upd503Builder;
use App\Services\Edo\Drivers\Diadoc\DiadocClientFactory;
use App\Services\Edo\Drivers\Null\NullEdoProvider;
use App\Services\Edo\Drivers\Sbis\SbisClientFactory;
use App\Services\Edo\Drivers\Sbis\SbisProvider;
use App\Services\Edo\Drivers\Taxcom\TaxcomClientFactory;
use App\Services\Edo\Drivers\Taxcom\TaxcomMapper;
use App\Services\Edo\Drivers\Taxcom\TaxcomProvider;

final class EdoDispatcher
{
    public function __construct(
        private readonly EdoRuClientFactory $edoRuClientFactory,
        private readonly Upd503Builder $upd503Builder,
        private readonly DiadocClientFactory $diadocClientFactory,
    ) {}

    /**
     * Оператор ЭДО для организации: выбранный в .env (EDO_PROVIDER) — один на
     * всю систему. Если EDO_PROVIDER не задан — оператор из настроек организации.
     */
    public function providerFor(Organization $org): EdoProvider
    {
        $configured = EdoProvider::tryFrom((string) config('edo.default', EdoProvider::None->value)) ?? EdoProvider::None;

        if ($configured !== EdoProvider::None) {
            return $configured;
        }

        return $org->edo_provider instanceof EdoProvider ? $org->edo_provider : EdoProvider::None;
    }

    /** Организация подключена к оператору: выбран тот же оператор и есть логин/ключ. */
    public function isConnected(Organization $org): bool
    {
        $provider = $this->providerFor($org);

        return $provider !== EdoProvider::None
            && $org->edo_provider === $provider
            && trim((string) $org->edo_client_id) !== '';
    }

    /**
     * @throws EdoNotConnectedException организация не подключена к оператору из EDO_PROVIDER
     */
    public function forOrganization(Organization $org): EdoOutboundProviderInterface
    {
        // Режим отладки (EDO_MOCK=true) — мок без обращения к оператору
        if (config('edo.mock', false)) {
            return new NullEdoProvider();
        }

        $provider = $this->providerFor($org);

        if (!$this->isConnected($org)) {
            throw EdoNotConnectedException::for($org, $provider);
        }

        return match ($provider) {
            EdoProvider::EdoRu => new EdoRuProvider(
                $this->edoRuClientFactory->make($org),
                new EdoRuMapper(),
                $this->upd503Builder
            ),
            EdoProvider::Taxcom => new TaxcomProvider(
                app(TaxcomClientFactory::class)->make($org),
                new TaxcomMapper(),
            ),
            EdoProvider::Diadoc => new DiadocProvider(
                $this->diadocClientFactory->make($org),
                new DiadocMapper(),
            ),
            EdoProvider::Sbis => new SbisProvider(
                app(SbisClientFactory::class)->make($org),
            ),
            default => throw EdoNotConnectedException::for($org, $provider),
        };
    }

    /**
     * Привязка организации к Диадоку (доступно только для провайдера Diadoc).
     */
    public function connectDiadocOrganization(Organization $org, string $refreshToken, string $boxId): void
    {
        // Проверяем, что организация действительно на Диадоке
        if ($org->edo_provider !== EdoProvider::Diadoc) {
            throw new \RuntimeException("Организация не использует Диадок. Текущий провайдер: {$org->edo_provider?->value}");
        }

        // Создаем провайдер (в обход мока, чтобы реально проверить токен)
        $provider = new DiadocProvider(
            $this->diadocClientFactory->make($org),
            new DiadocMapper(),
        );

        $provider->connectOrganization($org, $refreshToken, $boxId);
    }
}