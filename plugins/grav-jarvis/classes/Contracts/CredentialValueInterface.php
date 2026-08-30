<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Contracts;

interface CredentialValueInterface
{
    public function environmentVariable(): string;

    public function reveal(): string;

    public function prefixed(string $prefix): CredentialValueInterface;
}
