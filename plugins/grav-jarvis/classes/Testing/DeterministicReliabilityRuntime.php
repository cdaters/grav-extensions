<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Testing;

use Grav\Plugin\GravJarvis\Reliability\ReliabilityRuntimeInterface;

final class DeterministicReliabilityRuntime implements ReliabilityRuntimeInterface
{
    /** @var list<int> */
    private array $jitters;
    /** @var list<int> */
    private array $sleeps = [];

    /** @param list<int> $jitters */
    public function __construct(private int $milliseconds = 0, array $jitters = [])
    {
        $this->jitters = array_values($jitters);
    }

    public function nowMilliseconds(): int
    {
        return $this->milliseconds;
    }

    public function sleepMilliseconds(int $milliseconds): void
    {
        $this->sleeps[] = $milliseconds;
        $this->milliseconds += $milliseconds;
    }

    public function jitterMilliseconds(int $maximum): int
    {
        $value = array_shift($this->jitters) ?? 0;
        return max(0, min($maximum, $value));
    }

    /** @return list<int> */
    public function sleeps(): array
    {
        return $this->sleeps;
    }
}
