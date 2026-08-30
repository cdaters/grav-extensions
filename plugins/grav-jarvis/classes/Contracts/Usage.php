<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Contracts;

use InvalidArgumentException;

final readonly class Usage
{
    public ?int $inputUnits;
    public ?int $outputUnits;
    public ?int $totalUnits;
    public string $unit;
    public bool $providerReported;

    public function __construct(
        ?int $inputUnits = null,
        ?int $outputUnits = null,
        ?int $totalUnits = null,
        string $unit = 'unknown',
        bool $providerReported = false
    ) {
        foreach ([$inputUnits, $outputUnits, $totalUnits] as $value) {
            if ($value !== null && $value < 0) {
                throw new InvalidArgumentException('Usage values cannot be negative.');
            }
        }
        $unit = trim($unit);
        if (!CompletionRequest::validIdentifier($unit)) {
            throw new InvalidArgumentException('Usage units must be lowercase stable slugs.');
        }
        if ($totalUnits === null && $inputUnits !== null && $outputUnits !== null) {
            $totalUnits = $inputUnits + $outputUnits;
        }

        $this->inputUnits = $inputUnits;
        $this->outputUnits = $outputUnits;
        $this->totalUnits = $totalUnits;
        $this->unit = $unit;
        $this->providerReported = $providerReported;
    }

    /** @return array<string, int|string|bool|null> */
    public function toArray(): array
    {
        return [
            'input' => $this->inputUnits,
            'output' => $this->outputUnits,
            'total' => $this->totalUnits,
            'unit' => $this->unit,
            'provider_reported' => $this->providerReported,
        ];
    }
}
