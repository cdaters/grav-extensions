<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Reliability;

use Grav\Plugin\GravJarvis\Contracts\CompletionRequest;
use InvalidArgumentException;

final readonly class ModelPricing
{
    public int $inputRateNanos;
    public int $outputRateNanos;
    public ?int $cacheReadRateNanos;
    public ?int $cacheWriteRateNanos;

    public function __construct(
        public string $providerId,
        public string $model,
        public string $unit,
        string $inputPerMillion,
        string $outputPerMillion,
        ?string $cacheReadPerMillion = null,
        ?string $cacheWritePerMillion = null
    ) {
        if (!CompletionRequest::validIdentifier($providerId) || !CompletionRequest::validIdentifier($unit)
            || trim($model) === '' || strlen($model) > 256) {
            throw new InvalidArgumentException('Jarvis model pricing identifiers are invalid.');
        }
        $this->inputRateNanos = self::rate($inputPerMillion);
        $this->outputRateNanos = self::rate($outputPerMillion);
        $this->cacheReadRateNanos = $cacheReadPerMillion === null ? null : self::rate($cacheReadPerMillion);
        $this->cacheWriteRateNanos = $cacheWritePerMillion === null ? null : self::rate($cacheWritePerMillion);
    }

    private static function rate(string $amount): int
    {
        $nanos = DecimalMoney::parseNanos($amount);
        if ($nanos > 10000000000000) {
            throw new InvalidArgumentException('Jarvis model pricing exceeds its safety bound.');
        }
        return $nanos;
    }
}
