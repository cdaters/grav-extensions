<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Plugin\GravJarvis\Provider\Anthropic\AnthropicProvider;
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
            'onApiRegisterRoutes' => ['onApiRegisterRoutes', 0],
            'onApiSidebarItems' => ['onApiSidebarItems', 0],
            'onApiPluginPageInfo' => ['onApiPluginPageInfo', 0],
            'onApiContextPanels' => ['onApiContextPanels', 0],
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

        if ($this->config->get('plugins.' . self::SLUG . '.providers.anthropic.enabled', true)) {
            try {
                $defaultModel = $this->config->get(
                    'plugins.' . self::SLUG . '.providers.anthropic.default_model',
                    AnthropicProvider::DEFAULT_MODEL
                );
                if (!is_string($defaultModel)) {
                    throw new \InvalidArgumentException('The Anthropic default model must be a string.');
                }
                $registry->register(AnthropicProvider::createProduction($defaultModel));
            } catch (Throwable $error) {
                $this->logRegistrationFailure('Anthropic registration failed', $error, $redactor);
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

    public function onApiRegisterRoutes(Event $event): void
    {
        if (!$this->enabled()) {
            return;
        }
        require_once __DIR__ . '/classes/Controller/ApiController.php';
        $controller = \Grav\Plugin\GravJarvis\Controller\ApiController::class;
        $event['routes']->group('/grav-jarvis', static function ($group) use ($controller): void {
            $group->get('/bootstrap', [$controller, 'bootstrap']);
            $group->post('/providers/{id}/validate', [$controller, 'validateProvider']);
            $group->get('/providers/{id}/models', [$controller, 'models']);
            $group->post('/completions', [$controller, 'complete']);
            $group->get('/page-context', [$controller, 'pageContext']);
            $group->post('/proposals', [$controller, 'propose']);
            $group->post('/proposals/{id}/accept', [$controller, 'accept']);
            $group->post('/proposals/{id}/discard', [$controller, 'discard']);
        });
    }

    public function onApiSidebarItems(Event $event): void
    {
        if (!$this->enabled() || !$this->config->get('plugins.' . self::SLUG . '.admin.show_sidebar', true)) {
            return;
        }
        $user = $event['user'] ?? null;
        if (!is_object($user) || !$this->userCan($user, 'grav-jarvis.access')) {
            return;
        }
        $items = (array) ($event['items'] ?? []);
        $items[] = [
            'id' => self::SLUG,
            'plugin' => self::SLUG,
            'label' => 'Jarvis',
            'icon' => 'fa-wand-magic-sparkles',
            'route' => '/plugin/' . self::SLUG,
            'priority' => 9,
            'authorize' => ['grav-jarvis.access'],
        ];
        $event['items'] = $items;
    }

    public function onApiPluginPageInfo(Event $event): void
    {
        if (($event['plugin'] ?? null) !== self::SLUG || !$this->enabled()) {
            return;
        }
        $user = $event['user'] ?? null;
        if (is_object($user) && !$this->userCan($user, 'grav-jarvis.access')) {
            return;
        }
        $event['definition'] = [
            'id' => self::SLUG,
            'plugin' => self::SLUG,
            'title' => 'Jarvis',
            'icon' => 'fa-wand-magic-sparkles',
            'page_type' => 'component',
        ];
    }

    public function onApiContextPanels(Event $event): void
    {
        if (!$this->enabled() || !$this->config->get('plugins.' . self::SLUG . '.admin.show_page_panel', true)) {
            return;
        }
        $user = $event['user'] ?? null;
        if (!is_object($user) || !$this->userCan($user, 'grav-jarvis.use')) {
            return;
        }
        $panels = (array) ($event['panels'] ?? []);
        $panels[] = [
            'id' => self::SLUG,
            'plugin' => self::SLUG,
            'label' => 'Jarvis',
            'icon' => 'sparkles',
            'contexts' => ['pages'],
            'priority' => 20,
            'width' => 620,
        ];
        $event['panels'] = $panels;
    }

    private function enabled(): bool
    {
        return (bool) $this->config->get('plugins.' . self::SLUG . '.enabled', true);
    }

    private function userCan(object $user, string $permission): bool
    {
        foreach ([$permission, 'api.super', 'admin.super'] as $candidate) {
            if ((method_exists($user, 'authorize') && (bool) $user->authorize($candidate))
                || (method_exists($user, 'get') && (bool) $user->get('access.' . $candidate))) {
                return true;
            }
        }
        return false;
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
