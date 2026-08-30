<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Contracts;

interface ProviderValidationInterface
{
    public function validateProvider(): ProviderValidationResult;
}
