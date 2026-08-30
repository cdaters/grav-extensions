<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Contracts;

interface JarvisServiceInterface
{
    public function complete(CompletionRequest $request): CompletionResult;

    public function providers(): ProviderRegistryInterface;

    /** @return list<string> */
    public function providerIds(): array;

    /** @return list<string> */
    public function capabilities(string $providerId): array;
}
