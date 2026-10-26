<?php

declare(strict_types=1);

namespace App\Services\Edo;

use App\Contracts\Edo\EdoProviderInterface;
use App\Enums\Edo\EdoProvider as EdoProviderEnum;
use App\Exceptions\EdoException;
use App\Services\Edo\Drivers\Null\NullEdoProvider;
use Illuminate\Contracts\Container\Container;

final class EdoManager
{
    /** @var array<string, EdoProviderInterface> */
    private array $resolved = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * Резолвит провайдера по имени. Если не задано — берёт config('edo.default').
     */
    public function provider(?string $name = null): EdoProviderInterface
    {
        $name = $name ?? (string) config('edo.default', EdoProviderEnum::None->value);

        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        return $this->resolved[$name] = $this->resolve($name);
    }

    public function isConfigured(): bool
    {
        $name = (string) config('edo.default', EdoProviderEnum::None->value);

        return $name !== EdoProviderEnum::None->value;
    }

    private function resolve(string $name): EdoProviderInterface
    {
        if ($name === EdoProviderEnum::None->value) {
            return new NullEdoProvider();
        }

        $config = config("edo.providers.{$name}");

        if (!is_array($config) || !isset($config['class'])) {
            throw EdoException::providerUnavailable($name);
        }

        /** @var class-string<EdoProviderInterface> $class */
        $class = $config['class'];

        if (!class_exists($class)) {
            throw EdoException::providerUnavailable($name);
        }

        return $this->container->make($class);
    }
}