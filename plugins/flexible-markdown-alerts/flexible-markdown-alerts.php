<?php

namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Markdown\Extension\MarkdownExtensionRegistry;
use Grav\Common\Plugin;
use Grav\Plugin\FlexibleMarkdownAlerts\AlertsExtension;
use RocketTheme\Toolbox\Event\Event;

/**
 * Flexible Markdown Alerts plugin.
 */
class FlexibleMarkdownAlertsPlugin extends Plugin
{
    public static function getSubscribedEvents(): array
    {
        return [
            'onMarkdownInitialized' => ['onMarkdownInitialized', 0],
            'onTwigSiteVariables' => ['onTwigSiteVariables', 0],
            'registerEditorProPlugin' => ['registerEditorProPlugin', 0],
        ];
    }

    public function autoload(): ClassLoader
    {
        return require __DIR__ . '/vendor/autoload.php';
    }

    public function onMarkdownInitialized(Event $event): void
    {
        $registry = new MarkdownExtensionRegistry($event['markdown'], $event['page'] ?? null);
        $registry->add(new AlertsExtension($this->config->get('plugins.flexible-markdown-alerts')));
    }

    public function onTwigSiteVariables(): void
    {
        if (!$this->config->get('plugins.flexible-markdown-alerts.include_css')) {
            return;
        }

        $this->grav['assets']->add('plugin://flexible-markdown-alerts/assets/flexible-markdown-alerts.css');
    }

    public function registerEditorProPlugin(Event $event): void
    {
        $plugins = $event['plugins'];
        $plugins['js'][] = 'plugin://flexible-markdown-alerts/editor-pro/flexible-markdown-alerts-integration.js';
        $event['plugins'] = $plugins;
    }

}
