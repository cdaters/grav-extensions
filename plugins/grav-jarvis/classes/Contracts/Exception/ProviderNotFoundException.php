<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Contracts\Exception;

final class ProviderNotFoundException extends JarvisException
{
    public function __construct(string $providerId)
    {
        parent::__construct('Jarvis provider is not registered: ' . $providerId);
    }
}
