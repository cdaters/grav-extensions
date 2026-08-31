<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Reliability;

interface ReliabilityRuntimeInterface
{
    public function nowMilliseconds(): int;

    public function sleepMilliseconds(int $milliseconds): void;

    public function jitterMilliseconds(int $maximum): int;
}
