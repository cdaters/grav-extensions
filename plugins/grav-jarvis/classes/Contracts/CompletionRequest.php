<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Contracts;

use InvalidArgumentException;

final readonly class CompletionRequest
{
    public string $providerId;
    public string $input;
    public ?string $model;
    public ?string $instructions;
    /** @var array<string, mixed> */
    public array $options;
    /** @var array<string, mixed> */
    public array $metadata;

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        string $providerId,
        string $input,
        ?string $model = null,
        ?string $instructions = null,
        array $options = [],
        array $metadata = []
    ) {
        $providerId = trim($providerId);
        if (!self::validIdentifier($providerId)) {
            throw new InvalidArgumentException('Provider identifiers must be lowercase stable slugs.');
        }
        if (trim($input) === '') {
            throw new InvalidArgumentException('Completion input cannot be empty.');
        }

        $model = $model === null ? null : trim($model);
        if ($model === '') {
            $model = null;
        }
        $instructions = $instructions === null ? null : trim($instructions);
        if ($instructions === '') {
            $instructions = null;
        }

        self::assertSafeData($options, 'options');
        self::assertSafeData($metadata, 'metadata');

        $this->providerId = $providerId;
        $this->input = $input;
        $this->model = $model;
        $this->instructions = $instructions;
        $this->options = $options;
        $this->metadata = $metadata;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'provider_id' => $this->providerId,
            'input' => $this->input,
            'model' => $this->model,
            'instructions' => $this->instructions,
            'options' => $this->options,
            'metadata' => $this->metadata,
        ];
    }

    public function canonicalPayload(): string
    {
        $encoded = json_encode(
            self::sortRecursively($this->toArray()),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        return $encoded;
    }

    public static function validIdentifier(string $identifier): bool
    {
        return preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $identifier) === 1;
    }

    /** @param array<string, mixed> $data */
    private static function assertSafeData(array $data, string $path): void
    {
        foreach ($data as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidArgumentException($path . ' must use string keys.');
            }
            if (self::sensitiveKey($key)) {
                throw new InvalidArgumentException(
                    'Provider credentials and secrets cannot be included in request ' . $path . '.'
                );
            }
            if (is_array($value)) {
                self::assertNestedData($value, $path . '.' . $key);
                continue;
            }
            if (!is_null($value) && !is_scalar($value)) {
                throw new InvalidArgumentException($path . ' may contain only scalar, null, or array values.');
            }
        }
    }

    /** @param array<mixed> $data */
    private static function assertNestedData(array $data, string $path): void
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && self::sensitiveKey($key)) {
                throw new InvalidArgumentException(
                    'Provider credentials and secrets cannot be included in request ' . $path . '.'
                );
            }
            if (is_array($value)) {
                self::assertNestedData($value, $path . '.' . (string) $key);
                continue;
            }
            if (!is_null($value) && !is_scalar($value)) {
                throw new InvalidArgumentException($path . ' may contain only scalar, null, or array values.');
            }
        }
    }

    private static function sensitiveKey(string $key): bool
    {
        return preg_match(
            '/^(?:authorization|api[_-]?key|access[_-]?token|refresh[_-]?token|bearer[_-]?token|auth[_-]?token|token|secret|client[_-]?secret|password|credentials?)$/i',
            $key
        ) === 1;
    }

    private static function sortRecursively(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::sortRecursively($item);
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        return $value;
    }
}
