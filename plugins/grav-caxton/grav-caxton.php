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
    private const DEFAULT_TOOLBAR = [
        'undo', 'redo', 'separator', 'heading', 'separator', 'bold', 'italic', 'strikethrough',
        'inline_code', 'remove_format', 'separator', 'link', 'blockquote',
        'bullet_list', 'ordered_list', 'horizontal_rule', 'code_block', 'media',
        'separator', 'jarvis', 'source',
    ];
    private const TOOLBAR_ALIASES = [
        '|' => 'separator',
        'removeformat' => 'remove_format',
        'code' => 'inline_code',
        'strike' => 'strikethrough',
        'inlineCode' => 'inline_code',
        'bulletList' => 'bullet_list',
        'orderedList' => 'ordered_list',
        'codeBlock' => 'code_block',
        'horizontalRule' => 'horizontal_rule',
    ];

    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
            'onApiBlueprintResolved' => ['onApiBlueprintResolved', 0],
            'onApiRegisterRoutes' => ['onApiRegisterRoutes', 0],
        ];
    }

    public function onApiRegisterRoutes(Event $event): void
    {
        if (!$this->config->get('plugins.' . self::SLUG . '.enabled', true)
            || !$this->config->get('plugins.' . self::SLUG . '.jarvis.enabled', true)) {
            return;
        }
        require_once __DIR__ . '/classes/Jarvis/CaxtonJarvisException.php';
        require_once __DIR__ . '/classes/Jarvis/CaxtonProposalStore.php';
        require_once __DIR__ . '/classes/Jarvis/CaxtonJarvisService.php';
        require_once __DIR__ . '/classes/Controller/ApiController.php';
        $routes = $event['routes'];
        $controller = \Grav\Plugin\GravCaxton\Controller\ApiController::class;
        $routes->group('/grav-caxton', static function ($group) use ($controller): void {
            $group->get('/jarvis/status', [$controller, 'jarvisStatus']);
            $group->get('/jarvis/providers/{id}/models', [$controller, 'jarvisModels']);
            $group->post('/jarvis/providers/{id}/validate', [$controller, 'jarvisValidate']);
            $group->post('/jarvis/proposals', [$controller, 'jarvisPropose']);
            $group->post('/jarvis/proposals/{id}/accept', [$controller, 'jarvisAccept']);
            $group->post('/jarvis/proposals/{id}/discard', [$controller, 'jarvisDiscard']);
        });
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

    public function onApiBlueprintResolved(Event $event): void
    {
        if (!$this->config->get('plugins.' . self::SLUG . '.enabled', true)
            || !$this->config->get('plugins.' . self::SLUG . '.admin.replace_markdown_fields', true)
            || ($event['context'] ?? null) !== 'page') {
            return;
        }

        $user = $event['user'] ?? null;
        if (!is_object($user) || !$this->userCan($user, 'grav-caxton.use')) {
            return;
        }

        $fields = (array) ($event['fields'] ?? []);
        $event['fields'] = $this->replaceMarkdownFields(
            $fields,
            $this->userCan($user, 'grav-caxton.source'),
            $this->configuredToolbar(),
            (bool) $this->config->get('plugins.' . self::SLUG . '.jarvis.enabled', true)
                && $this->userCan($user, 'grav-jarvis.use')
        );
    }

    /**
     * @param array<array-key, mixed> $fields
     * @return array<array-key, mixed>
     */
    private function replaceMarkdownFields(array $fields, bool $allowSource, array $toolbar, bool $allowJarvis): array
    {
        foreach ($fields as $key => $field) {
            if (!is_array($field)) {
                continue;
            }

            if (($field['type'] ?? null) === 'markdown') {
                $field['type'] = 'caxton';
                $caxton = is_array($field['caxton'] ?? null) ? $field['caxton'] : [];
                $caxton['allow_source'] = $allowSource;
                $caxton['allow_jarvis'] = $allowJarvis;
                $caxton['toolbar'] = $this->normalizeToolbar($caxton['toolbar'] ?? $toolbar, $allowSource);
                $field['caxton'] = $caxton;
            }

            if (isset($field['fields']) && is_array($field['fields'])) {
                $field['fields'] = $this->replaceMarkdownFields($field['fields'], $allowSource, $toolbar, $allowJarvis);
            }

            $fields[$key] = $field;
        }

        return $fields;
    }

    /** @return list<string> */
    private function configuredToolbar(): array
    {
        return $this->normalizeToolbar(
            $this->config->get('plugins.' . self::SLUG . '.admin.toolbar', self::DEFAULT_TOOLBAR),
            true
        );
    }

    /** @return list<string> */
    private function normalizeToolbar(mixed $configured, bool $allowSource): array
    {
        if (is_string($configured)) {
            $configured = explode(',', $configured);
        }
        if (!is_array($configured)) {
            $configured = self::DEFAULT_TOOLBAR;
        }

        $allowed = array_fill_keys(self::DEFAULT_TOOLBAR, true);
        $result = [];
        foreach (array_slice($configured, 0, 48) as $entry) {
            if (!is_string($entry)) {
                continue;
            }
            $raw = trim($entry);
            $item = self::TOOLBAR_ALIASES[$raw] ?? $raw;
            if (!isset($allowed[$item]) || (!$allowSource && $item === 'source')) {
                continue;
            }
            if ($item === 'separator' && ($result === [] || end($result) === 'separator')) {
                continue;
            }
            $result[] = $item;
        }
        while ($result !== [] && end($result) === 'separator') {
            array_pop($result);
        }

        if ($result === []) {
            return array_values(array_filter(
                self::DEFAULT_TOOLBAR,
                static fn (string $item): bool => $allowSource || $item !== 'source'
            ));
        }
        return $result;
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
}
