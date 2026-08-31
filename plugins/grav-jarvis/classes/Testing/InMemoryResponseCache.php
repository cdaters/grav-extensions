<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Testing;

use Grav\Plugin\GravJarvis\Contracts\CompletionResult;
use Grav\Plugin\GravJarvis\Contracts\ResponseCacheInterface;

final class InMemoryResponseCache implements ResponseCacheInterface
{
    /** @var array<string, array{scope: string, result: CompletionResult, issued: int, expires: int}> */
    private array $records = [];

    public function get(string $key, string $scopeHash, int $now): ?CompletionResult
    {
        $this->cleanup($now);
        $record = $this->records[$key] ?? null;
        return $record !== null && hash_equals($record['scope'], $scopeHash) ? $record['result'] : null;
    }

    public function put(
        string $key,
        string $scopeHash,
        CompletionResult $result,
        int $issuedAt,
        int $expiresAt,
        int $maxEntries
    ): void {
        $this->cleanup($issuedAt);
        if (!isset($this->records[$key]) && count($this->records) >= $maxEntries) {
            uasort($this->records, static fn (array $a, array $b): int => $a['issued'] <=> $b['issued']);
            while (count($this->records) >= $maxEntries) {
                array_shift($this->records);
            }
        }
        $this->records[$key] = [
            'scope' => $scopeHash,
            'result' => $result,
            'issued' => $issuedAt,
            'expires' => $expiresAt,
        ];
        ksort($this->records, SORT_STRING);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->records);
    }

    public function count(): int
    {
        return count($this->records);
    }

    private function cleanup(int $now): void
    {
        foreach ($this->records as $key => $record) {
            if ($record['expires'] <= $now) {
                unset($this->records[$key]);
            }
        }
    }
}
