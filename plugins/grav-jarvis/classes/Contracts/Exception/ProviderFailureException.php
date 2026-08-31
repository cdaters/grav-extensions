<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Contracts\Exception;

final class ProviderFailureException extends JarvisException
{
    public readonly string $providerId;
    public readonly string $category;
    public readonly bool $retryable;

    public function __construct(
        string $providerId,
        string $safeMessage,
        string $category = 'provider_unavailable',
        bool $retryable = true
    ) {
        $this->providerId = $providerId;
        $this->category = $category;
        $this->retryable = $retryable;
        parent::__construct('Provider ' . $providerId . ' failed: ' . $safeMessage);
    }
}
