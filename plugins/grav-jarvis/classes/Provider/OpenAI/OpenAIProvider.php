<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Provider\OpenAI;

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
use Grav\Plugin\GravJarvis\Security\EnvironmentCredentialResolver;
use Grav\Plugin\GravJarvis\Security\SecretRedactor;
use Grav\Plugin\GravJarvis\Transport\BoundedHttpTransport;
use JsonException;
use Throwable;

/**
 * Official OpenAI adapter. Vendor request, response, status, and usage fields
 * are intentionally contained in this namespace.
 */
final class OpenAIProvider implements
    ProviderInterface,
    ProviderValidationInterface,
    ModelDiscoveryInterface
{
    public const ID = 'openai';
    public const API_BASE_URI = 'https://api.openai.com/v1';
    public const CREDENTIAL_ENVIRONMENT_VARIABLE = 'GRAV_JARVIS_OPENAI_API_KEY';
    public const DEFAULT_MODEL = 'gpt-5.6-luna';

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
            throw new ProviderException('The OpenAI provider received a request for another provider.');
        }
        if ($request->options !== []) {
            throw new ProviderConfigurationException(
                'The OpenAI 0.1.2 adapter does not support request options.'
            );
        }

        $model = $request->model ?? trim($this->defaultModel);
        $this->assertModel($model);
        $payload = [
            'model' => $model,
            'input' => $request->input,
            'store' => false,
        ];
        if ($request->instructions !== null) {
            $payload['instructions'] = $request->instructions;
        }

        try {
            $body = json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            throw new ProviderConfigurationException('The OpenAI request could not be encoded.');
        }

        $response = $this->http->send($this->request('POST', '/responses', $body));
        $this->assertSuccessful($response);
        $decoded = $this->decodeObject($response->body);
        $output = $this->extractOutput($decoded);
        $responseModel = $decoded['model'] ?? $model;
        if (!is_string($responseModel)) {
            throw new ProviderResponseException('OpenAI returned an invalid model identifier.');
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
            return $this->invalid('provider_unavailable', 'OpenAI is currently unavailable.', true);
        }
    }

    public function discoverModels(): ModelCatalog
    {
        $this->assertConfiguration();
        $response = $this->http->send($this->request('GET', '/models'));
        $this->assertSuccessful($response);
        $decoded = $this->decodeObject($response->body);
        $records = $decoded['data'] ?? null;
        if (!is_array($records) || !array_is_list($records)) {
            throw new ProviderResponseException('OpenAI returned an invalid model list.');
        }

        $models = [];
        foreach ($records as $record) {
            if (!is_array($record) || !isset($record['id']) || !is_string($record['id'])) {
                throw new ProviderResponseException('OpenAI returned an invalid model record.');
            }
            try {
                $models[] = new ModelDescriptor(id: $record['id']);
            } catch (Throwable) {
                throw new ProviderResponseException('OpenAI returned a malformed model identifier.');
            }
        }

        try {
            return new ModelCatalog(self::ID, $models);
        } catch (Throwable) {
            throw new ProviderResponseException('OpenAI returned duplicate or malformed model records.');
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
            if ($providerResponse) {
                throw new ProviderResponseException('OpenAI returned a malformed model identifier.');
            }
            throw new ProviderConfigurationException('The OpenAI model identifier is invalid.');
        }
    }

    private function request(string $method, string $path, ?string $body = null): HttpRequest
    {
        $credential = $this->credentials->resolve(self::CREDENTIAL_ENVIRONMENT_VARIABLE);
        return new HttpRequest(
            method: $method,
            uri: self::API_BASE_URI . $path,
            headers: [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            body: $body,
            credentialHeaders: [
                'Authorization' => $this->bearer($credential),
            ]
        );
    }

    private function bearer(CredentialValueInterface $credential): CredentialValueInterface
    {
        return $credential->prefixed('Bearer ');
    }

    private function assertSuccessful(HttpResponse $response): void
    {
        if ($response->status === 401 || $response->status === 403) {
            throw new ProviderAuthenticationException('OpenAI authentication failed.');
        }
        if ($response->status === 429) {
            $retryAfter = $response->header('retry-after');
            $retryAfterSeconds = is_string($retryAfter) && ctype_digit($retryAfter)
                ? min((int) $retryAfter, 86400)
                : null;
            throw new ProviderRateLimitException('OpenAI rate limited the request.', $retryAfterSeconds);
        }
        if ($response->status >= 500) {
            throw new HttpTransportException(
                'OpenAI is unavailable with HTTP status ' . $response->status . '.'
            );
        }
        if ($response->status < 200 || $response->status >= 300) {
            throw new ProviderConfigurationException(
                'OpenAI rejected the request with HTTP status ' . $response->status . '.'
            );
        }
    }

    /** @return array<string, mixed> */
    private function decodeObject(string $body): array
    {
        try {
            $decoded = json_decode($body, true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ProviderResponseException('OpenAI returned malformed JSON.');
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new ProviderResponseException('OpenAI returned a non-object response.');
        }
        return $decoded;
    }

    /** @param array<string, mixed> $decoded */
    private function extractOutput(array $decoded): string
    {
        if (isset($decoded['error']) && $decoded['error'] !== null) {
            throw new ProviderResponseException('OpenAI reported an unsuccessful response.');
        }
        if (array_key_exists('status', $decoded)
            && (!is_string($decoded['status']) || $decoded['status'] !== 'completed')) {
            throw new ProviderResponseException('OpenAI returned an incomplete response.');
        }

        $outputText = $decoded['output_text'] ?? null;
        if (is_string($outputText) && trim($outputText) !== '') {
            return $outputText;
        }

        $items = $decoded['output'] ?? null;
        if (!is_array($items) || !array_is_list($items)) {
            throw new ProviderResponseException('OpenAI returned no usable text output.');
        }
        $parts = [];
        foreach ($items as $item) {
            if (!is_array($item) || ($item['type'] ?? null) !== 'message') {
                continue;
            }
            $content = $item['content'] ?? null;
            if (!is_array($content) || !array_is_list($content)) {
                continue;
            }
            foreach ($content as $contentItem) {
                if (is_array($contentItem)
                    && ($contentItem['type'] ?? null) === 'output_text'
                    && isset($contentItem['text'])
                    && is_string($contentItem['text'])) {
                    $parts[] = $contentItem['text'];
                }
            }
        }
        $output = implode('', $parts);
        if (trim($output) === '') {
            throw new ProviderResponseException('OpenAI returned no usable text output.');
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
            throw new ProviderResponseException('OpenAI returned malformed usage information.');
        }
        $usage = $decoded['usage'];
        $input = $this->usageValue($usage, 'input_tokens');
        $output = $this->usageValue($usage, 'output_tokens');
        $total = $this->usageValue($usage, 'total_tokens');

        return new Usage(
            inputUnits: $input,
            outputUnits: $output,
            totalUnits: $total,
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
            throw new ProviderResponseException('OpenAI returned malformed usage information.');
        }
        return $usage[$key];
    }

    private function invalid(string $code, string $message, bool $retryable = false): ProviderValidationResult
    {
        $message = trim($this->redactor->redact($message));
        if ($message === '') {
            $message = 'OpenAI is not usable.';
        }
        return new ProviderValidationResult(
            providerId: self::ID,
            usable: false,
            issues: [new ValidationIssue($code, $message, ValidationIssue::ERROR, $retryable)],
            capabilities: $this->capabilities()
        );
    }
}
