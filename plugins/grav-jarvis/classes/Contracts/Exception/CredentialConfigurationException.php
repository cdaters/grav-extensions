<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Contracts\Exception;

final class CredentialConfigurationException extends CredentialException
{
    public function __construct(string $providerId, string $safeReason)
    {
        parent::__construct('Credential configuration for provider ' . $providerId . ' is invalid: ' . $safeReason);
    }
}
