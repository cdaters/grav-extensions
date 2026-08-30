<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Contracts\Exception;

final class ProviderFailureException extends JarvisException
{
    public readonly string $providerId;

    public function __construct(string $providerId, string $safeMessage)
    {
        $this->providerId = $providerId;
        parent::__construct('Provider ' . $providerId . ' failed: ' . $safeMessage);
    }
}
