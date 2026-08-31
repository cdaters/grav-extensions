<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Contracts;

final readonly class CostEstimate
{
    /** @param array<string, string|null> $breakdown */
    public function __construct(
        public string $currency,
        public ?string $estimatedAmount,
        public ?string $authoritativeAmount,
        public ?string $pricingVersion,
        public ?string $pricingAsOf,
        public bool $complete,
        public string $reason,
        public array $breakdown = []
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'currency' => $this->currency,
            'estimated_amount' => $this->estimatedAmount,
            'authoritative_amount' => $this->authoritativeAmount,
            'pricing_version' => $this->pricingVersion,
            'pricing_as_of' => $this->pricingAsOf,
            'complete' => $this->complete,
            'reason' => $this->reason,
            'breakdown' => $this->breakdown,
        ];
    }
}
