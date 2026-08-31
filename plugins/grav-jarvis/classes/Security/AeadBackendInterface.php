<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Security;

interface AeadBackendInterface
{
    public function id(): string;

    public function available(): bool;

    /** @return array<string, string|int> */
    public function encrypt(#[\SensitiveParameter] string $plaintext, #[\SensitiveParameter] string $key, string $aad): array;

    /** @param array<string, mixed> $record */
    public function decrypt(array $record, #[\SensitiveParameter] string $key, string $aad): string;
}
