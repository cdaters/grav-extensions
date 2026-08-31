<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Security;

use Grav\Plugin\GravJarvis\Contracts\Exception\MalformedCredentialException;
use Grav\Plugin\GravJarvis\Contracts\Exception\MissingCredentialException;
use RuntimeException;
use Throwable;

final class CredentialManager
{
    /** @param array<string, string> $environmentVariables */
    public function __construct(
        private readonly EncryptedCredentialStore $store,
        private readonly array $environmentVariables
    ) {
    }

    public function resolver(string $providerId): CompositeCredentialResolver
    {
        $this->environmentVariable($providerId);
        return new CompositeCredentialResolver($providerId, $this->store);
    }

    /** @return array<string, mixed> */
    public function status(string $providerId): array
    {
        $environmentVariable = $this->environmentVariable($providerId);
        $stored = $this->store->inspect($providerId);
        try {
            (new EnvironmentCredentialResolver($providerId))->resolve($environmentVariable);
            return [
                'status' => 'configured',
                'source' => 'environment',
                'backend' => null,
                'stored_credential_present' => $stored !== null,
                'stored_credential_inactive' => $stored !== null,
                'stored_credential' => $stored,
            ];
        } catch (MalformedCredentialException) {
            return [
                'status' => 'invalid', 'source' => 'environment', 'backend' => null,
                'stored_credential_present' => $stored !== null,
                'stored_credential_inactive' => $stored !== null,
                'stored_credential' => $stored,
            ];
        } catch (MissingCredentialException) {
            // Continue to the encrypted local source.
        }
        if ($stored === null) {
            return [
                'status' => 'missing', 'source' => 'missing', 'backend' => null,
                'stored_credential_present' => false, 'stored_credential_inactive' => false,
                'stored_credential' => null,
            ];
        }
        try {
            $credential = $this->store->reveal($providerId);
            SodiumAeadBackend::zero($credential);
            return [
                'status' => 'configured', 'source' => 'encrypted_local',
                'backend' => $stored['backend'] ?? null,
                'master_key_source' => $stored['master_key_source'] ?? null,
                'stored_credential_present' => true, 'stored_credential_inactive' => false,
                'stored_credential' => $stored,
            ];
        } catch (Throwable) {
            return [
                'status' => 'invalid', 'source' => 'encrypted_local',
                'backend' => $stored['backend'] ?? null,
                'master_key_source' => $stored['master_key_source'] ?? null,
                'stored_credential_present' => true, 'stored_credential_inactive' => false,
                'stored_credential' => $stored,
            ];
        }
    }

    /** @return array<string, mixed> */
    public function save(string $providerId, #[\SensitiveParameter] string $credential): array
    {
        $this->environmentVariable($providerId);
        return $this->store->save($providerId, $credential);
    }

    public function remove(string $providerId): bool
    {
        $this->environmentVariable($providerId);
        return $this->store->remove($providerId);
    }

    public function supportsAdminCredential(string $providerId): bool
    {
        return isset($this->environmentVariables[$providerId]);
    }

    /** @return array<string, mixed> */
    public function readiness(): array
    {
        return $this->store->readiness();
    }

    private function environmentVariable(string $providerId): string
    {
        $value = $this->environmentVariables[$providerId] ?? null;
        if (!is_string($value)) {
            throw new RuntimeException('Jarvis does not manage credentials for this provider.');
        }
        return $value;
    }
}
