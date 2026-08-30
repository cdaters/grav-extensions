<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Service;

use Grav\Plugin\GravJarvis\Contracts\CompletionRequest;
use Grav\Plugin\GravJarvis\Contracts\CompletionResult;
use Grav\Plugin\GravJarvis\Contracts\Exception\ProviderFailureException;
use Grav\Plugin\GravJarvis\Contracts\JarvisServiceInterface;
use Grav\Plugin\GravJarvis\Contracts\ProviderRegistryInterface;
use Grav\Plugin\GravJarvis\Security\SecretRedactor;
use Throwable;

final class JarvisService implements JarvisServiceInterface
{
    public function __construct(
        private readonly ProviderRegistryInterface $registry,
        private readonly SecretRedactor $redactor
    ) {
    }

    public function complete(CompletionRequest $request): CompletionResult
    {
        $provider = $this->registry->get($request->providerId);
        try {
            $result = $provider->complete($request);
            if ($result->providerId !== $provider->id()) {
                throw new \UnexpectedValueException('Provider returned a result for a different provider identifier.');
            }
            $metadata = $this->redactor->redactValue($result->metadata);
            return new CompletionResult(
                providerId: $result->providerId,
                model: $result->model,
                output: $this->redactor->redact($result->output),
                usage: $result->usage,
                metadata: is_array($metadata) ? $metadata : []
            );
        } catch (Throwable $error) {
            $safeMessage = trim($this->redactor->redact($error->getMessage()));
            if ($safeMessage === '') {
                $safeMessage = 'The provider request failed without a safe diagnostic.';
            }
            throw new ProviderFailureException($request->providerId, $safeMessage);
        }
    }

    public function providers(): ProviderRegistryInterface
    {
        return $this->registry;
    }

    public function providerIds(): array
    {
        return $this->registry->ids();
    }

    public function capabilities(string $providerId): array
    {
        $capabilities = array_values(array_unique($this->registry->get($providerId)->capabilities()));
        sort($capabilities, SORT_STRING);
        return $capabilities;
    }
}
