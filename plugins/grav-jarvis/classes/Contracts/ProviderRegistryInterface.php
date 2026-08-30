<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Contracts;

interface ProviderRegistryInterface
{
    public function register(ProviderInterface $provider): void;

    public function has(string $providerId): bool;

    public function get(string $providerId): ProviderInterface;

    /** @return array<string, ProviderInterface> */
    public function all(): array;

    /** @return list<string> */
    public function ids(): array;
}
