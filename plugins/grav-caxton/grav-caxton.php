<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Plugin\GravCaxton\Document\SourceDocumentParser;
use Grav\Plugin\GravCaxton\Extension\ExtensionRegistry;
use Grav\Plugin\GravCaxton\Service\CaxtonService;
use RocketTheme\Toolbox\Event\Event;
use Throwable;

final class GravCaxtonPlugin extends Plugin
{
    public const SLUG = 'grav-caxton';
    public const SERVICE_KEY = 'gravCaxton';
    public const EXTENSION_EVENT = 'onCaxtonExtensionRegister';
    public const DEFAULT_MAX_SOURCE_BYTES = 2_097_152;

    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
        ];
    }

    public function autoload(): void
    {
        spl_autoload_register(static function (string $class): void {
            $prefix = 'Grav\\Plugin\\GravCaxton\\';
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

        $registry = new ExtensionRegistry();
        try {
            $this->grav->fireEvent(self::EXTENSION_EVENT, new Event(['registry' => $registry]));
        } catch (Throwable $error) {
            $logger = $this->grav['log'] ?? null;
            if (is_object($logger) && method_exists($logger, 'error')) {
                $logger->error('[Caxton] Extension registration failed: ' . $error::class);
            }
        }

        if (isset($this->grav[self::SERVICE_KEY])) {
            return;
        }

        $configuredLimit = $this->config->get(
            'plugins.' . self::SLUG . '.limits.max_source_bytes',
            self::DEFAULT_MAX_SOURCE_BYTES
        );
        $limit = is_int($configuredLimit) || is_numeric($configuredLimit)
            ? (int) $configuredLimit
            : self::DEFAULT_MAX_SOURCE_BYTES;
        $limit = max(1024, min(16_777_216, $limit));

        $this->grav[self::SERVICE_KEY] = static fn (): CaxtonService => new CaxtonService(
            new SourceDocumentParser($limit),
            $registry
        );
    }
}
