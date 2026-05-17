<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Grav\Common\Plugin;
use RocketTheme\Toolbox\Event\Event;

/**
 * Grav Commander
 *
 * Admin2-first file manager and backup playground for Grav 2.
 */
class GravCommanderPlugin extends Plugin
{
    public const SLUG = 'grav-commander';

    public static function getSubscribedEvents(): array
    {
        return [
            'onApiRegisterRoutes' => ['onApiRegisterRoutes', 0],
            'onApiSidebarItems' => ['onApiSidebarItems', 0],
            'onApiPluginPageInfo' => ['onApiPluginPageInfo', 0],
        ];
    }

    public function autoload()
    {
        spl_autoload_register(static function (string $class): void {
            $prefix = 'Grav\\Plugin\\GravCommander\\';
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

    public function onApiRegisterRoutes(Event $event): void
    {
        require_once __DIR__ . '/classes/Service/FileService.php';
        require_once __DIR__ . '/classes/Controller/ApiController.php';

        $routes = $event['routes'];
        $controller = \Grav\Plugin\GravCommander\Controller\ApiController::class;

        $routes->group('/grav-commander', static function ($group) use ($controller): void {
            $group->get('/status', [$controller, 'status']);
            $group->get('/roots', [$controller, 'roots']);
            $group->get('/list', [$controller, 'list']);
            $group->get('/read', [$controller, 'read']);
            $group->get('/download', [$controller, 'download']);

            $group->patch('/write', [$controller, 'write']);
            $group->post('/mkdir', [$controller, 'mkdir']);
            $group->post('/upload', [$controller, 'upload']);
            $group->post('/archive/zip', [$controller, 'archiveZip']);
            $group->post('/archive/extract', [$controller, 'extractArchive']);
            $group->post('/rename', [$controller, 'rename']);
            $group->post('/copy', [$controller, 'copy']);
            $group->post('/move', [$controller, 'move']);
            $group->delete('/delete', [$controller, 'delete']);

            $group->post('/backup/file', [$controller, 'backupFile']);
            $group->post('/backup/site', [$controller, 'backupSite']);
            $group->get('/backups', [$controller, 'backups']);
            $group->get('/backup/profiles', [$controller, 'profiles']);
            $group->post('/backup/profiles', [$controller, 'saveProfiles']);
            $group->post('/profiles/save', [$controller, 'saveProfiles']);
            $group->post('/config/profiles', [$controller, 'saveProfiles']);
            $group->get('/backup/schedules', [$controller, 'schedules']);
            $group->post('/backup/schedules', [$controller, 'saveSchedules']);
            $group->post('/schedules/save', [$controller, 'saveSchedules']);
            $group->post('/config/schedules', [$controller, 'saveSchedules']);
            $group->post('/backup/schedules/{key}/run', [$controller, 'runSchedule']);
            $group->delete('/backup/schedules/{key}', [$controller, 'deleteSchedule']);
            $group->post('/backup/download-token', [$controller, 'createBackupDownloadToken']);
            $group->get('/backup/direct-download', [$controller, 'directDownloadBackup']);
            $group->get('/backup/download', [$controller, 'downloadBackupQuery']);
            $group->get('/backups/{name}/download', [$controller, 'downloadBackup']);
            $group->post('/restore', [$controller, 'restore']);
            $group->delete('/backups/{name}', [$controller, 'deleteBackup']);
        });
    }

    public function onApiSidebarItems(Event $event): void
    {
        $config = $this->grav['config'];
        if (!$config->get('plugins.grav-commander.admin.show_sidebar', true)) {
            return;
        }

        $user = $event['user'] ?? null;
        if ($user && !$this->userCanAccessCommander($user, 'grav-commander.browse')) {
            return;
        }

        $items = $event['items'] ?? [];
        $items[] = [
            'id' => self::SLUG,
            'plugin' => self::SLUG,
            'label' => 'Grav Commander',
            'icon' => 'fa-folder-tree',
            'route' => '/plugin/' . self::SLUG,
            'priority' => 8,
        ];
        $event['items'] = $items;
    }

    private function userCanAccessCommander(object $user, string $permission): bool
    {
        if (method_exists($user, 'get') && (bool) $user->get('access.api.super')) {
            return true;
        }

        if (method_exists($user, 'authorize')) {
            return (bool) ($user->authorize('api.super') || $user->authorize($permission));
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
            'title' => 'Grav Commander',
            'icon' => 'fa-folder-tree',
            'page_type' => 'component',
        ];
    }
}
