<?php

namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Plugin\PrismGallery\Service\ProtectedMediaService;
use Twig\TwigFunction;

/**
 * Prism Gallery
 *
 * Framework-free, accessible media galleries for Grav content and themes.
 */
class PrismGalleryPlugin extends Plugin
{
    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
            'onTwigExtensions' => ['onTwigExtensions', 0],
            'onTwigSiteVariables' => ['onTwigSiteVariables', 0],
            'onShortcodeHandlers' => ['onShortcodeHandlers', 0],
        ];
    }

    public function autoload(): void
    {
        spl_autoload_register(static function (string $class): void {
            $prefix = 'Grav\\Plugin\\PrismGallery\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }

            $relative = substr($class, strlen($prefix));
            $file = __DIR__ . '/classes/' . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });
    }

    public function onPluginsInitialized(): void
    {
        $uri = $this->grav['uri'] ?? null;
        if (!$uri || !method_exists($uri, 'path')) {
            return;
        }

        $path = '/' . trim((string) $uri->path(), '/');
        if ($path === '/prism-gallery/authorize') {
            $this->enable(['onPageInitialized' => ['onAuthorizeMedia', 10000]]);
        } elseif ($path === '/prism-gallery/media') {
            $this->enable(['onPageInitialized' => ['onServeMedia', 10000]]);
        }
    }

    public function onTwigTemplatePaths(): void
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }

    public function onTwigExtensions(): void
    {
        $this->grav['twig']->twig()->addFunction(new TwigFunction('prism_protect_media', function ($url): string {
            if (!$this->config->get('plugins.prism-gallery.protection.enabled', true)) {
                return '';
            }

            try {
                return (new ProtectedMediaService())->registerUrl((string) $url);
            } catch (\Throwable $e) {
                $this->grav['log']->warning('[Prism Gallery] Unable to protect media URL: ' . $e->getMessage());
                return '';
            }
        }));
    }

    public function onAuthorizeMedia(): void
    {
        try {
            if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET'
                || (string) ($_SERVER['HTTP_X_PRISM_REQUEST'] ?? '') !== 'media') {
                throw new \RuntimeException('Authorization request rejected.');
            }

            $id = (string) ($_GET['id'] ?? '');
            $result = (new ProtectedMediaService())->authorize($id);
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: private, no-store, max-age=0');
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: no-referrer');
            echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            exit;
        } catch (\Throwable $e) {
            http_response_code(403);
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: no-store, max-age=0');
            header('X-Content-Type-Options: nosniff');
            echo '{"error":"Media authorization failed."}';
            exit;
        }
    }

    public function onServeMedia(): void
    {
        try {
            (new ProtectedMediaService())->stream((string) ($_GET['token'] ?? ''));
        } catch (\Throwable $e) {
            http_response_code(403);
            header('Cache-Control: no-store, max-age=0');
            header('X-Content-Type-Options: nosniff');
            echo 'This media link is invalid or expired.';
            exit;
        }
    }

    public function onShortcodeHandlers(): void
    {
        if (isset($this->grav['shortcode'])) {
            $this->grav['shortcode']->registerAllShortcodes(__DIR__ . '/classes/Shortcodes');
        }
    }

    public function onTwigSiteVariables(): void
    {
        $always = $this->config->get('plugins.prism-gallery.load_assets', 'auto') === 'always';
        if (!$always && !$this->pageUsesPrism()) {
            return;
        }

        $this->grav['assets']->addCss('plugin://prism-gallery/assets/css/prism-gallery.css?v=0.2.1', 85);
        $this->grav['assets']->addJs('plugin://prism-gallery/assets/js/prism-gallery.js?v=0.2.1', [
            'group' => 'bottom',
            'priority' => 85,
        ]);
    }

    private function pageUsesPrism(): bool
    {
        $page = $this->grav['page'] ?? null;
        if (!$page) {
            return false;
        }

        if (method_exists($page, 'template') && $page->template() === 'gallery') {
            return true;
        }
        if (method_exists($page, 'get') && $page->get('header.gallery')) {
            return true;
        }
        if (method_exists($page, 'rawMarkdown')) {
            $markdown = (string) $page->rawMarkdown();
            if (str_contains($markdown, '[prism') || str_contains($markdown, '[lightbox')) {
                return true;
            }
        }

        if (method_exists($page, 'collection')) {
            try {
                foreach ($page->collection() as $module) {
                    if ((method_exists($module, 'template') && $module->template() === 'gallery')
                        || (method_exists($module, 'get') && $module->get('header.gallery'))) {
                        return true;
                    }
                }
            } catch (\Throwable $e) {
                $this->grav['log']->debug('[Prism Gallery] Unable to inspect modular collection: ' . $e->getMessage());
            }
        }

        return false;
    }
}
