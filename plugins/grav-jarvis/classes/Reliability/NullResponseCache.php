<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Reliability;

use Grav\Plugin\GravJarvis\Contracts\CompletionResult;
use Grav\Plugin\GravJarvis\Contracts\ResponseCacheInterface;

final class NullResponseCache implements ResponseCacheInterface
{
    public function get(string $key, string $scopeHash, int $now): ?CompletionResult
    {
        return null;
    }

    public function put(
        string $key,
        string $scopeHash,
        CompletionResult $result,
        int $issuedAt,
        int $expiresAt,
        int $maxEntries
    ): void {
    }
}
