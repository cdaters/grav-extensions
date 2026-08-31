<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Reliability;

use Grav\Plugin\GravJarvis\Contracts\CompletionResult;

final readonly class RetryOutcome
{
    /** @param list<int> $delaysMilliseconds */
    public function __construct(
        public CompletionResult $result,
        public int $attempts,
        public int $elapsedMilliseconds,
        public array $delaysMilliseconds
    ) {
    }
}
