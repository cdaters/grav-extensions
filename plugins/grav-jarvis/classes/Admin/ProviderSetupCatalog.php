<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Admin;

use Grav\Plugin\GravJarvis\Contracts\Exception\MalformedCredentialException;
use Grav\Plugin\GravJarvis\Contracts\Exception\MissingCredentialException;
use Grav\Plugin\GravJarvis\Contracts\JarvisServiceInterface;
use Grav\Plugin\GravJarvis\Provider\Anthropic\AnthropicProvider;
use Grav\Plugin\GravJarvis\Provider\OpenAI\OpenAIProvider;
use Grav\Plugin\GravJarvis\Provider\OpenAICompatible\CompatibleProviderConfig;
use Grav\Plugin\GravJarvis\Security\EnvironmentCredentialResolver;
use Throwable;

/**
 * Builds browser-safe operator metadata from server configuration.
 *
 * Credential values are resolved only to classify local presence/shape and are
 * never retained or returned. Remote validity remains the provider validation
 * operation's responsibility.
 */
final class ProviderSetupCatalog
{
    /** @var array<string, mixed> */
    private array $configuration;

    /** @param array<string, mixed> $configuration */
    public function __construct(
        array $configuration,
        private readonly JarvisServiceInterface $jarvis
    ) {
        $this->configuration = $configuration;
    }

    /** @return list<array<string, mixed>> */
    public function providers(): array
    {
        $registered = $this->jarvis->providerIds();
        $providers = [];

        $providers[] = $this->official(
            OpenAIProvider::ID,
            'OpenAI',
            OpenAIProvider::CREDENTIAL_ENVIRONMENT_VARIABLE,
            OpenAIProvider::DEFAULT_MODEL,
            'https://platform.openai.com/api-keys',
            'Use an OpenAI API-platform project key. A ChatGPT login or subscription is not an API credential, and Jarvis never uses ChatGPT browser or session data.',
            $registered
        );
        $providers[] = $this->official(
            AnthropicProvider::ID,
            'Anthropic',
            AnthropicProvider::CREDENTIAL_ENVIRONMENT_VARIABLE,
            AnthropicProvider::DEFAULT_MODEL,
            'https://console.anthropic.com/settings/keys',
            'Create an API key in the Claude Console, then place it in the server environment. Claude browser-session credentials are not used.',
            $registered
        );

        $compatible = $this->nested($this->configuration, ['providers', 'openai_compatible']);
        $compatibleEnabled = $this->boolean($compatible['enabled'] ?? false, false);
        $instances = $compatible['instances'] ?? [];
        if (is_array($instances)) {
            foreach ($instances as $instance) {
                if (!is_array($instance)) {
                    continue;
                }
                try {
                    $config = CompatibleProviderConfig::fromArray($instance);
                    $isRegistered = in_array($config->providerId, $registered, true);
                    $providers[] = [
                        'id' => $config->providerId,
                        'label' => $this->label($config->providerId),
                        'kind' => 'openai-compatible',
                        'enabled' => $compatibleEnabled,
                        'registered' => $isRegistered,
                        'credential_environment_variable' => $config->credentialEnvironmentVariable,
                        'credential_status' => $this->credentialStatus(
                            $config->providerId,
                            $config->credentialEnvironmentVariable
                        ),
                        'default_model' => $config->defaultModel,
                        'base_uri' => $config->baseUri,
                        'model_discovery' => $config->modelDiscovery,
                        'official_setup_url' => null,
                        'guidance' => 'Set the named credential in the server environment. Jarvis stores only this immutable public HTTPS base URI and non-secret instance metadata.',
                        'capabilities' => $this->capabilities($config->providerId, $isRegistered),
                        'configuration_status' => $compatibleEnabled && !$isRegistered
                            ? 'invalid'
                            : 'valid',
                    ];
                } catch (Throwable) {
                    // Invalid stored values are rejected by registration and
                    // represented as one safe configuration issue below.
                    $providers[] = [
                        'id' => 'compatible-configuration',
                        'label' => 'OpenAI-compatible instance',
                        'kind' => 'openai-compatible',
                        'enabled' => $compatibleEnabled,
                        'registered' => false,
                        'credential_environment_variable' => null,
                        'credential_status' => 'unknown',
                        'default_model' => null,
                        'base_uri' => null,
                        'model_discovery' => null,
                        'official_setup_url' => null,
                        'guidance' => 'One stored compatible-provider instance is invalid. Review its non-secret fields in Jarvis plugin settings.',
                        'capabilities' => [],
                        'configuration_status' => 'invalid',
                    ];
                }
            }
        }

        $known = array_fill_keys(array_column($providers, 'id'), true);
        foreach ($registered as $providerId) {
            if (isset($known[$providerId])) {
                continue;
            }
            $providers[] = [
                'id' => $providerId,
                'label' => $this->label($providerId),
                'kind' => 'extension',
                'enabled' => true,
                'registered' => true,
                'credential_environment_variable' => null,
                'credential_status' => 'managed',
                'default_model' => null,
                'base_uri' => null,
                'model_discovery' => in_array('model-discovery', $this->capabilities($providerId, true), true),
                'official_setup_url' => null,
                'guidance' => 'This provider is registered by another installed plugin. Its operator documentation owns credential and endpoint setup.',
                'capabilities' => $this->capabilities($providerId, true),
                'configuration_status' => 'valid',
            ];
        }

        return $providers;
    }

    public function defaultProvider(): ?string
    {
        $registered = $this->jarvis->providerIds();
        if ($registered === []) {
            return null;
        }
        $admin = $this->nested($this->configuration, ['admin']);
        $preferred = $admin['default_provider'] ?? OpenAIProvider::ID;
        if (is_string($preferred)) {
            $preferred = trim($preferred);
            if (in_array($preferred, $registered, true)) {
                return $preferred;
            }
        }
        return $registered[0];
    }

    /** @param list<string> $registered @return array<string, mixed> */
    private function official(
        string $id,
        string $label,
        string $environmentVariable,
        string $fallbackModel,
        string $setupUrl,
        string $guidance,
        array $registered
    ): array {
        $configuration = $this->nested($this->configuration, ['providers', $id]);
        $enabled = $this->boolean($configuration['enabled'] ?? true, true);
        $defaultModel = $configuration['default_model'] ?? $fallbackModel;
        $defaultModel = is_string($defaultModel) && $this->validModel($defaultModel)
            ? trim($defaultModel)
            : null;
        $isRegistered = in_array($id, $registered, true);

        return [
            'id' => $id,
            'label' => $label,
            'kind' => 'official',
            'enabled' => $enabled,
            'registered' => $isRegistered,
            'credential_environment_variable' => $environmentVariable,
            'credential_status' => $this->credentialStatus($id, $environmentVariable),
            'default_model' => $defaultModel,
            'base_uri' => null,
            'model_discovery' => true,
            'official_setup_url' => $setupUrl,
            'guidance' => $guidance,
            'capabilities' => $this->capabilities($id, $isRegistered),
            'configuration_status' => $enabled && (!$isRegistered || $defaultModel === null)
                ? 'invalid'
                : 'valid',
        ];
    }

    private function credentialStatus(string $providerId, string $environmentVariable): string
    {
        try {
            (new EnvironmentCredentialResolver($providerId))->resolve($environmentVariable);
            return 'configured';
        } catch (MissingCredentialException) {
            return 'missing';
        } catch (MalformedCredentialException) {
            return 'invalid';
        } catch (Throwable) {
            return 'invalid';
        }
    }

    /** @return list<string> */
    private function capabilities(string $providerId, bool $registered): array
    {
        if (!$registered) {
            return [];
        }
        try {
            return $this->jarvis->capabilities($providerId);
        } catch (Throwable) {
            return [];
        }
    }

    /** @param array<string, mixed> $values @param list<string> $path @return array<string, mixed> */
    private function nested(array $values, array $path): array
    {
        $value = $values;
        foreach ($path as $key) {
            $value = $value[$key] ?? [];
            if (!is_array($value)) {
                return [];
            }
        }
        return $value;
    }

    private function boolean(mixed $value, bool $fallback): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 0 || $value === '0') {
            return false;
        }
        if ($value === 1 || $value === '1') {
            return true;
        }
        return $fallback;
    }

    private function validModel(string $model): bool
    {
        $model = trim($model);
        return $model !== ''
            && strlen($model) <= 256
            && preg_match('/[\x00-\x1F\x7F]/', $model) !== 1;
    }

    private function label(string $id): string
    {
        return ucwords(str_replace(['-', '_', '.'], ' ', $id));
    }
}
