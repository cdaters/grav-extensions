<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Security;

use Grav\Plugin\GravJarvis\Contracts\CompletionRequest;
use Grav\Plugin\GravJarvis\Contracts\CredentialResolverInterface;
use Grav\Plugin\GravJarvis\Contracts\CredentialValueInterface;
use Grav\Plugin\GravJarvis\Contracts\Exception\CredentialConfigurationException;
use Grav\Plugin\GravJarvis\Contracts\Exception\MalformedCredentialException;
use Grav\Plugin\GravJarvis\Contracts\Exception\MissingCredentialException;
use InvalidArgumentException;

final class EnvironmentCredentialResolver implements CredentialResolverInterface
{
    private readonly string $providerId;
    private readonly string $expectedPrefix;

    public function __construct(string $providerId)
    {
        $providerId = trim($providerId);
        if (!CompletionRequest::validIdentifier($providerId)) {
            throw new InvalidArgumentException('Provider identifiers must be lowercase stable slugs.');
        }
        $this->providerId = $providerId;
        $this->expectedPrefix = 'GRAV_JARVIS_'
            . strtoupper(str_replace(['-', '.'], '_', $providerId)) . '_';
    }

    public function providerId(): string
    {
        return $this->providerId;
    }

    public function resolve(string $environmentVariable): CredentialValueInterface
    {
        $environmentVariable = trim($environmentVariable);
        if (preg_match('/^GRAV_JARVIS_[A-Z][A-Z0-9_]{2,120}$/D', $environmentVariable) !== 1) {
            throw new CredentialConfigurationException(
                $this->providerId,
                'the environment-variable name is not a valid GRAV_JARVIS_* name'
            );
        }
        if (!str_starts_with($environmentVariable, $this->expectedPrefix)) {
            throw new CredentialConfigurationException(
                $this->providerId,
                'the environment-variable name belongs to a different provider namespace'
            );
        }

        $value = getenv($environmentVariable);
        if ($value === false || $value === '') {
            throw new MissingCredentialException($this->providerId, $environmentVariable);
        }
        if (trim($value) !== $value
            || strlen($value) > 8192
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new MalformedCredentialException($this->providerId, $environmentVariable);
        }

        return new EnvironmentCredential($environmentVariable, $value);
    }
}
