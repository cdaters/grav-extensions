<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Testing;

use Grav\Plugin\GravJarvis\Contracts\CompletionRequest;
use Grav\Plugin\GravJarvis\Contracts\CompletionResult;
use Grav\Plugin\GravJarvis\Contracts\CredentialResolverInterface;
use Grav\Plugin\GravJarvis\Contracts\CredentialValueInterface;
use Grav\Plugin\GravJarvis\Contracts\Exception\CredentialConfigurationException;
use Grav\Plugin\GravJarvis\Contracts\Exception\HttpTransportException;
use Grav\Plugin\GravJarvis\Contracts\Exception\MalformedCredentialException;
use Grav\Plugin\GravJarvis\Contracts\Exception\MissingCredentialException;
use Grav\Plugin\GravJarvis\Contracts\Exception\ProviderAuthenticationException;
use Grav\Plugin\GravJarvis\Contracts\Exception\ProviderConfigurationException;
use Grav\Plugin\GravJarvis\Contracts\Exception\ProviderException;
use Grav\Plugin\GravJarvis\Contracts\Exception\ProviderRateLimitException;
use Grav\Plugin\GravJarvis\Contracts\Exception\ProviderResponseException;
use Grav\Plugin\GravJarvis\Contracts\HttpRequest;
use Grav\Plugin\GravJarvis\Contracts\HttpResponse;
use Grav\Plugin\GravJarvis\Contracts\HttpTransportInterface;
use Grav\Plugin\GravJarvis\Contracts\ModelCatalog;
use Grav\Plugin\GravJarvis\Contracts\ModelDescriptor;
use Grav\Plugin\GravJarvis\Contracts\ModelDiscoveryInterface;
use Grav\Plugin\GravJarvis\Contracts\ProviderInterface;
use Grav\Plugin\GravJarvis\Contracts\ProviderValidationInterface;
use Grav\Plugin\GravJarvis\Contracts\ProviderValidationResult;
use Grav\Plugin\GravJarvis\Contracts\Usage;
use Grav\Plugin\GravJarvis\Contracts\ValidationIssue;
use Grav\Plugin\GravJarvis\Security\SecretRedactor;
use JsonException;
use Throwable;

/**
 * Offline reference adapter used to prove the provider boundary. It is never
 * registered by Jarvis and never creates a network client.
 */
final class ConformanceFakeProvider implements
    ProviderInterface,
    ProviderValidationInterface,
    ModelDiscoveryInterface
{
    private int $completionCalls = 0;
    private readonly SecretRedactor $redactor;

    public function __construct(
        private readonly string $providerId,
        private readonly string $endpoint,
        private readonly string $credentialEnvironmentVariable,
        private readonly CredentialResolverInterface $credentials,
        private readonly HttpTransportInterface $http
    ) {
        $this->redactor = SecretRedactor::fromEnvironment();
    }

    public function id(): string
    {
        return $this->providerId;
    }

    public function capabilities(): array
    {
        return ['deterministic-test', 'model-discovery', 'provider-validation', 'text-completion'];
    }

    public function complete(CompletionRequest $request): CompletionResult
    {
        ++$this->completionCalls;
        if ($request->providerId !== $this->providerId) {
            throw new ProviderException('The conformance provider received a request for another provider.');
        }
        $digest = hash('sha256', $request->canonicalPayload());
        $output = 'jarvis-conformance:' . substr($digest, 0, 24);
        return new CompletionResult(
            providerId: $this->providerId,
            model: $request->model ?? 'offline-v1',
            output: $output,
            usage: new Usage(
                inputUnits: strlen($request->input),
                outputUnits: strlen($output),
                unit: 'characters',
                providerReported: false
            ),
            metadata: ['fixture' => 'offline-v1']
        );
    }

    public function completionCalls(): int
    {
        return $this->completionCalls;
    }

    public function validateProvider(): ProviderValidationResult
    {
        try {
            $this->assertConfiguration();
            $credential = $this->credentials->resolve($this->credentialEnvironmentVariable);
            $response = $this->http->send($this->request('/validate', $credential));
            $this->assertSuccessful($response);
            $payload = $this->decodeObject($response->body);
            if (!array_key_exists('usable', $payload) || !is_bool($payload['usable'])) {
                throw new ProviderResponseException('Provider validation returned an invalid usable flag.');
            }
            if (!$payload['usable']) {
                return $this->invalid('provider_unavailable', 'The provider reported that it is unavailable.', true);
            }
            return new ProviderValidationResult(
                providerId: $this->providerId,
                usable: true,
                capabilities: $this->capabilities()
            );
        } catch (CredentialConfigurationException|ProviderConfigurationException $error) {
            return $this->invalid('configuration_invalid', $error->getMessage());
        } catch (MissingCredentialException $error) {
            return $this->invalid('credential_missing', $error->getMessage());
        } catch (MalformedCredentialException $error) {
            return $this->invalid('credential_invalid', $error->getMessage());
        } catch (ProviderAuthenticationException $error) {
            return $this->invalid('authentication_failed', $error->getMessage());
        } catch (ProviderRateLimitException $error) {
            return $this->invalid('rate_limited', $error->getMessage(), true);
        } catch (ProviderResponseException $error) {
            return $this->invalid('response_invalid', $error->getMessage());
        } catch (HttpTransportException $error) {
            return $this->invalid('transport_unavailable', $error->getMessage(), true);
        } catch (Throwable $error) {
            return $this->invalid('provider_unavailable', $error->getMessage(), true);
        }
    }

    public function discoverModels(): ModelCatalog
    {
        $this->assertConfiguration();
        $credential = $this->credentials->resolve($this->credentialEnvironmentVariable);
        $response = $this->http->send($this->request('/models', $credential));
        $this->assertSuccessful($response);
        $payload = $this->decodeObject($response->body);
        if (!isset($payload['models']) || !is_array($payload['models']) || !array_is_list($payload['models'])) {
            throw new ProviderResponseException('Provider model discovery returned an invalid model list.');
        }

        $models = [];
        foreach ($payload['models'] as $item) {
            if (!is_array($item) || !isset($item['id']) || !is_string($item['id'])) {
                throw new ProviderResponseException('Provider model discovery returned an invalid model entry.');
            }
            $capabilities = $item['capabilities'] ?? [];
            if (!is_array($capabilities) || !array_is_list($capabilities)) {
                throw new ProviderResponseException('Provider model discovery returned invalid capabilities.');
            }
            if ((array_key_exists('label', $item) && !is_string($item['label']))
                || (array_key_exists('description', $item) && !is_string($item['description']))
                || (array_key_exists('available', $item) && !is_bool($item['available']))) {
                throw new ProviderResponseException('Provider model discovery returned invalid model fields.');
            }
            try {
                $models[] = new ModelDescriptor(
                    id: $item['id'],
                    label: $item['label'] ?? null,
                    description: $item['description'] ?? null,
                    capabilities: $capabilities,
                    available: $item['available'] ?? true
                );
            } catch (Throwable) {
                throw new ProviderResponseException('Provider model discovery returned a malformed model descriptor.');
            }
        }
        return new ModelCatalog($this->providerId, $models);
    }

    private function assertConfiguration(): void
    {
        if (!CompletionRequest::validIdentifier($this->providerId)) {
            throw new ProviderConfigurationException('The provider identifier is invalid.');
        }
        if ($this->credentials->providerId() !== $this->providerId) {
            throw new ProviderConfigurationException('The credential resolver belongs to another provider.');
        }
        $parts = parse_url($this->endpoint);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new ProviderConfigurationException('The endpoint must be an absolute HTTPS origin or base path.');
        }
    }

    private function request(string $path, CredentialValueInterface $credential): HttpRequest
    {
        return new HttpRequest(
            method: 'GET',
            uri: rtrim($this->endpoint, '/') . $path,
            headers: ['Accept' => 'application/json'],
            credentialHeaders: ['X-Fixture-Credential' => $credential]
        );
    }

    private function assertSuccessful(HttpResponse $response): void
    {
        $safeBody = trim($this->redactor->redact(substr($response->body, 0, 500)));
        if ($response->status === 401 || $response->status === 403) {
            throw new ProviderAuthenticationException(
                'Provider authentication failed.' . ($safeBody === '' ? '' : ' ' . $safeBody)
            );
        }
        if ($response->status === 429) {
            $retryAfter = $response->header('retry-after');
            throw new ProviderRateLimitException(
                'Provider request was rate limited.' . ($safeBody === '' ? '' : ' ' . $safeBody),
                is_string($retryAfter) && ctype_digit($retryAfter) ? (int) $retryAfter : null
            );
        }
        if ($response->status < 200 || $response->status >= 300) {
            throw new HttpTransportException(
                'Provider HTTP request failed with status ' . $response->status
                . '.' . ($safeBody === '' ? '' : ' ' . $safeBody)
            );
        }
    }

    /** @return array<string, mixed> */
    private function decodeObject(string $body): array
    {
        try {
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ProviderResponseException('Provider returned malformed JSON.');
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new ProviderResponseException('Provider returned a non-object response.');
        }
        return $decoded;
    }

    private function invalid(string $code, string $message, bool $retryable = false): ProviderValidationResult
    {
        $message = trim($this->redactor->redact($message));
        if ($message === '') {
            $message = 'The provider is not usable.';
        }
        return new ProviderValidationResult(
            providerId: $this->providerId,
            usable: false,
            issues: [new ValidationIssue($code, $message, ValidationIssue::ERROR, $retryable)],
            capabilities: $this->capabilities()
        );
    }
}
