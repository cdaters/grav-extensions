<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Reliability;

use Grav\Plugin\GravJarvis\Contracts\CompletionResult;
use Grav\Plugin\GravJarvis\Contracts\ResponseCacheInterface;
use Grav\Plugin\GravJarvis\Contracts\Usage;
use RuntimeException;

final class TransientResponseCache implements ResponseCacheInterface
{
    public function __construct(private readonly string $directory)
    {
    }

    public function get(string $key, string $scopeHash, int $now): ?CompletionResult
    {
        $this->assertDigest($key);
        $this->assertDigest($scopeHash);
        $path = $this->path($key);
        $handle = @fopen($path, 'r+b');
        if ($handle === false) {
            return null;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return null;
            }
            $record = json_decode((string) stream_get_contents($handle), true);
            if (!is_array($record)
                || ($record['version'] ?? null) !== 1
                || !hash_equals((string) ($record['scope_sha256'] ?? ''), $scopeHash)
                || (int) ($record['expires'] ?? 0) <= $now) {
                @unlink($path);
                return null;
            }
            return $this->result($record['result'] ?? null);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function put(
        string $key,
        string $scopeHash,
        CompletionResult $result,
        int $issuedAt,
        int $expiresAt,
        int $maxEntries
    ): void {
        $this->assertDigest($key);
        $this->assertDigest($scopeHash);
        if ($expiresAt <= $issuedAt || $maxEntries < 1 || $maxEntries > 1024) {
            throw new RuntimeException('Jarvis response cache policy is invalid.');
        }
        $this->ensureDirectory();
        $lock = @fopen($this->directory . '/.lock', 'c+b');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) fclose($lock);
            throw new RuntimeException('Jarvis response cache is unavailable.');
        }
        try {
            @chmod($this->directory . '/.lock', 0600);
            $this->cleanup($issuedAt);
            $path = $this->path($key);
            $files = $this->recordFiles();
            if (!is_file($path) && count($files) >= $maxEntries) {
                $this->evict($files, count($files) - $maxEntries + 1);
            }
            $record = [
                'version' => 1,
                'scope_sha256' => $scopeHash,
                'issued' => $issuedAt,
                'expires' => $expiresAt,
                'result' => $result->toArray(),
            ];
            $encoded = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $handle = @fopen($path, 'c+b');
            if ($handle === false) {
                throw new RuntimeException('Jarvis could not write its response cache.');
            }
            try {
                if (!flock($handle, LOCK_EX) || !ftruncate($handle, 0) || rewind($handle) === false
                    || fwrite($handle, $encoded) !== strlen($encoded) || !fflush($handle)) {
                    throw new RuntimeException('Jarvis could not store its response cache entry.');
                }
                @chmod($path, 0600);
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Jarvis could not create its private response cache directory.');
        }
        @chmod($this->directory, 0700);
    }

    private function cleanup(int $now): void
    {
        foreach ($this->recordFiles() as $file) {
            $record = json_decode((string) @file_get_contents($file), true);
            if (!is_array($record) || (int) ($record['expires'] ?? 0) <= $now) {
                @unlink($file);
            }
        }
    }

    /** @param list<string> $files */
    private function evict(array $files, int $count): void
    {
        $ranked = [];
        foreach ($files as $file) {
            $record = json_decode((string) @file_get_contents($file), true);
            $ranked[] = ['file' => $file, 'issued' => (int) ($record['issued'] ?? 0)];
        }
        usort($ranked, static fn (array $a, array $b): int => [$a['issued'], $a['file']] <=> [$b['issued'], $b['file']]);
        foreach (array_slice($ranked, 0, $count) as $item) {
            @unlink($item['file']);
        }
    }

    /** @return list<string> */
    private function recordFiles(): array
    {
        $files = glob(rtrim($this->directory, '/\\') . '/*.json') ?: [];
        sort($files, SORT_STRING);
        return array_values($files);
    }

    private function path(string $key): string
    {
        return rtrim($this->directory, '/\\') . '/' . $key . '.json';
    }

    private function assertDigest(string $value): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new RuntimeException('Jarvis response cache identifiers are invalid.');
        }
    }

    private function result(mixed $value): ?CompletionResult
    {
        if (!is_array($value) || !is_array($value['usage'] ?? null) || !is_array($value['metadata'] ?? null)) {
            return null;
        }
        try {
            $usage = $value['usage'];
            return new CompletionResult(
                (string) ($value['provider_id'] ?? ''),
                (string) ($value['output'] ?? ''),
                is_string($value['model'] ?? null) ? $value['model'] : null,
                new Usage(
                    is_int($usage['input'] ?? null) ? $usage['input'] : null,
                    is_int($usage['output'] ?? null) ? $usage['output'] : null,
                    is_int($usage['total'] ?? null) ? $usage['total'] : null,
                    (string) ($usage['unit'] ?? 'unknown'),
                    (bool) ($usage['provider_reported'] ?? false)
                ),
                $value['metadata']
            );
        } catch (\Throwable) {
            return null;
        }
    }
}
