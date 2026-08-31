<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Reliability;

use Grav\Plugin\GravJarvis\Contracts\BudgetPolicy;
use Grav\Plugin\GravJarvis\Contracts\CachePolicy;
use Grav\Plugin\GravJarvis\Contracts\ChunkPolicy;
use Grav\Plugin\GravJarvis\Contracts\ReliabilityPolicy;
use Grav\Plugin\GravJarvis\Contracts\RetryPolicy;
use InvalidArgumentException;

final class ReliabilityPolicyFactory
{
    /** @param array<string, mixed> $configuration */
    public static function fromArray(array $configuration): ReliabilityPolicy
    {
        $retry = self::map($configuration['retry'] ?? null);
        $cache = self::map($configuration['cache'] ?? null);
        $budget = self::map($configuration['budgets'] ?? null);
        $chunk = self::map($configuration['chunking'] ?? null);
        $eligible = array_values(array_filter(
            (array) ($cache['eligible_actions'] ?? ['expand', 'proofread', 'rewrite', 'shorten', 'summarize']),
            'is_string'
        ));
        return new ReliabilityPolicy(
            new RetryPolicy(
                self::bool($retry, 'enabled', true),
                self::int($retry, 'max_attempts', 3),
                self::int($retry, 'max_elapsed_ms', 5000),
                self::int($retry, 'base_delay_ms', 100),
                self::int($retry, 'max_delay_ms', 1000),
                self::int($retry, 'max_jitter_ms', 50)
            ),
            new CachePolicy(
                self::bool($cache, 'enabled', false),
                self::int($cache, 'ttl_seconds', 300),
                self::int($cache, 'max_entries', 128),
                $eligible
            ),
            new BudgetPolicy(
                self::bool($budget, 'enabled', false),
                self::nullableInt($budget, 'max_request_count'),
                self::nullableInt($budget, 'max_input_bytes'),
                self::nullableInt($budget, 'max_estimated_output_units'),
                self::nullableString($budget, 'max_estimated_cost_per_request'),
                self::nullableString($budget, 'max_estimated_cost_per_operation'),
                self::nullableInt($budget, 'max_retry_count')
            ),
            new ChunkPolicy(
                self::int($chunk, 'max_chunk_bytes', 12288),
                self::int($chunk, 'max_chunks', 16),
                self::int($chunk, 'max_total_bytes', 196608),
                self::int($chunk, 'max_synthesis_bytes', 49152),
                (string) ($chunk['overflow_strategy'] ?? ChunkPolicy::FAIL)
            )
        );
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value): array { return is_array($value) ? $value : []; }
    private static function bool(array $map, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $map)) return $default;
        $value = $map[$key];
        if (is_bool($value)) return $value;
        if ($value === 0 || $value === '0') return false;
        if ($value === 1 || $value === '1') return true;
        throw new InvalidArgumentException('Jarvis reliability boolean configuration is invalid.');
    }
    private static function int(array $map, string $key, int $default): int
    {
        if (!array_key_exists($key, $map)) return $default;
        return self::integer($map[$key]);
    }
    private static function nullableInt(array $map, string $key): ?int
    {
        return !array_key_exists($key, $map) || $map[$key] === null || $map[$key] === ''
            ? null
            : self::integer($map[$key]);
    }
    private static function nullableString(array $map, string $key): ?string { return isset($map[$key]) && $map[$key] !== '' ? (string) $map[$key] : null; }

    private static function integer(mixed $value): int
    {
        if (is_int($value)) return $value;
        if (is_string($value) && preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value) === 1) return (int) $value;
        throw new InvalidArgumentException('Jarvis reliability integer configuration is invalid.');
    }
}
