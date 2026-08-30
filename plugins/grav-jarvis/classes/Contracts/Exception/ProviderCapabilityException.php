<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Contracts\Exception;

final class ProviderCapabilityException extends JarvisException
{
    public function __construct(string $providerId, string $capability)
    {
        parent::__construct('Provider ' . $providerId . ' does not support ' . $capability . '.');
    }
}
