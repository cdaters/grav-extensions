<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Provider;

use Grav\Plugin\GravJarvis\Contracts\CompletionRequest;
use Grav\Plugin\GravJarvis\Contracts\Exception\DuplicateProviderException;
use Grav\Plugin\GravJarvis\Contracts\Exception\ProviderNotFoundException;
use Grav\Plugin\GravJarvis\Contracts\ProviderInterface;
use Grav\Plugin\GravJarvis\Contracts\ProviderRegistryInterface;
use InvalidArgumentException;

final class ProviderRegistry implements ProviderRegistryInterface
{
    /** @var array<string, ProviderInterface> */
    private array $providers = [];

    public function register(ProviderInterface $provider): void
    {
        $providerId = trim($provider->id());
        if (!CompletionRequest::validIdentifier($providerId)) {
            throw new InvalidArgumentException('Provider identifiers must be lowercase stable slugs.');
        }
        if (isset($this->providers[$providerId])) {
            throw new DuplicateProviderException($providerId);
        }
        $this->providers[$providerId] = $provider;
        ksort($this->providers, SORT_STRING);
    }

    public function has(string $providerId): bool
    {
        return isset($this->providers[$providerId]);
    }

    public function get(string $providerId): ProviderInterface
    {
        if (!$this->has($providerId)) {
            throw new ProviderNotFoundException($providerId);
        }
        return $this->providers[$providerId];
    }

    public function all(): array
    {
        return $this->providers;
    }

    public function ids(): array
    {
        return array_keys($this->providers);
    }
}
