<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Provider\OpenAICompatible;

use Grav\Plugin\GravJarvis\Contracts\CompletionRequest;
use Grav\Plugin\GravJarvis\Contracts\Exception\ProviderConfigurationException;

final readonly class CompatibleProviderConfig
{
    public function __construct(
        public string $providerId,
        public string $baseUri,
        public string $credentialEnvironmentVariable,
        public string $defaultModel,
        public bool $modelDiscovery = true
    ) {
        if (!CompletionRequest::validIdentifier($this->providerId)
            || $this->providerId === 'openai') {
            throw new ProviderConfigurationException('The compatible provider identifier is invalid.');
        }
        $parts = parse_url($this->baseUri);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || rtrim($this->baseUri, '/') !== $this->baseUri) {
            throw new ProviderConfigurationException(
                'The compatible provider base URI must be an absolute HTTPS origin or base path without a trailing slash.'
            );
        }
        if ($this->defaultModel === ''
            || strlen($this->defaultModel) > 256
            || preg_match('/[\x00-\x1F\x7F]/', $this->defaultModel) === 1) {
            throw new ProviderConfigurationException('The compatible provider model identifier is invalid.');
        }
        if (preg_match('/^GRAV_JARVIS_[A-Z][A-Z0-9_]{2,120}$/D', $this->credentialEnvironmentVariable) !== 1) {
            throw new ProviderConfigurationException(
                'The compatible provider credential environment-variable name is invalid.'
            );
        }
    }

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        foreach (['id', 'base_uri', 'credential_environment_variable', 'default_model'] as $key) {
            if (!isset($values[$key]) || !is_string($values[$key])) {
                throw new ProviderConfigurationException(
                    'A compatible provider instance is missing required non-secret configuration.'
                );
            }
        }
        $modelDiscovery = $values['model_discovery'] ?? true;
        if (!is_bool($modelDiscovery)) {
            throw new ProviderConfigurationException('Compatible provider capability configuration is invalid.');
        }
        return new self(
            providerId: trim($values['id']),
            baseUri: trim($values['base_uri']),
            credentialEnvironmentVariable: trim($values['credential_environment_variable']),
            defaultModel: trim($values['default_model']),
            modelDiscovery: $modelDiscovery
        );
    }

    /** @return array<string, string|bool> */
    public function toArray(): array
    {
        return [
            'id' => $this->providerId,
            'base_uri' => $this->baseUri,
            'credential_environment_variable' => $this->credentialEnvironmentVariable,
            'default_model' => $this->defaultModel,
            'model_discovery' => $this->modelDiscovery,
        ];
    }
}
