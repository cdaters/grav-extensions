<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Security;

use Grav\Plugin\GravJarvis\Contracts\CompletionRequest;
use JsonException;
use RuntimeException;
use Throwable;

final class EncryptedCredentialStore
{
    public const FORMAT_VERSION = 1;
    /** @var array<string, AeadBackendInterface> */
    private array $backends = [];

    /** @param list<AeadBackendInterface>|null $backends */
    public function __construct(
        private readonly string $directory,
        private readonly MasterKeyManager $masterKeys,
        ?array $backends = null
    ) {
        foreach ($backends ?? [new SodiumAeadBackend(), new OpenSslAeadBackend()] as $backend) {
            $this->backends[$backend->id()] = $backend;
        }
    }

    public function availableBackend(): ?AeadBackendInterface
    {
        foreach ([SodiumAeadBackend::ID, OpenSslAeadBackend::ID] as $id) {
            if (($this->backends[$id] ?? null)?->available()) {
                return $this->backends[$id];
            }
        }
        return null;
    }

    /** @return array<string, mixed> */
    public function save(string $providerId, #[\SensitiveParameter] string $credential): array
    {
        $this->assertProvider($providerId);
        $this->assertCredential($credential);
        $backend = $this->availableBackend()
            ?? throw new RuntimeException('No authenticated local credential encryption backend is available. Use an environment credential.');
        $this->ensureDirectory();
        $keyRecord = $this->masterKeys->forNewRecord($this->recordsExist());
        $key = $keyRecord['key'];
        try {
            $record = [
                'format_version' => self::FORMAT_VERSION,
                'credential_id' => $providerId,
                'backend' => $backend->id(),
                'master_key_source' => $keyRecord['source'],
                'aad_version' => 1,
                ...$backend->encrypt($credential, $key, $this->aad($providerId)),
                'updated_at' => gmdate('c'),
            ];
            $this->atomicJson($this->path($providerId), $record);
            return $this->metadata($record);
        } finally {
            SodiumAeadBackend::zero($key);
        }
    }

    public function reveal(string $providerId): string
    {
        $record = $this->record($providerId);
        $backendId = $record['backend'] ?? null;
        $backend = is_string($backendId) ? ($this->backends[$backendId] ?? null) : null;
        if (!$backend instanceof AeadBackendInterface) {
            throw new RuntimeException('The stored Jarvis credential uses an unsupported encryption backend.');
        }
        if (!$backend->available()) {
            throw new RuntimeException('The stored Jarvis credential requires an encryption backend unavailable on this host.');
        }
        $source = $record['master_key_source'] ?? null;
        if (!is_string($source)) {
            throw new RuntimeException('The stored Jarvis credential record is malformed.');
        }
        $key = $this->masterKeys->forSource($source);
        try {
            $credential = $backend->decrypt($record, $key, $this->aad($providerId));
            $this->assertCredential($credential);
            return $credential;
        } finally {
            SodiumAeadBackend::zero($key);
        }
    }

    public function remove(string $providerId): bool
    {
        $this->assertProvider($providerId);
        $path = $this->path($providerId);
        if (is_link($path)) {
            throw new RuntimeException('Jarvis refused a symbolic-link credential path.');
        }
        return !file_exists($path) || @unlink($path);
    }

    public function exists(string $providerId): bool
    {
        $this->assertProvider($providerId);
        $path = $this->path($providerId);
        return is_file($path) && !is_link($path);
    }

    /** @return array<string, mixed>|null */
    public function inspect(string $providerId): ?array
    {
        if (!$this->exists($providerId)) {
            return null;
        }
        try {
            return $this->metadata($this->record($providerId));
        } catch (Throwable) {
            return ['status' => 'invalid', 'backend' => null, 'master_key_source' => null];
        }
    }

    /** @return array<string, mixed> */
    public function readiness(): array
    {
        $sodium = ($this->backends[SodiumAeadBackend::ID] ?? null)?->available() ?? false;
        $openssl = ($this->backends[OpenSslAeadBackend::ID] ?? null)?->available() ?? false;
        $pathReady = $this->directoryReady();
        $master = $this->masterKeys->status();
        $masterReady = $master['configured']
            ? $master['valid']
            : ($master['local_key_exists'] ? $master['local_key_valid'] : !$this->recordsExist());
        return [
            'best_backend' => $sodium ? 'sodium' : ($openssl ? 'openssl' : null),
            'local_storage_available' => ($sodium || $openssl) && $pathReady && $masterReady,
            'master_key' => $master,
            'requirements' => [
                ['key' => 'php', 'label' => 'Supported PHP version', 'group' => 'required', 'available' => PHP_VERSION_ID >= 80300, 'detail' => PHP_VERSION],
                ['key' => 'curl', 'label' => 'HTTPS provider transport', 'group' => 'required', 'available' => extension_loaded('curl'), 'detail' => extension_loaded('curl') ? 'cURL available' : 'cURL unavailable'],
                ['key' => 'sodium', 'label' => 'Preferred encrypted local storage', 'group' => 'recommended', 'available' => $sodium, 'detail' => $sodium ? 'Sodium available' : 'Sodium unavailable'],
                ['key' => 'openssl_gcm', 'label' => 'Authenticated encryption fallback', 'group' => 'fallback', 'available' => $openssl, 'detail' => $openssl ? 'OpenSSL AES-256-GCM available' : 'OpenSSL AES-256-GCM unavailable'],
                ['key' => 'data_directory', 'label' => 'Protected Jarvis data directory', 'group' => 'required_local', 'available' => $pathReady, 'detail' => $pathReady ? 'Writable without symbolic links' : 'Unavailable or not safely writable'],
                ['key' => 'master_key_storage', 'label' => 'Local master-key storage', 'group' => 'required_local', 'available' => $masterReady, 'detail' => $master['configured'] ? 'External master-key mode' : ($master['local_key_exists'] ? ($master['local_key_valid'] ? 'Protected local key is readable' : 'Local key is corrupt or unreadable') : ($this->recordsExist() ? 'Local key is missing for existing records' : 'Ready for safe first-use creation'))],
                ['key' => 'environment', 'label' => 'Environment credentials', 'group' => 'required', 'available' => function_exists('getenv'), 'detail' => 'Direct provider variables remain supported'],
                ['key' => 'external_master_key', 'label' => 'External master key', 'group' => 'optional', 'available' => $master['configured'] && $master['valid'], 'detail' => $master['configured'] ? ($master['valid'] ? 'Configured' : 'Configured but invalid') : 'Not configured'],
            ],
        ];
    }

    private function record(string $providerId): array
    {
        $this->assertProvider($providerId);
        $path = $this->path($providerId);
        if (is_link($path) || !is_file($path)) {
            throw new RuntimeException('No encrypted local credential is stored for this provider.');
        }
        $raw = @file_get_contents($path);
        try {
            $record = is_string($raw) ? json_decode($raw, true, 32, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            throw new RuntimeException('The stored Jarvis credential record is malformed.');
        }
        if (!is_array($record)
            || ($record['format_version'] ?? null) !== self::FORMAT_VERSION
            || ($record['credential_id'] ?? null) !== $providerId
            || ($record['aad_version'] ?? null) !== 1) {
            throw new RuntimeException('The stored Jarvis credential record has an unsupported version or identity.');
        }
        return $record;
    }

    /** @param array<string, mixed> $record @return array<string, mixed> */
    private function metadata(array $record): array
    {
        return [
            'status' => 'configured',
            'backend' => match ($record['backend'] ?? null) {
                SodiumAeadBackend::ID => 'sodium',
                OpenSslAeadBackend::ID => 'openssl',
                default => 'unsupported',
            },
            'master_key_source' => $record['master_key_source'] ?? null,
            'updated_at' => $record['updated_at'] ?? null,
        ];
    }

    private function atomicJson(string $path, array $record): void
    {
        try {
            $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        } catch (JsonException) {
            throw new RuntimeException('Jarvis could not encode the encrypted credential record.');
        }
        if (is_link($path)) {
            throw new RuntimeException('Jarvis refused a symbolic-link credential path.');
        }
        $temporary = dirname($path) . '/.credential-' . bin2hex(random_bytes(12)) . '.tmp';
        $handle = @fopen($temporary, 'x+b');
        if ($handle === false) {
            throw new RuntimeException('Jarvis could not create an atomic credential record.');
        }
        try {
            @chmod($temporary, 0600);
            if (fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
                throw new RuntimeException('Jarvis could not persist the encrypted credential record.');
            }
            if (function_exists('fsync')) @fsync($handle);
            fclose($handle);
            $handle = null;
            if (!@rename($temporary, $path)) {
                throw new RuntimeException('Jarvis could not atomically replace the encrypted credential record.');
            }
            @chmod($path, 0600);
        } finally {
            if (is_resource($handle)) fclose($handle);
            if (is_file($temporary)) @unlink($temporary);
        }
    }

    private function ensureDirectory(): void
    {
        if (is_link($this->directory)) {
            throw new RuntimeException('Jarvis refused a symbolic-link credential directory.');
        }
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Jarvis could not create its protected credential directory.');
        }
        @chmod($this->directory, 0700);
        if (!is_dir($this->directory) || !is_writable($this->directory)) {
            throw new RuntimeException('The protected Jarvis credential directory is not writable.');
        }
    }

    private function directoryReady(): bool
    {
        if (is_link($this->directory)) return false;
        if (is_dir($this->directory)) return is_writable($this->directory);
        $parent = dirname($this->directory);
        while (!is_dir($parent) && dirname($parent) !== $parent) $parent = dirname($parent);
        return is_dir($parent) && is_writable($parent) && !is_link($parent);
    }

    private function recordsExist(): bool
    {
        return is_dir($this->directory) && (glob($this->directory . '/*.credential.json') ?: []) !== [];
    }

    private function path(string $providerId): string
    {
        return rtrim($this->directory, '/\\') . '/' . $providerId . '.credential.json';
    }

    private function aad(string $providerId): string
    {
        return 'grav-jarvis|credential|' . self::FORMAT_VERSION . '|' . $providerId;
    }

    private function assertProvider(string $providerId): void
    {
        if (!CompletionRequest::validIdentifier($providerId)) {
            throw new RuntimeException('The Jarvis credential provider identifier is invalid.');
        }
    }

    private function assertCredential(string $credential): void
    {
        if ($credential === '' || trim($credential) !== $credential || strlen($credential) > 8192
            || preg_match('/[\x00-\x1F\x7F]/', $credential) === 1) {
            throw new RuntimeException('The submitted provider credential is empty or malformed.');
        }
    }
}
