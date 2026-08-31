<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Reliability;

use InvalidArgumentException;

final readonly class PricingCatalog
{
    /** @var array<string, ModelPricing> */
    private array $models;

    /** @param list<ModelPricing> $models */
    public function __construct(
        public string $version = 'unconfigured',
        public ?string $asOf = null,
        public string $currency = 'USD',
        array $models = []
    ) {
        if (trim($version) === '' || strlen($version) > 128
            || preg_match('/^[A-Z]{3}$/D', $currency) !== 1
            || $asOf !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $asOf) !== 1) {
            throw new InvalidArgumentException('Jarvis pricing catalog metadata is invalid.');
        }
        $indexed = [];
        foreach ($models as $pricing) {
            if (!$pricing instanceof ModelPricing) {
                throw new InvalidArgumentException('Jarvis pricing catalogs contain only ModelPricing values.');
            }
            $key = $pricing->providerId . "\0" . $pricing->model;
            if (isset($indexed[$key])) {
                throw new InvalidArgumentException('Jarvis model pricing entries must be unique.');
            }
            $indexed[$key] = $pricing;
        }
        ksort($indexed, SORT_STRING);
        $this->models = $indexed;
    }

    /** @param array<string, mixed> $configuration */
    public static function fromArray(array $configuration): self
    {
        $models = [];
        foreach ((array) ($configuration['models'] ?? []) as $entry) {
            if (!is_array($entry)) {
                throw new InvalidArgumentException('Jarvis pricing model entries must be maps.');
            }
            $models[] = new ModelPricing(
                (string) ($entry['provider'] ?? ''),
                (string) ($entry['model'] ?? ''),
                (string) ($entry['unit'] ?? 'tokens'),
                (string) ($entry['input_per_million'] ?? ''),
                (string) ($entry['output_per_million'] ?? ''),
                isset($entry['cache_read_per_million']) ? (string) $entry['cache_read_per_million'] : null,
                isset($entry['cache_write_per_million']) ? (string) $entry['cache_write_per_million'] : null
            );
        }
        return new self(
            (string) ($configuration['version'] ?? 'unconfigured'),
            isset($configuration['as_of']) ? (string) $configuration['as_of'] : null,
            (string) ($configuration['currency'] ?? 'USD'),
            $models
        );
    }

    public function find(string $providerId, ?string $model): ?ModelPricing
    {
        return $model === null ? null : ($this->models[$providerId . "\0" . $model] ?? null);
    }
}
