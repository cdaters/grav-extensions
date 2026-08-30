<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Contracts;

interface CredentialResolverInterface
{
    public function providerId(): string;

    public function resolve(string $environmentVariable): CredentialValueInterface;
}
