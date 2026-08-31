<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Reliability;

final class BudgetState
{
    public int $requestCount = 0;
    public int $retryCount = 0;
    public int $projectedCostNanos = 0;
    public bool $costUnknown = false;
}
