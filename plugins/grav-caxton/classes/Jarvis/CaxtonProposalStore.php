<?php

declare(strict_types=1);

namespace Grav\Plugin\GravCaxton\Jarvis;

use Closure;
use RuntimeException;

/** Hash-only, actor/page/range-bound, one-time receipts for unsaved editor proposals. */
final class CaxtonProposalStore
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
        int $from,
        int $to,
        string $targetHash,
        string $proposalHash,
        string $action,
        bool $mutable
    ): array {
        $this->ensureDirectory();
        $directoryLock = @fopen($this->directory . '/.lock', 'c+b');
        if ($directoryLock === false || !flock($directoryLock, LOCK_EX)) {
            if (is_resource($directoryLock)) fclose($directoryLock);
            throw new RuntimeException('Caxton proposal storage is unavailable.');
        }
        try {
            @chmod($this->directory . '/.lock', 0600);
            $this->cleanupExpired();
            if (count($this->receiptFiles()) >= self::MAX_ACTIVE_RECEIPTS) {
                throw new RuntimeException('Caxton proposal storage is at capacity.');
            }
            $id = bin2hex(random_bytes(16));
            $now = ($this->clock)();
            $record = [
                'version' => 1,
                'actor_hash' => hash('sha256', $actor),
                'route_hash' => hash('sha256', $route),
                'source_hash' => $sourceHash,
                'from' => $from,
                'to' => $to,
                'target_hash' => $targetHash,
                'proposal_hash' => $proposalHash,
                'action' => $action,
                'mutable' => $mutable,
                'expires' => $now + self::LIFETIME_SECONDS,
            ];
            $encoded = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $path = $this->path($id);
            $handle = @fopen($path, 'x+b');
            if ($handle === false) throw new RuntimeException('Caxton could not create a proposal receipt.');
            $stored = false;
            try {
                $stored = flock($handle, LOCK_EX)
                    && fwrite($handle, $encoded) === strlen($encoded)
                    && fflush($handle);
                if (!$stored) throw new RuntimeException('Caxton could not store a proposal receipt.');
                @chmod($path, 0600);
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
                if (!$stored) @unlink($path);
            }
            return ['id' => $id, 'expires_at' => gmdate('c', $record['expires'])];
        } finally {
            flock($directoryLock, LOCK_UN);
            fclose($directoryLock);
        }
    }

    /** @return array{from: int, to: int, action: string, mutable: bool} */
    public function consume(
        string $id,
        string $actor,
        string $route,
        string $sourceHash,
        int $from,
        int $to,
        string $targetHash,
        string $proposalHash
    ): array {
        return $this->withReceipt($id, $actor, $route, static function (array $record) use ($sourceHash, $from, $to, $targetHash, $proposalHash): array {
            foreach (['source_hash' => $sourceHash, 'target_hash' => $targetHash, 'proposal_hash' => $proposalHash] as $key => $value) {
                if (!hash_equals((string) ($record[$key] ?? ''), $value)) {
                    throw new RuntimeException('The Caxton proposal is stale or belongs to another buffer.');
                }
            }
            if ((int) ($record['from'] ?? -1) !== $from || (int) ($record['to'] ?? -1) !== $to) {
                throw new RuntimeException('The Caxton proposal range is stale or invalid.');
            }
            return [
                'from' => (int) ($record['from'] ?? -1),
                'to' => (int) ($record['to'] ?? -1),
                'action' => (string) ($record['action'] ?? ''),
                'mutable' => (bool) ($record['mutable'] ?? false),
            ];
        });
    }

    public function revoke(string $id, string $actor, string $route): void
    {
        $this->withReceipt($id, $actor, $route, static fn (): null => null);
    }

    private function withReceipt(string $id, string $actor, string $route, callable $verify): mixed
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) throw new RuntimeException('Invalid proposal receipt.');
        $path = $this->path($id);
        $handle = @fopen($path, 'r+b');
        if ($handle === false) throw new RuntimeException('Proposal expired or was already closed.');
        try {
            if (!flock($handle, LOCK_EX)) throw new RuntimeException('Proposal receipt is unavailable.');
            $record = json_decode((string) stream_get_contents($handle), true);
            if (!is_array($record) || (int) ($record['expires'] ?? 0) <= ($this->clock)()) {
                @unlink($path);
                throw new RuntimeException('Proposal expired or was already closed.');
            }
            if (!hash_equals((string) ($record['actor_hash'] ?? ''), hash('sha256', $actor))
                || !hash_equals((string) ($record['route_hash'] ?? ''), hash('sha256', $route))) {
                throw new RuntimeException('Proposal belongs to another user or page.');
            }
            $result = $verify($record);
            if (!@unlink($path)) throw new RuntimeException('Caxton could not close the proposal receipt.');
            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Caxton could not create protected proposal storage.');
        }
        @chmod($this->directory, 0700);
    }

    private function cleanupExpired(): void
    {
        $now = ($this->clock)();
        foreach ($this->receiptFiles() as $file) {
            $record = json_decode((string) @file_get_contents($file), true);
            if (!is_array($record) || (int) ($record['expires'] ?? 0) <= $now) @unlink($file);
        }
    }

    /** @return list<string> */
    private function receiptFiles(): array
    {
        $files = glob($this->directory . '/*.json') ?: [];
        sort($files, SORT_STRING);
        return array_values($files);
    }

    private function path(string $id): string
    {
        return rtrim($this->directory, '/\\') . '/' . $id . '.json';
    }
}
