<?php

declare(strict_types=1);

namespace Grav\Plugin\GravCaxton\Extension;

use Grav\Plugin\GravCaxton\Contracts\CaxtonExtensionInterface;
use Grav\Plugin\GravCaxton\Contracts\Exception\DuplicateExtensionException;
use Grav\Plugin\GravCaxton\Contracts\ExtensionRegistryInterface;
use InvalidArgumentException;

final class ExtensionRegistry implements ExtensionRegistryInterface
{
    /** @var array<string, CaxtonExtensionInterface> */
    private array $extensions = [];

    public function register(CaxtonExtensionInterface $extension): void
    {
        $id = $extension->id();
        if (!preg_match('/^[a-z0-9][a-z0-9._-]*\/[a-z0-9][a-z0-9._-]*$/', $id)) {
            throw new InvalidArgumentException('Caxton extension IDs must be namespaced, for example vendor/feature.');
        }
        if (isset($this->extensions[$id])) {
            throw new DuplicateExtensionException('Caxton extension already registered: ' . $id);
        }
        $this->extensions[$id] = $extension;
        ksort($this->extensions, SORT_STRING);
    }

    public function has(string $id): bool
    {
        return isset($this->extensions[$id]);
    }

    public function get(string $id): ?CaxtonExtensionInterface
    {
        return $this->extensions[$id] ?? null;
    }

    public function all(): array
    {
        return array_values($this->extensions);
    }
}
