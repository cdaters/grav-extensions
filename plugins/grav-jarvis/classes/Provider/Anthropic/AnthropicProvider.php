<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Provider\Anthropic;

use Grav\Plugin\GravJarvis\Contracts\CompletionRequest;
use Grav\Plugin\GravJarvis\Contracts\CompletionResult;
use Grav\Plugin\GravJarvis\Contracts\CredentialResolverInterface;
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
use Grav\Plugin\GravJarvis\Security\EnvironmentCredentialResolver;
use Grav\Plugin\GravJarvis\Security\SecretRedactor;
use Grav\Plugin\GravJarvis\Transport\BoundedHttpTransport;
use JsonException;
use Throwable;

/**
 * Official Anthropic adapter. Messages, content blocks, model records, headers,
 * status details, and usage fields remain private to this namespace.
 */
final class AnthropicProvider implements
    ProviderInterface,
    ProviderValidationInterface,
    ModelDiscoveryInterface
{
    public const ID = 'anthropic';
    public const API_BASE_URI = 'https://api.anthropic.com/v1';
    public const API_VERSION = '2023-06-01';
    public const CREDENTIAL_ENVIRONMENT_VARIABLE = 'GRAV_JARVIS_ANTHROPIC_API_KEY';
    public const DEFAULT_MODEL = 'claude-sonnet-5';
    public const DEFAULT_MAX_OUTPUT_UNITS = 1024;
    private const MODEL_PAGE_LIMIT = 1000;
    private const MAX_MODEL_PAGES = 4;

    private readonly SecretRedactor $redactor;

    public function __construct(
        private readonly string $defaultModel,
        private readonly CredentialResolverInterface $credentials,
        private readonly HttpTransportInterface $http
    ) {
        $this->redactor = SecretRedactor::fromEnvironment();
    }

    public static function createProduction(string $defaultModel = self::DEFAULT_MODEL): self
    {
        return new self(
            $defaultModel,
            new EnvironmentCredentialResolver(self::ID),
            BoundedHttpTransport::forAllowedBaseUris([self::API_BASE_URI])
        );
    }

    public function id(): string
    {
        return self::ID;
    }

    public function capabilities(): array
    {
        return ['model-discovery', 'provider-validation', 'text-completion'];
    }

    public function complete(CompletionRequest $request): CompletionResult
    {
        $this->assertConfiguration();
        if ($request->providerId !== self::ID) {
            throw new ProviderException('The Anthropic provider received a request for another provider.');
        }

        $model = $request->model ?? trim($this->defaultModel);
        $this->assertModel($model);
        $payload = [
            'model' => $model,
            'max_tokens' => $this->maxOutputUnits($request->options),
            'messages' => [[
                'role' => 'user',
                'content' => $request->input,
            ]],
        ];
        if ($request->instructions !== null) {
            $payload['system'] = $request->instructions;
        }

        try {
            $body = json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            throw new ProviderConfigurationException('The Anthropic request could not be encoded.');
        }

        $response = $this->http->send($this->request('POST', '/messages', $body));
        $this->assertSuccessful($response);
        $decoded = $this->decodeObject($response->body);
        $output = $this->extractOutput($decoded);
        $responseModel = $decoded['model'] ?? null;
        if (!is_string($responseModel)) {
            throw new ProviderResponseException('Anthropic returned an invalid model identifier.');
        }
        $this->assertModel($responseModel, true);

        return new CompletionResult(
            providerId: self::ID,
            model: $responseModel,
            output: $output,
            usage: $this->extractUsage($decoded)
        );
    }

    public function validateProvider(): ProviderValidationResult
    {
        try {
            $this->assertConfiguration();
            $this->discoverModels();
            return new ProviderValidationResult(
                providerId: self::ID,
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
        } catch (Throwable) {
            return $this->invalid('provider_unavailable', 'Anthropic is currently unavailable.', true);
        }
    }

    public function discoverModels(): ModelCatalog
    {
        $this->assertConfiguration();
        $models = [];
        $path = '/models?limit=' . self::MODEL_PAGE_LIMIT;
        for ($page = 0; $page < self::MAX_MODEL_PAGES; ++$page) {
            $response = $this->http->send($this->request('GET', $path));
            $this->assertSuccessful($response);
            $decoded = $this->decodeObject($response->body);
            $records = $decoded['data'] ?? null;
            if (!is_array($records) || !array_is_list($records)) {
                throw new ProviderResponseException('Anthropic returned an invalid model list.');
            }
            $hasMore = $decoded['has_more'] ?? false;
            if (!is_bool($hasMore)) {
                throw new ProviderResponseException('Anthropic returned invalid model pagination metadata.');
            }

            foreach ($records as $record) {
                if (!is_array($record)
                    || !isset($record['id'], $record['display_name'])
                    || !is_string($record['id'])
                    || !is_string($record['display_name'])
                    || (isset($record['type']) && $record['type'] !== 'model')) {
                    throw new ProviderResponseException('Anthropic returned an invalid model record.');
                }
                try {
                    $models[] = new ModelDescriptor(
                        id: $record['id'],
                        label: $record['display_name'],
                        capabilities: ['text-completion']
                    );
                } catch (Throwable) {
                    throw new ProviderResponseException('Anthropic returned a malformed model record.');
                }
            }

            if (!$hasMore) {
                break;
            }
            $lastId = $decoded['last_id'] ?? null;
            if (!is_string($lastId)
                || $lastId === ''
                || strlen($lastId) > 256
                || preg_match('/[\x00-\x1F\x7F]/', $lastId) === 1) {
                throw new ProviderResponseException('Anthropic returned invalid model pagination metadata.');
            }
            $path = '/models?limit=' . self::MODEL_PAGE_LIMIT
                . '&after_id=' . rawurlencode($lastId);
        }
        if ($hasMore ?? false) {
            throw new ProviderResponseException('Anthropic model discovery exceeded its bounded page limit.');
        }

        try {
            return new ModelCatalog(self::ID, $models);
        } catch (Throwable) {
            throw new ProviderResponseException('Anthropic returned duplicate or malformed model records.');
        }
    }

    private function assertConfiguration(): void
    {
        if ($this->credentials->providerId() !== self::ID) {
            throw new ProviderConfigurationException('The credential resolver belongs to another provider.');
        }
        $this->assertModel(trim($this->defaultModel));
    }

    private function assertModel(string $model, bool $providerResponse = false): void
    {
        if ($model === ''
            || strlen($model) > 256
            || preg_match('/[\x00-\x1F\x7F]/', $model) === 1) {
            throw $providerResponse
                ? new ProviderResponseException('Anthropic returned a malformed model identifier.')
                : new ProviderConfigurationException('The Anthropic model identifier is invalid.');
        }
    }

    /** @param array<string, mixed> $options */
    private function maxOutputUnits(array $options): int
    {
        foreach (array_keys($options) as $key) {
            if ($key !== 'max_output_units') {
                throw new ProviderConfigurationException(
                    'The Anthropic 0.1.4 adapter received an unsupported provider-neutral option.'
                );
            }
        }
        $value = $options['max_output_units'] ?? self::DEFAULT_MAX_OUTPUT_UNITS;
        if (!is_int($value) || $value < 1 || $value > 65536) {
            throw new ProviderConfigurationException(
                'The provider-neutral output limit must be an integer between 1 and 65536.'
            );
        }
        return $value;
    }

    private function request(string $method, string $path, ?string $body = null): HttpRequest
    {
        $credential = $this->credentials->resolve(self::CREDENTIAL_ENVIRONMENT_VARIABLE);
        return new HttpRequest(
            method: $method,
            uri: self::API_BASE_URI . $path,
            headers: [
                'Accept' => 'application/json',
                'Anthropic-Version' => self::API_VERSION,
                'Content-Type' => 'application/json',
            ],
            body: $body,
            credentialHeaders: ['X-Api-Key' => $credential]
        );
    }

    private function assertSuccessful(HttpResponse $response): void
    {
        if ($response->status === 401 || $response->status === 403) {
            throw new ProviderAuthenticationException('Anthropic authentication or access failed.');
        }
        if ($response->status === 429) {
            $retryAfter = $response->header('retry-after');
            $retryAfterSeconds = is_string($retryAfter) && ctype_digit($retryAfter)
                ? min((int) $retryAfter, 86400)
                : null;
            throw new ProviderRateLimitException('Anthropic rate limited the request.', $retryAfterSeconds);
        }
        if ($response->status >= 500) {
            throw new HttpTransportException(
                'Anthropic is unavailable with HTTP status ' . $response->status . '.'
            );
        }
        if ($response->status < 200 || $response->status >= 300) {
            throw new ProviderConfigurationException(
                'Anthropic rejected the request with HTTP status ' . $response->status . '.'
            );
        }
    }

    /** @return array<string, mixed> */
    private function decodeObject(string $body): array
    {
        try {
            $decoded = json_decode($body, true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ProviderResponseException('Anthropic returned malformed JSON.');
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new ProviderResponseException('Anthropic returned a non-object response.');
        }
        return $decoded;
    }

    /** @param array<string, mixed> $decoded */
    private function extractOutput(array $decoded): string
    {
        if (isset($decoded['error']) && $decoded['error'] !== null) {
            throw new ProviderResponseException('Anthropic reported an unsuccessful response.');
        }
        if (($decoded['type'] ?? null) !== 'message') {
            throw new ProviderResponseException('Anthropic returned an unexpected response type.');
        }
        if (($decoded['role'] ?? null) !== 'assistant') {
            throw new ProviderResponseException('Anthropic returned an unexpected response role.');
        }
        $stopReason = $decoded['stop_reason'] ?? null;
        if ($stopReason !== null
            && (!is_string($stopReason) || !in_array($stopReason, ['end_turn', 'stop_sequence'], true))) {
            throw new ProviderResponseException('Anthropic returned an incomplete or unsupported completion.');
        }

        $content = $decoded['content'] ?? null;
        if (!is_array($content) || !array_is_list($content)) {
            throw new ProviderResponseException('Anthropic returned no usable text output.');
        }
        $parts = [];
        foreach ($content as $block) {
            if (!is_array($block)) {
                throw new ProviderResponseException('Anthropic returned a malformed content block.');
            }
            if (($block['type'] ?? null) !== 'text') {
                continue;
            }
            if (!isset($block['text']) || !is_string($block['text'])) {
                throw new ProviderResponseException('Anthropic returned a malformed text block.');
            }
            $parts[] = $block['text'];
        }
        $output = implode('', $parts);
        if (trim($output) === '') {
            throw new ProviderResponseException('Anthropic returned no usable text output.');
        }
        return $output;
    }

    /** @param array<string, mixed> $decoded */
    private function extractUsage(array $decoded): Usage
    {
        if (!array_key_exists('usage', $decoded) || $decoded['usage'] === null) {
            return new Usage();
        }
        if (!is_array($decoded['usage']) || array_is_list($decoded['usage'])) {
            throw new ProviderResponseException('Anthropic returned malformed usage information.');
        }
        $input = $this->usageValue($decoded['usage'], 'input_tokens');
        $output = $this->usageValue($decoded['usage'], 'output_tokens');
        return new Usage(
            inputUnits: $input,
            outputUnits: $output,
            totalUnits: $input !== null && $output !== null ? $input + $output : null,
            unit: 'tokens',
            providerReported: true
        );
    }

    /** @param array<string, mixed> $usage */
    private function usageValue(array $usage, string $key): ?int
    {
        if (!array_key_exists($key, $usage)) {
            return null;
        }
        if (!is_int($usage[$key]) || $usage[$key] < 0) {
            throw new ProviderResponseException('Anthropic returned malformed usage information.');
        }
        return $usage[$key];
    }

    private function invalid(string $code, string $message, bool $retryable = false): ProviderValidationResult
    {
        $message = trim($this->redactor->redact($message));
        if ($message === '') {
            $message = 'Anthropic is not usable.';
        }
        return new ProviderValidationResult(
            providerId: self::ID,
            usable: false,
            issues: [new ValidationIssue($code, $message, ValidationIssue::ERROR, $retryable)],
            capabilities: $this->capabilities()
        );
    }
}
