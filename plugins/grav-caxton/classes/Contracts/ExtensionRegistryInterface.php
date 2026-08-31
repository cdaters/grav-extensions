<?php

declare(strict_types=1);

namespace Grav\Plugin\GravCaxton\Contracts;

interface ExtensionRegistryInterface
{
    public function register(CaxtonExtensionInterface $extension): void;

    public function has(string $id): bool;

    public function get(string $id): ?CaxtonExtensionInterface;

    /** @return list<CaxtonExtensionInterface> */
    public function all(): array;
}
