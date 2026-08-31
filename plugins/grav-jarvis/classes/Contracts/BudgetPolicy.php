<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Contracts;

use InvalidArgumentException;

final readonly class BudgetPolicy
{
    public function __construct(
        public bool $enabled = false,
        public ?int $maxRequestCount = null,
        public ?int $maxInputBytes = null,
        public ?int $maxEstimatedOutputUnits = null,
        public ?string $maxEstimatedCostPerRequest = null,
        public ?string $maxEstimatedCostPerOperation = null,
        public ?int $maxRetryCount = null
    ) {
        foreach ([$maxRequestCount, $maxInputBytes, $maxEstimatedOutputUnits, $maxRetryCount] as $value) {
            if ($value !== null && $value < 0) {
                throw new InvalidArgumentException('Jarvis budget limits cannot be negative.');
            }
        }
        if ($maxRequestCount !== null && $maxRequestCount > 1024
            || $maxInputBytes !== null && $maxInputBytes > 16777216
            || $maxEstimatedOutputUnits !== null && $maxEstimatedOutputUnits > 1000000000
            || $maxRetryCount !== null && $maxRetryCount > 7) {
            throw new InvalidArgumentException('Jarvis budget policy is outside its safety bounds.');
        }
        foreach ([$maxEstimatedCostPerRequest, $maxEstimatedCostPerOperation] as $amount) {
            if ($amount !== null && preg_match('/^(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,9})?$/D', $amount) !== 1) {
                throw new InvalidArgumentException('Jarvis monetary budgets must be bounded non-negative decimal strings.');
            }
        }
    }
}
