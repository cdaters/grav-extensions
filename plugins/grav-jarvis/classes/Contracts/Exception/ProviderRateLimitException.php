<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Contracts\Exception;

final class ProviderRateLimitException extends ProviderException
{
    public function __construct(string $message, public readonly ?int $retryAfterSeconds = null)
    {
        parent::__construct($message);
    }
}
