<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Contracts;

final readonly class ReliableCompletionResult
{
    /** @param array<string, bool|int|string|null> $diagnostics */
    public function __construct(
        public CompletionResult $completion,
        public UsageReport $usage,
        public CostEstimate $cost,
        public array $diagnostics = []
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            ...$this->completion->toArray(),
            'usage' => $this->usage->toArray(),
            'cost' => $this->cost->toArray(),
            'reliability' => $this->diagnostics,
        ];
    }
}
