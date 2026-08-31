<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Contracts;

use InvalidArgumentException;

final readonly class RetryPolicy
{
    public function __construct(
        public bool $enabled = true,
        public int $maxAttempts = 3,
        public int $maxElapsedMilliseconds = 5000,
        public int $baseDelayMilliseconds = 100,
        public int $maxDelayMilliseconds = 1000,
        public int $maxJitterMilliseconds = 50
    ) {
        if ($maxAttempts < 1 || $maxAttempts > 8
            || $maxElapsedMilliseconds < 0 || $maxElapsedMilliseconds > 60000
            || $baseDelayMilliseconds < 0 || $baseDelayMilliseconds > 10000
            || $maxDelayMilliseconds < $baseDelayMilliseconds || $maxDelayMilliseconds > 30000
            || $maxJitterMilliseconds < 0 || $maxJitterMilliseconds > 5000) {
            throw new InvalidArgumentException('Jarvis retry policy is outside its safety bounds.');
        }
    }
}
