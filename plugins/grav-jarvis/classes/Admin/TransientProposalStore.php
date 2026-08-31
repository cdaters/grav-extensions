<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Admin;

use RuntimeException;

final class TransientProposalStore
{
    public const LIFETIME_SECONDS = 900;

    public function __construct(private readonly string $directory)
    {
    }

    /** @return array{id: string, expires_at: string} */
    public function issue(
        string $actor,
        string $route,
        string $sourceHash,
        string $proposalHash
    ): array {
        $this->ensureDirectory();
        $this->cleanupExpired();
        $id = bin2hex(random_bytes(16));
        $record = [
            'version' => 1,
            'actor_hash' => hash('sha256', $actor),
            'route_hash' => hash('sha256', $route),
            'source_hash' => $sourceHash,
            'proposal_hash' => $proposalHash,
            'expires' => time() + self::LIFETIME_SECONDS,
        ];
        $encoded = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $path = $this->path($id);
        $handle = @fopen($path, 'x+b');
        if ($handle === false) {
            throw new RuntimeException('Jarvis could not create the temporary proposal receipt.');
        }
        try {
            if (!flock($handle, LOCK_EX)
                || fwrite($handle, $encoded) !== strlen($encoded)
                || !fflush($handle)) {
                throw new RuntimeException('Jarvis could not store the temporary proposal receipt.');
            }
            @chmod($path, 0600);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        return [
            'id' => $id,
            'expires_at' => gmdate('c', $record['expires']),
        ];
    }

    public function consume(
        string $id,
        string $actor,
        string $route,
        string $sourceHash,
        string $proposalHash
    ): void {
        if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
            throw new RuntimeException('The Jarvis proposal receipt is invalid.');
        }
        $path = $this->path($id);
        $handle = @fopen($path, 'r+b');
        if ($handle === false) {
            throw new RuntimeException('The Jarvis proposal has expired or was already accepted.');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('The Jarvis proposal receipt is unavailable.');
            }
            $encoded = stream_get_contents($handle);
            $record = is_string($encoded) ? json_decode($encoded, true) : null;
            if (!is_array($record)
                || ($record['expires'] ?? 0) < time()
                || !hash_equals((string) ($record['actor_hash'] ?? ''), hash('sha256', $actor))
                || !hash_equals((string) ($record['route_hash'] ?? ''), hash('sha256', $route))
                || !hash_equals((string) ($record['source_hash'] ?? ''), $sourceHash)
                || !hash_equals((string) ($record['proposal_hash'] ?? ''), $proposalHash)) {
                throw new RuntimeException('The Jarvis proposal is stale or does not match this page.');
            }
            if (!@unlink($path)) {
                throw new RuntimeException('Jarvis could not consume the one-time proposal receipt.');
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Jarvis could not create its protected temporary receipt directory.');
        }
        @chmod($this->directory, 0700);
    }

    private function cleanupExpired(): void
    {
        $now = time();
        $files = glob($this->directory . '/*.json') ?: [];
        foreach (array_slice($files, 0, 100) as $file) {
            $modified = @filemtime($file);
            if (is_int($modified) && $modified + self::LIFETIME_SECONDS < $now) {
                @unlink($file);
            }
        }
    }

    private function path(string $id): string
    {
        return rtrim($this->directory, '/\\') . '/' . $id . '.json';
    }
}
