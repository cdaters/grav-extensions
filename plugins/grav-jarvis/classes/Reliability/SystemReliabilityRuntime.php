<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Reliability;

final class SystemReliabilityRuntime implements ReliabilityRuntimeInterface
{
    public function nowMilliseconds(): int
    {
        return (int) floor(hrtime(true) / 1000000);
    }

    public function sleepMilliseconds(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }

    public function jitterMilliseconds(int $maximum): int
    {
        return $maximum > 0 ? random_int(0, $maximum) : 0;
    }
}
