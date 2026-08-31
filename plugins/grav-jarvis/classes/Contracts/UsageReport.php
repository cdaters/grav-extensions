<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Contracts;

use InvalidArgumentException;

final readonly class UsageReport
{
    public function __construct(
        public string $providerId,
        public ?string $model,
        public ?int $inputUnits,
        public ?int $outputUnits,
        public ?int $totalUnits,
        public ?int $cacheReadUnits,
        public ?int $cacheWriteUnits,
        public string $unit,
        public bool $providerReported,
        public int $requestCount,
        public int $retryCount,
        public bool $cacheHit
    ) {
        if (!CompletionRequest::validIdentifier($providerId) || !CompletionRequest::validIdentifier($unit)) {
            throw new InvalidArgumentException('Usage reports require provider-neutral stable identifiers.');
        }
        foreach ([$inputUnits, $outputUnits, $totalUnits, $cacheReadUnits, $cacheWriteUnits] as $value) {
            if ($value !== null && $value < 0) {
                throw new InvalidArgumentException('Usage report values cannot be negative.');
            }
        }
        if ($requestCount < 0 || $retryCount < 0 || $retryCount > max(0, $requestCount - 1)) {
            throw new InvalidArgumentException('Usage request and retry counts are inconsistent.');
        }
    }

    public static function fromResult(
        CompletionResult $result,
        int $requestCount = 1,
        int $retryCount = 0,
        bool $cacheHit = false
    ): self {
        $usage = $result->usage;
        return new self(
            $result->providerId,
            $result->model,
            $usage->inputUnits,
            $usage->outputUnits,
            $usage->totalUnits,
            null,
            null,
            $usage->unit,
            $usage->providerReported,
            $requestCount,
            $retryCount,
            $cacheHit
        );
    }

    /** @return array<string, bool|int|string|null> */
    public function toArray(): array
    {
        return [
            'provider_id' => $this->providerId,
            'model' => $this->model,
            'input' => $this->inputUnits,
            'output' => $this->outputUnits,
            'total' => $this->totalUnits,
            'cache_read' => $this->cacheReadUnits,
            'cache_write' => $this->cacheWriteUnits,
            'unit' => $this->unit,
            'provider_reported' => $this->providerReported,
            'request_count' => $this->requestCount,
            'retry_count' => $this->retryCount,
            'cache_hit' => $this->cacheHit,
        ];
    }
}
