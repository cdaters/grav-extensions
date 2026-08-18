<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Plugin\FileVault\Service\FileVaultService;
use RocketTheme\Toolbox\Event\Event;

/**
 * File Vault
 *
 * Protected download library and Admin2 catalog manager for Grav 2.
 */
class FileVaultPlugin extends Plugin
{
    public const SLUG = 'file-vault';

    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
            'onTwigSiteVariables' => ['onTwigSiteVariables', 0],
            'onApiRegisterRoutes' => ['onApiRegisterRoutes', 0],
            'onApiSidebarItems' => ['onApiSidebarItems', 0],
            'onApiPluginPageInfo' => ['onApiPluginPageInfo', 0],
            'onShortcodeHandlers' => ['onShortcodeHandlers', 0],
        ];
    }

    public function autoload(): void
    {
        spl_autoload_register(static function (string $class): void {
            $prefix = 'Grav\\Plugin\\FileVault\\';
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
        if ($path === '/file-vault/download') {
            $this->enable([
                'onPageInitialized' => ['onDownloadRequest', 10000],
            ]);
        }
    }

    public function onTwigTemplatePaths(): void
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }

    public function onTwigSiteVariables(): void
    {
        $page = $this->grav['page'] ?? null;
        if (!$page || !method_exists($page, 'template')) {
            return;
        }

        $enabled = method_exists($page, 'get')
            ? (bool) $page->get('header.file_vault.enabled', false)
            : false;
        if ($page->template() !== 'file-vault' && !$enabled) {
            return;
        }

        $this->grav['assets']->addCss('plugin://file-vault/assets/css/file-vault.css', 90);
        $this->grav['assets']->addJs('plugin://file-vault/assets/js/file-vault.js', ['group' => 'bottom', 'priority' => 90]);

        try {
            $catalog = (new FileVaultService())->publicCatalog();
        } catch (\Throwable $e) {
            $catalog = [
                'items' => [],
                'categories' => [],
                'total_downloads' => 0,
                'error' => 'The download catalog is temporarily unavailable.',
            ];
            $this->grav['log']->error('[File Vault] ' . $e->getMessage());
        }

        $this->grav['twig']->twig_vars['file_vault'] = $catalog;
    }

    public function onDownloadRequest(): void
    {
        $token = (string) ($_POST['token'] ?? $_GET['token'] ?? '');
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        try {
            $service = new FileVaultService();
            $download = $service->resolveDownloadToken($token);
            $passwordHash = (string) ($download['password_hash'] ?? '');
            if ($passwordHash !== '') {
                if ($method !== 'POST') {
                    $this->renderPasswordPromptAndExit($token, (string) $download['download_name']);
                }
                $password = (string) ($_POST['password'] ?? '');
                if (!password_verify($password, $passwordHash)) {
                    $this->renderPasswordPromptAndExit($token, (string) $download['download_name'], 'That password was not accepted.');
                }
            }

            if ($method !== 'HEAD' && empty($_SERVER['HTTP_RANGE'])) {
                $service->claimDownload((string) $download['id']);
                try {
                    $service->recordDownloadActivity((string) $download['id'], (string) ($download['source_type'] ?? 'file'));
                } catch (\Throwable $activityError) {
                    $this->grav['log']->error('[File Vault activity] ' . $activityError->getMessage());
                }
            }
            if ((string) ($download['source_type'] ?? 'file') === 'url') {
                http_response_code(302);
                header('Location: ' . (string) $download['external_url']);
                header('Cache-Control: private, no-store, max-age=0');
                header('X-Content-Type-Options: nosniff');
                header('Referrer-Policy: no-referrer');
                exit;
            }
            $this->streamFileAndExit($download);
        } catch (\Throwable $e) {
            header('HTTP/1.1 403 Forbidden');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('X-Content-Type-Options: nosniff');
            echo 'This download link is invalid, expired, or unavailable.';
            exit;
        }
    }

    private function renderPasswordPromptAndExit(string $token, string $name, string $error = ''): void
    {
        http_response_code($error === '' ? 200 : 401);
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        $token = htmlspecialchars($token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $name = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $errorMarkup = $error !== '' ? '<p class="error">' . htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>' : '';
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Protected download</title><style>'
            . ':root{color-scheme:light dark;font-family:system-ui,-apple-system,sans-serif}body{display:grid;min-height:100vh;margin:0;place-items:center;background:#0b1017;color:#edf3fa}.card{width:min(28rem,calc(100% - 2rem));padding:2rem;border:1px solid #ffffff24;border-radius:16px;background:#121a25;box-shadow:0 24px 70px #0008}.eyebrow{color:#72a8ff;font-size:.72rem;font-weight:800;letter-spacing:.15em}.card h1{margin:.35rem 0 .5rem;font-size:1.8rem}.card p{color:#a9b5c4}.card label{display:block;margin:1.2rem 0 .45rem;font-size:.78rem;font-weight:750;text-transform:uppercase;letter-spacing:.06em}.card input{box-sizing:border-box;width:100%;padding:.8rem;border:1px solid #ffffff2b;border-radius:8px;background:#0b1119;color:inherit;font:inherit}.card button{width:100%;margin-top:1rem;padding:.8rem;border:0;border-radius:8px;background:#4389f8;color:white;font:inherit;font-weight:800;cursor:pointer}.error{padding:.7rem;border:1px solid #ff6c6c66;border-radius:8px;color:#ffaaaa!important;background:#ff4b4b15}@media(prefers-color-scheme:light){body{background:#f4f7fb;color:#17202b}.card{border-color:#17202b22;background:white;box-shadow:0 24px 70px #17304b20}.card p{color:#647184}.card input{border-color:#17202b2b;background:white}}'
            . '</style></head><body><main class="card"><span class="eyebrow">FILE VAULT</span><h1>Protected download</h1><p>Enter the password for <strong>' . $name . '</strong>.</p>' . $errorMarkup
            . '<form method="post"><input type="hidden" name="token" value="' . $token . '"><label for="password">Download password</label><input id="password" name="password" type="password" autocomplete="current-password" required autofocus><button type="submit">Unlock download</button></form></main></body></html>';
        exit;
    }

    public function onShortcodeHandlers(): void
    {
        if (isset($this->grav['shortcode'])) {
            $this->grav['shortcode']->registerAllShortcodes(__DIR__ . '/classes/Shortcodes');
        }
    }

    /** @param array<string,mixed> $download */
    private function streamFileAndExit(array $download): void
    {
        $path = (string) $download['absolute'];
        $name = (string) $download['download_name'];
        $mime = (string) $download['mime'];
        $size = (int) filesize($path);
        $start = 0;
        $end = max(0, $size - 1);
        $status = 200;

        $range = (string) ($_SERVER['HTTP_RANGE'] ?? '');
        if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $matches)) {
            if ($matches[1] === '' && $matches[2] !== '') {
                $suffix = min($size, (int) $matches[2]);
                $start = $size - $suffix;
            } else {
                $start = (int) ($matches[1] ?: 0);
                $end = $matches[2] !== '' ? min($end, (int) $matches[2]) : $end;
            }

            if ($start > $end || $start >= $size) {
                header('HTTP/1.1 416 Range Not Satisfiable');
                header('Content-Range: bytes */' . $size);
                exit;
            }
            $status = 206;
        }

        $length = $end - $start + 1;
        $asciiName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: 'download.bin';

        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        http_response_code($status);
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . $length);
        header('Accept-Ranges: bytes');
        if ($status === 206) {
            header(sprintf('Content-Range: bytes %d-%d/%d', $start, $end, $size));
        }
        header('Content-Disposition: attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');

        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'HEAD') {
            exit;
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open download.');
        }

        fseek($handle, $start);
        $remaining = $length;
        while ($remaining > 0 && !feof($handle)) {
            $chunk = fread($handle, min(1024 * 1024, $remaining));
            if ($chunk === false) {
                break;
            }
            echo $chunk;
            $remaining -= strlen($chunk);
            flush();
        }
        fclose($handle);
        exit;
    }

    public function onApiRegisterRoutes(Event $event): void
    {
        require_once __DIR__ . '/classes/Service/FileVaultService.php';
        require_once __DIR__ . '/classes/Controller/ApiController.php';

        $routes = $event['routes'];
        $controller = \Grav\Plugin\FileVault\Controller\ApiController::class;

        $routes->group('/file-vault', static function ($group) use ($controller): void {
            $group->get('/status', [$controller, 'status']);
            $group->post('/upload', [$controller, 'upload']);
            $group->post('/items/url', [$controller, 'createUrlItem']);
            $group->patch('/items/{id}', [$controller, 'saveItem']);
            $group->delete('/items/{id}', [$controller, 'deleteItem']);
            $group->post('/items/{id}/reset-count', [$controller, 'resetCount']);
            $group->post('/categories', [$controller, 'createCategory']);
            $group->patch('/categories/{id}', [$controller, 'saveCategory']);
            $group->delete('/categories/{id}', [$controller, 'deleteCategory']);
            $group->patch('/settings/public', [$controller, 'savePublicSettings']);
            $group->patch('/settings/vault', [$controller, 'saveVaultSettings']);
            $group->get('/activity', [$controller, 'activity']);
            $group->delete('/activity', [$controller, 'purgeActivity']);
        });
    }

    public function onApiSidebarItems(Event $event): void
    {
        if (!$this->grav['config']->get('plugins.file-vault.admin.show_sidebar', true)) {
            return;
        }

        $user = $event['user'] ?? null;
        if ($user && !$this->userCanManage($user)) {
            return;
        }

        $items = $event['items'] ?? [];
        $items[] = [
            'id' => self::SLUG,
            'plugin' => self::SLUG,
            'label' => 'File Vault',
            'icon' => 'fa-box-archive',
            'route' => '/plugin/' . self::SLUG,
            'priority' => 9,
        ];
        $event['items'] = $items;
    }

    private function userCanManage(object $user): bool
    {
        foreach (['file-vault.manage', 'api.super', 'admin.super'] as $permission) {
            if ((method_exists($user, 'authorize') && (bool) $user->authorize($permission))
                || (method_exists($user, 'get') && (bool) $user->get('access.' . $permission))) {
                return true;
            }
        }
        return false;
    }

    public function onApiPluginPageInfo(Event $event): void
    {
        if (($event['plugin'] ?? null) !== self::SLUG) {
            return;
        }

        $event['definition'] = [
            'id' => self::SLUG,
            'plugin' => self::SLUG,
            'title' => 'File Vault',
            'icon' => 'fa-box-archive',
            'page_type' => 'component',
        ];
    }
}
