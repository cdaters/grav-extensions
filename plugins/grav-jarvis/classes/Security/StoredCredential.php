<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Security;

use Grav\Plugin\GravJarvis\Contracts\CredentialValueInterface;
use InvalidArgumentException;
use LogicException;

final class StoredCredential implements CredentialValueInterface
{
    public function __construct(
        private readonly string $environmentVariable,
        #[\SensitiveParameter] private readonly string $value
    ) {
    }

    public function environmentVariable(): string { return $this->environmentVariable; }
    public function reveal(): string { return $this->value; }

    public function prefixed(string $prefix): CredentialValueInterface
    {
        if (strlen($prefix) > 64 || preg_match('/[\x00-\x1F\x7F]/', $prefix) === 1) {
            throw new InvalidArgumentException('Credential prefixes must be bounded plain text.');
        }
        return new self($this->environmentVariable, $prefix . $this->value);
    }

    public function __debugInfo(): array
    {
        return ['environment_variable' => $this->environmentVariable, 'value' => SecretRedactor::REDACTED];
    }

    public function __serialize(): array
    {
        throw new LogicException('Credential values cannot be serialized.');
    }
}
