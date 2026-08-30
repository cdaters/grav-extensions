<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Contracts\Exception;

final class DuplicateProviderException extends JarvisException
{
    public function __construct(string $providerId)
    {
        parent::__construct('Jarvis provider is already registered: ' . $providerId);
    }
}
