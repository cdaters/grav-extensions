<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Contracts;

use InvalidArgumentException;

final readonly class CompletionResult
{
    public string $providerId;
    public ?string $model;
    public string $output;
    public Usage $usage;
    /** @var array<string, mixed> */
    public array $metadata;

    /** @param array<string, mixed> $metadata */
    public function __construct(
        string $providerId,
        string $output,
        ?string $model = null,
        ?Usage $usage = null,
        array $metadata = []
    ) {
        $providerId = trim($providerId);
        if (!CompletionRequest::validIdentifier($providerId)) {
            throw new InvalidArgumentException('Provider identifiers must be lowercase stable slugs.');
        }
        $model = $model === null ? null : trim($model);
        if ($model === '') {
            $model = null;
        }
        self::assertMetadata($metadata);

        $this->providerId = $providerId;
        $this->model = $model;
        $this->output = $output;
        $this->usage = $usage ?? new Usage();
        $this->metadata = $metadata;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'provider_id' => $this->providerId,
            'model' => $this->model,
            'output' => $this->output,
            'usage' => $this->usage->toArray(),
            'metadata' => $this->metadata,
        ];
    }

    /** @param array<mixed> $metadata */
    private static function assertMetadata(array $metadata): void
    {
        foreach ($metadata as $key => $value) {
            if (!is_int($key) && !is_string($key)) {
                throw new InvalidArgumentException('Result metadata keys must be integers or strings.');
            }
            if (is_array($value)) {
                self::assertMetadata($value);
                continue;
            }
            if (!is_null($value) && !is_scalar($value)) {
                throw new InvalidArgumentException('Result metadata must contain serializable values.');
            }
        }
    }
}
