<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Contracts;

interface ModelDiscoveryInterface
{
    public function discoverModels(): ModelCatalog;
}
