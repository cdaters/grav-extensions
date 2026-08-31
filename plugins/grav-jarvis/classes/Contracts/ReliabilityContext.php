<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Contracts;

use InvalidArgumentException;

final readonly class ReliabilityContext
{
    public string $siteScope;
    public string $actorScope;
    public string $contextScope;
    public string $operationId;
    public string $actionId;
    public ?int $estimatedInputUnits;
    public ?int $estimatedOutputUnits;
    public string $estimatedUnit;

    public function __construct(
        string $siteScope,
        string $actorScope,
        string $contextScope,
        string $operationId,
        string $actionId,
        ?int $estimatedInputUnits = null,
        ?int $estimatedOutputUnits = null,
        string $estimatedUnit = 'unknown'
    ) {
        foreach (compact('siteScope', 'actorScope', 'contextScope', 'operationId') as $name => $value) {
            $value = trim($value);
            if ($value === '' || strlen($value) > 1024 || self::unsafe($value)) {
                throw new InvalidArgumentException("Reliability {$name} must be bounded plain text.");
            }
            $this->{$name} = $value;
        }
        $actionId = trim($actionId);
        if (!CompletionRequest::validIdentifier($actionId)) {
            throw new InvalidArgumentException('Reliability action identifiers must be lowercase stable slugs.');
        }
        foreach ([$estimatedInputUnits, $estimatedOutputUnits] as $estimate) {
            if ($estimate !== null && $estimate < 0) {
                throw new InvalidArgumentException('Reliability usage estimates cannot be negative.');
            }
        }
        if (!CompletionRequest::validIdentifier($estimatedUnit)) {
            throw new InvalidArgumentException('Reliability estimate units must be lowercase stable slugs.');
        }

        $this->actionId = $actionId;
        $this->estimatedInputUnits = $estimatedInputUnits;
        $this->estimatedOutputUnits = $estimatedOutputUnits;
        $this->estimatedUnit = $estimatedUnit;
    }

    public function scopeHash(): string
    {
        return hash('sha256', self::field($this->siteScope) . self::field($this->actorScope) . self::field($this->contextScope));
    }

    /** @return array<string, int|string|null> */
    public function diagnosticArray(): array
    {
        return [
            'scope_sha256' => $this->scopeHash(),
            'operation_sha256' => hash('sha256', $this->operationId),
            'action' => $this->actionId,
            'estimated_input' => $this->estimatedInputUnits,
            'estimated_output' => $this->estimatedOutputUnits,
            'estimated_unit' => $this->estimatedUnit,
        ];
    }

    private static function field(string $value): string
    {
        return strlen($value) . ':' . $value . ';';
    }

    private static function unsafe(string $value): bool
    {
        return preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1;
    }
}
