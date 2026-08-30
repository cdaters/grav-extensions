<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Provider\OpenAICompatible;

use Grav\Plugin\GravJarvis\Contracts\CompletionRequest;
use Grav\Plugin\GravJarvis\Contracts\CompletionResult;
use Grav\Plugin\GravJarvis\Contracts\CredentialResolverInterface;
use Grav\Plugin\GravJarvis\Contracts\Exception\CredentialConfigurationException;
use Grav\Plugin\GravJarvis\Contracts\Exception\HttpTransportException;
use Grav\Plugin\GravJarvis\Contracts\Exception\MalformedCredentialException;
use Grav\Plugin\GravJarvis\Contracts\Exception\MissingCredentialException;
use Grav\Plugin\GravJarvis\Contracts\Exception\ProviderAuthenticationException;
use Grav\Plugin\GravJarvis\Contracts\Exception\ProviderCapabilityException;
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
use Grav\Plugin\GravJarvis\Security\EnvironmentCredentialResolver;
use Grav\Plugin\GravJarvis\Security\SecretRedactor;
use Grav\Plugin\GravJarvis\Transport\BoundedHttpTransport;
use JsonException;
use Throwable;

/**
 * Generic adapter for the documented Jarvis Responses-compatible subset.
 * Compatibility is explicit and must not be inferred from a product label.
 */
final class OpenAICompatibleProvider implements
    ProviderInterface,
    ProviderValidationInterface,
    ModelDiscoveryInterface
{
    private readonly SecretRedactor $redactor;

    public function __construct(
        private readonly CompatibleProviderConfig $config,
        private readonly CredentialResolverInterface $credentials,
        private readonly HttpTransportInterface $http
    ) {
        $this->redactor = SecretRedactor::fromEnvironment();
    }

    public static function createProduction(CompatibleProviderConfig $config): self
    {
        return new self(
            $config,
            new EnvironmentCredentialResolver($config->providerId),
            BoundedHttpTransport::forAllowedBaseUris([$config->baseUri])
        );
    }

    public function id(): string
    {
        return $this->config->providerId;
    }

    public function capabilities(): array
    {
        $capabilities = ['provider-validation', 'text-completion'];
        if ($this->config->modelDiscovery) {
            $capabilities[] = 'model-discovery';
        }
        sort($capabilities, SORT_STRING);
        return $capabilities;
    }

    public function complete(CompletionRequest $request): CompletionResult
    {
        $this->assertConfiguration();
        if ($request->providerId !== $this->id()) {
            throw new ProviderException('The compatible provider received a request for another provider.');
        }
        if ($request->options !== []) {
            throw new ProviderConfigurationException(
                'The compatible 0.1.3 profile does not support request options.'
            );
        }
        $model = $request->model ?? $this->config->defaultModel;
        $this->assertModel($model);
        $payload = ['model' => $model, 'input' => $request->input, 'store' => false];
        if ($request->instructions !== null) {
            $payload['instructions'] = $request->instructions;
        }
        try {
            $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ProviderConfigurationException('The compatible provider request could not be encoded.');
        }
        $response = $this->http->send($this->request('POST', '/responses', $body));
        $this->assertSuccessful($response, 'text-completion');
        $decoded = $this->decode($response->body);
        $output = $this->output($decoded);
        $responseModel = $decoded['model'] ?? $model;
        if (!is_string($responseModel)) {
            throw new ProviderResponseException('The compatible provider returned an invalid model identifier.');
        }
        $this->assertModel($responseModel, true);
        return new CompletionResult(
            providerId: $this->id(),
            model: $responseModel,
            output: $output,
            usage: $this->usage($decoded)
        );
    }

    public function validateProvider(): ProviderValidationResult
    {
        try {
            $this->assertConfiguration();
            $this->credentials->resolve($this->config->credentialEnvironmentVariable);
            if (!$this->config->modelDiscovery) {
                return new ProviderValidationResult(
                    providerId: $this->id(),
                    usable: true,
                    issues: [new ValidationIssue(
                        'remote_validation_limited',
                        'Configuration and credentials are valid, but this instance declares no non-generating remote validation endpoint.',
                        ValidationIssue::WARNING
                    )],
                    capabilities: $this->capabilities()
                );
            }
            $this->discoverModels();
            return new ProviderValidationResult($this->id(), true, capabilities: $this->capabilities());
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
        } catch (ProviderCapabilityException $error) {
            return $this->invalid('model_discovery_unavailable', $error->getMessage());
        } catch (ProviderResponseException $error) {
            return $this->invalid('response_invalid', $error->getMessage());
        } catch (HttpTransportException $error) {
            return $this->invalid('transport_unavailable', $error->getMessage(), true);
        } catch (Throwable) {
            return $this->invalid('provider_unavailable', 'The compatible provider is unavailable.', true);
        }
    }

    public function discoverModels(): ModelCatalog
    {
        $this->assertConfiguration();
        if (!$this->config->modelDiscovery) {
            throw new ProviderCapabilityException($this->id(), 'model-discovery');
        }
        $response = $this->http->send($this->request('GET', '/models'));
        $this->assertSuccessful($response, 'model-discovery');
        $records = $this->decode($response->body)['data'] ?? null;
        if (!is_array($records) || !array_is_list($records)) {
            throw new ProviderResponseException('The compatible provider returned an invalid model list.');
        }
        $models = [];
        foreach ($records as $record) {
            if (!is_array($record) || !isset($record['id']) || !is_string($record['id'])) {
                throw new ProviderResponseException('The compatible provider returned an invalid model record.');
            }
            try {
                $models[] = new ModelDescriptor(id: $record['id']);
            } catch (Throwable) {
                throw new ProviderResponseException('The compatible provider returned a malformed model identifier.');
            }
        }
        try {
            return new ModelCatalog($this->id(), $models);
        } catch (Throwable) {
            throw new ProviderResponseException('The compatible provider returned duplicate model records.');
        }
    }

    private function assertConfiguration(): void
    {
        if ($this->credentials->providerId() !== $this->id()) {
            throw new ProviderConfigurationException('The credential resolver belongs to another provider instance.');
        }
        $this->assertModel($this->config->defaultModel);
    }

    private function assertModel(string $model, bool $response = false): void
    {
        if ($model === '' || strlen($model) > 256 || preg_match('/[\x00-\x1F\x7F]/', $model) === 1) {
            throw $response
                ? new ProviderResponseException('The compatible provider returned a malformed model identifier.')
                : new ProviderConfigurationException('The compatible provider model identifier is invalid.');
        }
    }

    private function request(string $method, string $path, ?string $body = null): HttpRequest
    {
        $credential = $this->credentials->resolve($this->config->credentialEnvironmentVariable);
        return new HttpRequest(
            $method,
            $this->config->baseUri . $path,
            ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
            $body,
            ['Authorization' => $credential->prefixed('Bearer ')]
        );
    }

    private function assertSuccessful(HttpResponse $response, string $capability): void
    {
        if ($response->status === 401 || $response->status === 403) {
            throw new ProviderAuthenticationException('Compatible provider authentication failed.');
        }
        if ($response->status === 429) {
            $retry = $response->header('retry-after');
            throw new ProviderRateLimitException(
                'The compatible provider rate limited the request.',
                is_string($retry) && ctype_digit($retry) ? min((int) $retry, 86400) : null
            );
        }
        if ($response->status === 404 && $capability === 'model-discovery') {
            throw new ProviderCapabilityException($this->id(), 'model-discovery');
        }
        if ($response->status >= 500) {
            throw new HttpTransportException('The compatible provider is unavailable with HTTP status ' . $response->status . '.');
        }
        if ($response->status < 200 || $response->status >= 300) {
            throw new ProviderConfigurationException('The compatible provider rejected the request with HTTP status ' . $response->status . '.');
        }
    }

    /** @return array<string, mixed> */
    private function decode(string $body): array
    {
        try {
            $decoded = json_decode($body, true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ProviderResponseException('The compatible provider returned malformed JSON.');
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new ProviderResponseException('The compatible provider returned a non-object response.');
        }
        return $decoded;
    }

    /** @param array<string, mixed> $decoded */
    private function output(array $decoded): string
    {
        if (isset($decoded['error']) && $decoded['error'] !== null) {
            throw new ProviderResponseException('The compatible provider reported an unsuccessful response.');
        }
        if (array_key_exists('status', $decoded)
            && (!is_string($decoded['status']) || $decoded['status'] !== 'completed')) {
            throw new ProviderResponseException('The compatible provider returned an incomplete response.');
        }
        if (isset($decoded['output_text']) && is_string($decoded['output_text'])
            && trim($decoded['output_text']) !== '') {
            return $decoded['output_text'];
        }
        $parts = [];
        $items = $decoded['output'] ?? null;
        if (is_array($items) && array_is_list($items)) {
            foreach ($items as $item) {
                $content = is_array($item) && ($item['type'] ?? null) === 'message'
                    ? ($item['content'] ?? null)
                    : null;
                if (!is_array($content) || !array_is_list($content)) {
                    continue;
                }
                foreach ($content as $value) {
                    if (is_array($value) && ($value['type'] ?? null) === 'output_text'
                        && isset($value['text']) && is_string($value['text'])) {
                        $parts[] = $value['text'];
                    }
                }
            }
        }
        $output = implode('', $parts);
        if (trim($output) === '') {
            throw new ProviderResponseException('The compatible provider does not implement the required Responses text shape.');
        }
        return $output;
    }

    /** @param array<string, mixed> $decoded */
    private function usage(array $decoded): Usage
    {
        $usage = $decoded['usage'] ?? null;
        if ($usage === null) {
            return new Usage();
        }
        if (!is_array($usage) || array_is_list($usage)) {
            throw new ProviderResponseException('The compatible provider returned malformed usage information.');
        }
        $values = [];
        foreach (['input_tokens', 'output_tokens', 'total_tokens'] as $key) {
            $value = $usage[$key] ?? null;
            if ($value !== null && (!is_int($value) || $value < 0)) {
                throw new ProviderResponseException('The compatible provider returned malformed usage information.');
            }
            $values[] = $value;
        }
        return new Usage($values[0], $values[1], $values[2], 'tokens', true);
    }

    private function invalid(string $code, string $message, bool $retryable = false): ProviderValidationResult
    {
        $message = trim($this->redactor->redact($message)) ?: 'The compatible provider is not usable.';
        return new ProviderValidationResult(
            $this->id(),
            false,
            [new ValidationIssue($code, $message, ValidationIssue::ERROR, $retryable)],
            $this->capabilities()
        );
    }
}
