<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Admin;

use Closure;
use RuntimeException;

final class TransientProposalStore
{
    public const LIFETIME_SECONDS = 900;
    public const MAX_ACTIVE_RECEIPTS = 128;

    private readonly Closure $clock;

    public function __construct(private readonly string $directory, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /** @return array{id: string, expires_at: string} */
    public function issue(
        string $actor,
        string $route,
        string $sourceHash,
        string $proposalHash
    ): array {
        $this->ensureDirectory();
        $directoryLock = @fopen($this->directory . '/.lock', 'c+b');
        if ($directoryLock === false || !flock($directoryLock, LOCK_EX)) {
            if (is_resource($directoryLock)) fclose($directoryLock);
            throw new RuntimeException('Jarvis temporary proposal storage is unavailable.');
        }
        try {
            @chmod($this->directory . '/.lock', 0600);
            $this->cleanupExpired();
            if (count($this->receiptFiles()) >= self::MAX_ACTIVE_RECEIPTS) {
                throw new RuntimeException('Jarvis temporary proposal storage is at capacity.');
            }
            $id = bin2hex(random_bytes(16));
            $now = ($this->clock)();
            $record = [
                'version' => 2,
                'actor_hash' => hash('sha256', $actor),
                'route_hash' => hash('sha256', $route),
                'source_hash' => $sourceHash,
                'proposal_hash' => $proposalHash,
                'issued' => $now,
                'expires' => $now + self::LIFETIME_SECONDS,
            ];
            $encoded = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $path = $this->path($id);
            $handle = @fopen($path, 'x+b');
            if ($handle === false) {
                throw new RuntimeException('Jarvis could not create the temporary proposal receipt.');
            }
            $stored = false;
            try {
                $stored = flock($handle, LOCK_EX)
                    && fwrite($handle, $encoded) === strlen($encoded)
                    && fflush($handle);
                if (!$stored) {
                    throw new RuntimeException('Jarvis could not store the temporary proposal receipt.');
                }
                @chmod($path, 0600);
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
                if (!$stored) @unlink($path);
            }
            return [
                'id' => $id,
                'expires_at' => gmdate('c', $record['expires']),
            ];
        } finally {
            flock($directoryLock, LOCK_UN);
            fclose($directoryLock);
        }
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
        $this->withReceipt($id, $actor, $route, static function (array $record) use ($sourceHash, $proposalHash): void {
            if (!hash_equals((string) ($record['source_hash'] ?? ''), $sourceHash)
                || !hash_equals((string) ($record['proposal_hash'] ?? ''), $proposalHash)) {
                throw new RuntimeException('The Jarvis proposal is stale or does not match this page.');
            }
        });
    }

    public function revoke(string $id, string $actor, string $route): void
    {
        $this->withReceipt($id, $actor, $route, static function (): void {
        });
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
        $now = ($this->clock)();
        foreach ($this->receiptFiles() as $file) {
            $record = json_decode((string) @file_get_contents($file), true);
            if (!is_array($record) || (int) ($record['expires'] ?? 0) <= $now) {
                @unlink($file);
            }
        }
    }

    /** @return list<string> */
    private function receiptFiles(): array
    {
        $files = glob($this->directory . '/*.json') ?: [];
        sort($files, SORT_STRING);
        return array_values($files);
    }

    /** @param callable(array<string, mixed>): void $verify */
    private function withReceipt(string $id, string $actor, string $route, callable $verify): void
    {
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
                || (int) ($record['expires'] ?? 0) <= ($this->clock)()) {
                @unlink($path);
                throw new RuntimeException('The Jarvis proposal has expired or was already accepted.');
            }
            if (!hash_equals((string) ($record['actor_hash'] ?? ''), hash('sha256', $actor))
                || !hash_equals((string) ($record['route_hash'] ?? ''), hash('sha256', $route))) {
                throw new RuntimeException('The Jarvis proposal is stale or does not match this page.');
            }
            $verify($record);
            if (!@unlink($path)) {
                throw new RuntimeException('Jarvis could not consume the one-time proposal receipt.');
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function path(string $id): string
    {
        return rtrim($this->directory, '/\\') . '/' . $id . '.json';
    }
}
