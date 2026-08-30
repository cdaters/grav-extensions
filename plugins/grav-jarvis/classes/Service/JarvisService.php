<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Service;

use Grav\Plugin\GravJarvis\Contracts\CompletionRequest;
use Grav\Plugin\GravJarvis\Contracts\CompletionResult;
use Grav\Plugin\GravJarvis\Contracts\Exception\ProviderCapabilityException;
use Grav\Plugin\GravJarvis\Contracts\Exception\ProviderFailureException;
use Grav\Plugin\GravJarvis\Contracts\ModelCatalog;
use Grav\Plugin\GravJarvis\Contracts\ModelDescriptor;
use Grav\Plugin\GravJarvis\Contracts\ModelDiscoveryInterface;
use Grav\Plugin\GravJarvis\Contracts\ProviderIntrospectionServiceInterface;
use Grav\Plugin\GravJarvis\Contracts\ProviderRegistryInterface;
use Grav\Plugin\GravJarvis\Contracts\ProviderValidationInterface;
use Grav\Plugin\GravJarvis\Contracts\ProviderValidationResult;
use Grav\Plugin\GravJarvis\Contracts\ValidationIssue;
use Grav\Plugin\GravJarvis\Security\SecretRedactor;
use Throwable;

final class JarvisService implements ProviderIntrospectionServiceInterface
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
            throw $this->normalizedFailure($request->providerId, $error);
        }
    }

    public function validateProvider(string $providerId): ProviderValidationResult
    {
        $provider = $this->registry->get($providerId);
        if (!$provider instanceof ProviderValidationInterface) {
            throw new ProviderCapabilityException($providerId, 'provider-validation');
        }
        try {
            $result = $provider->validateProvider();
            if ($result->providerId !== $provider->id()) {
                throw new \UnexpectedValueException('Provider validation returned a different provider identifier.');
            }
            $issues = array_map(
                fn (ValidationIssue $issue): ValidationIssue => new ValidationIssue(
                    code: $issue->code,
                    message: $this->redactor->redact($issue->message),
                    severity: $issue->severity,
                    retryable: $issue->retryable
                ),
                $result->issues
            );
            return new ProviderValidationResult(
                providerId: $result->providerId,
                usable: $result->usable,
                issues: $issues,
                capabilities: $result->capabilities
            );
        } catch (Throwable $error) {
            throw $this->normalizedFailure($providerId, $error);
        }
    }

    public function discoverModels(string $providerId): ModelCatalog
    {
        $provider = $this->registry->get($providerId);
        if (!$provider instanceof ModelDiscoveryInterface) {
            throw new ProviderCapabilityException($providerId, 'model-discovery');
        }
        try {
            $catalog = $provider->discoverModels();
            if ($catalog->providerId !== $provider->id()) {
                throw new \UnexpectedValueException('Model discovery returned a different provider identifier.');
            }
            $models = array_map(
                fn (ModelDescriptor $model): ModelDescriptor => new ModelDescriptor(
                    id: $this->redactor->redact($model->id),
                    label: $this->redactor->redact($model->label),
                    description: $model->description === null
                        ? null
                        : $this->redactor->redact($model->description),
                    capabilities: $model->capabilities,
                    available: $model->available
                ),
                $catalog->models
            );
            return new ModelCatalog($catalog->providerId, $models);
        } catch (Throwable $error) {
            throw $this->normalizedFailure($providerId, $error);
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

    private function normalizedFailure(string $providerId, Throwable $error): ProviderFailureException
    {
        $safeMessage = trim($this->redactor->redact($error->getMessage()));
        if ($safeMessage === '') {
            $safeMessage = 'The provider request failed without a safe diagnostic.';
        }
        return new ProviderFailureException($providerId, $safeMessage);
    }
}
