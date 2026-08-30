<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Plugin\GravJarvis\Provider\OpenAI\OpenAIProvider;
use Grav\Plugin\GravJarvis\Provider\OpenAICompatible\CompatibleProviderConfig;
use Grav\Plugin\GravJarvis\Provider\OpenAICompatible\OpenAICompatibleProvider;
use Grav\Plugin\GravJarvis\Provider\ProviderRegistry;
use Grav\Plugin\GravJarvis\Security\SecretRedactor;
use Grav\Plugin\GravJarvis\Service\JarvisService;
use RocketTheme\Toolbox\Event\Event;
use Throwable;

final class GravJarvisPlugin extends Plugin
{
    public const SLUG = 'grav-jarvis';
    public const SERVICE_KEY = 'gravJarvis';
    public const PROVIDER_EVENT = 'onJarvisProviderRegister';

    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
        ];
    }

    public function autoload(): void
    {
        spl_autoload_register(static function (string $class): void {
            $prefix = 'Grav\\Plugin\\GravJarvis\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }
            $file = __DIR__ . '/classes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });
    }

    public function onPluginsInitialized(): void
    {
        if (!$this->config->get('plugins.' . self::SLUG . '.enabled', true)) {
            return;
        }

        $registry = new ProviderRegistry();
        $redactor = SecretRedactor::fromEnvironment();

        if ($this->config->get('plugins.' . self::SLUG . '.providers.openai.enabled', true)) {
            try {
                $defaultModel = $this->config->get(
                    'plugins.' . self::SLUG . '.providers.openai.default_model',
                    OpenAIProvider::DEFAULT_MODEL
                );
                if (!is_string($defaultModel)) {
                    throw new \InvalidArgumentException('The OpenAI default model must be a string.');
                }
                $registry->register(OpenAIProvider::createProduction($defaultModel));
            } catch (Throwable $error) {
                $this->logRegistrationFailure('OpenAI registration failed', $error, $redactor);
            }
        }

        if ($this->config->get('plugins.' . self::SLUG . '.providers.openai_compatible.enabled', false)) {
            $instances = $this->config->get(
                'plugins.' . self::SLUG . '.providers.openai_compatible.instances',
                []
            );
            if (!is_array($instances)) {
                $this->logRegistrationFailure(
                    'Compatible provider registration failed',
                    new \InvalidArgumentException('Compatible provider instances must be a list.'),
                    $redactor
                );
            } else {
                foreach ($instances as $instance) {
                    try {
                        if (!is_array($instance)) {
                            throw new \InvalidArgumentException(
                                'Compatible provider instance configuration must be a map.'
                            );
                        }
                        $config = CompatibleProviderConfig::fromArray($instance);
                        $registry->register(OpenAICompatibleProvider::createProduction($config));
                    } catch (Throwable $error) {
                        $this->logRegistrationFailure(
                            'Compatible provider registration failed',
                            $error,
                            $redactor
                        );
                    }
                }
            }
        }

        try {
            $this->grav->fireEvent(self::PROVIDER_EVENT, new Event(['registry' => $registry]));
        } catch (Throwable $error) {
            $this->logRegistrationFailure('Provider event failed', $error, $redactor);
        }

        if (!isset($this->grav[self::SERVICE_KEY])) {
            $service = new JarvisService($registry, $redactor);
            $this->grav[self::SERVICE_KEY] = static fn (): JarvisService => $service;
        }
    }

    private function logRegistrationFailure(
        string $context,
        Throwable $error,
        SecretRedactor $redactor
    ): void {
        $safeMessage = $redactor->redact($error->getMessage());
        $log = $this->grav['log'] ?? null;
        if (is_object($log) && method_exists($log, 'error')) {
            $log->error('[Jarvis] ' . $context . ': ' . $safeMessage);
        }
    }
}
