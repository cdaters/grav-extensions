<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Contracts\Exception;

final class ProviderFailureException extends JarvisException
{
    public readonly string $providerId;
    public readonly string $category;
    public readonly bool $retryable;
    public readonly ?int $retryAfterSeconds;

    public function __construct(
        string $providerId,
        string $safeMessage,
        string $category = 'provider_unavailable',
        bool $retryable = true,
        ?int $retryAfterSeconds = null
    ) {
        $this->providerId = $providerId;
        $this->category = $category;
        $this->retryable = $retryable;
        $this->retryAfterSeconds = $retryAfterSeconds === null ? null : max(0, min(3600, $retryAfterSeconds));
        parent::__construct('Provider ' . $providerId . ' failed: ' . $safeMessage);
    }
}
