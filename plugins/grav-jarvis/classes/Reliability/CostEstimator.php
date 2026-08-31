<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Reliability;

use Grav\Plugin\GravJarvis\Contracts\CostEstimate;
use Grav\Plugin\GravJarvis\Contracts\UsageReport;

final class CostEstimator
{
    public function __construct(private readonly PricingCatalog $catalog)
    {
    }

    public function estimate(UsageReport $usage): CostEstimate
    {
        if ($usage->cacheHit && $usage->requestCount === 0) {
            return new CostEstimate(
                $this->catalog->currency,
                '0',
                null,
                $this->catalog->version,
                $this->catalog->asOf,
                true,
                'cache_hit_no_provider_request',
                ['input' => '0', 'output' => '0']
            );
        }
        $pricing = $this->catalog->find($usage->providerId, $usage->model);
        if ($pricing === null) {
            return $this->unknown('pricing_unknown');
        }
        if ($pricing->unit !== $usage->unit) {
            return $this->unknown('usage_unit_mismatch');
        }
        if ($usage->inputUnits === null || $usage->outputUnits === null) {
            return $this->unknown('usage_unknown');
        }

        try {
            $input = DecimalMoney::priceUnits($pricing->inputRateNanos, $usage->inputUnits);
            $output = DecimalMoney::priceUnits($pricing->outputRateNanos, $usage->outputUnits);
            $cacheRead = $this->optionalComponent($pricing->cacheReadRateNanos, $usage->cacheReadUnits);
            $cacheWrite = $this->optionalComponent($pricing->cacheWriteRateNanos, $usage->cacheWriteUnits);
            if ($cacheRead === false || $cacheWrite === false) {
                return $this->unknown('cache_usage_pricing_unknown');
            }
            $base = $input + $output + $cacheRead + $cacheWrite;
            $attempts = max(1, $usage->requestCount);
            $total = $base * $attempts;
            return new CostEstimate(
                $this->catalog->currency,
                DecimalMoney::formatNanos($total),
                null,
                $this->catalog->version,
                $this->catalog->asOf,
                $usage->retryCount === 0,
                $usage->retryCount === 0 ? 'estimated_from_provider_usage' : 'retry_amplified_upper_estimate',
                [
                    'input' => DecimalMoney::formatNanos($input * $attempts),
                    'output' => DecimalMoney::formatNanos($output * $attempts),
                    'cache_read' => DecimalMoney::formatNanos($cacheRead * $attempts),
                    'cache_write' => DecimalMoney::formatNanos($cacheWrite * $attempts),
                ]
            );
        } catch (\InvalidArgumentException) {
            return $this->unknown('usage_outside_estimation_bounds');
        }
    }

    public function projectedNanos(
        string $providerId,
        ?string $model,
        ?int $inputUnits,
        ?int $outputUnits,
        string $unit
    ): ?int {
        $pricing = $this->catalog->find($providerId, $model);
        if ($pricing === null || $pricing->unit !== $unit || $inputUnits === null || $outputUnits === null) {
            return null;
        }
        try {
            return DecimalMoney::priceUnits($pricing->inputRateNanos, $inputUnits)
                + DecimalMoney::priceUnits($pricing->outputRateNanos, $outputUnits);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function optionalComponent(?int $rate, ?int $units): int|false
    {
        if ($units === null) {
            return 0;
        }
        return $rate === null ? false : DecimalMoney::priceUnits($rate, $units);
    }

    private function unknown(string $reason): CostEstimate
    {
        return new CostEstimate(
            $this->catalog->currency,
            null,
            null,
            $this->catalog->version,
            $this->catalog->asOf,
            false,
            $reason
        );
    }
}
