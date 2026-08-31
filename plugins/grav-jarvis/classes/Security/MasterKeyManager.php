<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Security;

use RuntimeException;

final class MasterKeyManager
{
    public const ENVIRONMENT_VARIABLE = 'GRAV_JARVIS_MASTER_KEY';
    private const PREFIX = "JMK1\0";
    private const KEY_BYTES = 32;

    public function __construct(
        private readonly string $directory,
        private readonly string $environmentVariable = self::ENVIRONMENT_VARIABLE
    ) {
    }

    /** @return array{key: string, source: string} */
    public function forNewRecord(bool $recordsExist): array
    {
        $external = $this->external();
        if ($external !== null) {
            return ['key' => $external, 'source' => 'external'];
        }
        return ['key' => $this->local(true, $recordsExist), 'source' => 'local'];
    }

    public function forSource(string $source): string
    {
        return match ($source) {
            'external' => $this->external()
                ?? throw new RuntimeException('This stored Jarvis credential requires the configured external master key.'),
            'local' => $this->local(false, true),
            default => throw new RuntimeException('The stored Jarvis credential names an unsupported master-key source.'),
        };
    }

    /** @return array{configured: bool, valid: bool, source: string, local_key_exists: bool, local_key_valid: bool} */
    public function status(): array
    {
        $raw = getenv($this->environmentVariable);
        $configured = is_string($raw) && $raw !== '';
        $valid = false;
        if ($configured) {
            try {
                $key = $this->decodeExternal($raw);
                $valid = true;
                SodiumAeadBackend::zero($key);
            } catch (RuntimeException) {
                $valid = false;
            }
        }
        $path = $this->path();
        $localExists = is_file($path) && !is_link($path);
        $localValid = false;
        if ($localExists) {
            try {
                $localKey = $this->local(false, true);
                $localValid = true;
                SodiumAeadBackend::zero($localKey);
            } catch (RuntimeException) {
                $localValid = false;
            }
        }
        return [
            'configured' => $configured,
            'valid' => $valid,
            'source' => $configured ? 'external' : 'local-auto-managed',
            'local_key_exists' => $localExists,
            'local_key_valid' => $localValid,
        ];
    }

    private function external(): ?string
    {
        $raw = getenv($this->environmentVariable);
        return is_string($raw) && $raw !== '' ? $this->decodeExternal($raw) : null;
    }

    private function decodeExternal(string $raw): string
    {
        if (!str_starts_with($raw, 'base64:')) {
            throw new RuntimeException('GRAV_JARVIS_MASTER_KEY must use the documented base64: format.');
        }
        $decoded = base64_decode(substr($raw, 7), true);
        if (!is_string($decoded) || strlen($decoded) !== self::KEY_BYTES) {
            throw new RuntimeException('GRAV_JARVIS_MASTER_KEY must contain exactly 32 random bytes encoded as base64.');
        }
        return $decoded;
    }

    private function local(bool $create, bool $recordsExist): string
    {
        $this->assertSafeDirectory($create);
        $path = $this->path();
        if (is_link($path)) {
            throw new RuntimeException('Jarvis refused a symbolic-link master-key path.');
        }
        if (!is_file($path)) {
            if (!$create || $recordsExist) {
                throw new RuntimeException('The Jarvis local master key is missing; existing credentials were not modified.');
            }
            $this->createLocal($path);
        }
        $record = @file_get_contents($path);
        if (!is_string($record) || !str_starts_with($record, self::PREFIX)) {
            throw new RuntimeException('The Jarvis local master key is malformed.');
        }
        $key = substr($record, strlen(self::PREFIX));
        SodiumAeadBackend::zero($record);
        if (strlen($key) !== self::KEY_BYTES) {
            SodiumAeadBackend::zero($key);
            throw new RuntimeException('The Jarvis local master key is malformed.');
        }
        @chmod($path, 0600);
        return $key;
    }

    private function createLocal(string $path): void
    {
        $key = random_bytes(self::KEY_BYTES);
        $handle = @fopen($path, 'x+b');
        if ($handle === false) {
            SodiumAeadBackend::zero($key);
            if (is_file($path) && !is_link($path)) {
                return;
            }
            throw new RuntimeException('Jarvis could not create its protected local master key.');
        }
        try {
            @chmod($path, 0600);
            $record = self::PREFIX . $key;
            if (fwrite($handle, $record) !== strlen($record) || !fflush($handle)) {
                throw new RuntimeException('Jarvis could not persist its protected local master key.');
            }
            if (function_exists('fsync')) {
                @fsync($handle);
            }
        } catch (\Throwable $error) {
            fclose($handle);
            @unlink($path);
            SodiumAeadBackend::zero($key);
            throw $error;
        }
        fclose($handle);
        SodiumAeadBackend::zero($key);
    }

    private function assertSafeDirectory(bool $create): void
    {
        if ($this->directory === '' || str_contains($this->directory, "\0")) {
            throw new RuntimeException('The Jarvis credential directory is invalid.');
        }
        if (is_link($this->directory)) {
            throw new RuntimeException('Jarvis refused a symbolic-link credential directory.');
        }
        if (!is_dir($this->directory) && $create && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Jarvis could not create its protected credential directory.');
        }
        if (!is_dir($this->directory)) {
            throw new RuntimeException('The Jarvis credential directory is unavailable.');
        }
        @chmod($this->directory, 0700);
    }

    private function path(): string
    {
        return rtrim($this->directory, '/\\') . '/master.key';
    }
}
