<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Security;

use Grav\Plugin\GravJarvis\Contracts\CredentialResolverInterface;
use Grav\Plugin\GravJarvis\Contracts\CredentialValueInterface;
use Grav\Plugin\GravJarvis\Contracts\Exception\MalformedCredentialException;
use Grav\Plugin\GravJarvis\Contracts\Exception\MissingCredentialException;
use RuntimeException;

final class CompositeCredentialResolver implements CredentialResolverInterface
{
    private readonly EnvironmentCredentialResolver $environment;

    public function __construct(
        private readonly string $providerId,
        private readonly EncryptedCredentialStore $store
    ) {
        $this->environment = new EnvironmentCredentialResolver($providerId);
    }

    public function providerId(): string { return $this->providerId; }

    public function resolve(string $environmentVariable): CredentialValueInterface
    {
        try {
            return $this->environment->resolve($environmentVariable);
        } catch (MissingCredentialException) {
            // Environment absence alone permits the encrypted local fallback.
        }
        try {
            return new StoredCredential($environmentVariable, $this->store->reveal($this->providerId));
        } catch (RuntimeException) {
            if (!$this->store->exists($this->providerId)) {
                throw new MissingCredentialException($this->providerId, $environmentVariable);
            }
            throw new MalformedCredentialException($this->providerId, $environmentVariable);
        }
    }
}
