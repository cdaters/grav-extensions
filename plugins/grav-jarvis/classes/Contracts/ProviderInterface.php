<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Contracts;

interface ProviderInterface
{
    public function id(): string;

    /** @return list<string> */
    public function capabilities(): array;

    public function complete(CompletionRequest $request): CompletionResult;
}
