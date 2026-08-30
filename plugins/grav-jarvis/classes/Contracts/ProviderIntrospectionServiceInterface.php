<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Contracts;

interface ProviderIntrospectionServiceInterface extends JarvisServiceInterface
{
    public function validateProvider(string $providerId): ProviderValidationResult;

    public function discoverModels(string $providerId): ModelCatalog;
}
