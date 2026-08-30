<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Contracts\Exception;

final class MalformedCredentialException extends CredentialException
{
    public function __construct(string $providerId, string $environmentVariable)
    {
        parent::__construct(
            'Provider ' . $providerId . ' found a malformed value in environment variable '
            . $environmentVariable . '.'
        );
    }
}
