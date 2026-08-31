<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Contracts;

final readonly class ReliabilityPolicy
{
    public RetryPolicy $retry;
    public CachePolicy $cache;
    public BudgetPolicy $budget;
    public ChunkPolicy $chunking;

    public function __construct(
        ?RetryPolicy $retry = null,
        ?CachePolicy $cache = null,
        ?BudgetPolicy $budget = null,
        ?ChunkPolicy $chunking = null
    ) {
        $this->retry = $retry ?? new RetryPolicy();
        $this->cache = $cache ?? new CachePolicy();
        $this->budget = $budget ?? new BudgetPolicy();
        $this->chunking = $chunking ?? new ChunkPolicy();
    }
}
