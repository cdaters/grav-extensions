<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Contracts\Exception;

final class MissingCredentialException extends CredentialException
{
    public function __construct(string $providerId, string $environmentVariable)
    {
        parent::__construct(
            'Provider ' . $providerId . ' requires environment variable ' . $environmentVariable . '.'
        );
    }
}
