<?php

declare(strict_types=1);

namespace Grav\Plugin\GravCommander\Controller;

use Grav\Framework\Psr7\Response;
use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\GravCommander\Service\FileService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class ApiController extends AbstractApiController
{
    private function service(): FileService
    {
        return new FileService();
    }

    /**
     * Admin2/API permissions are still settling in Grav 2 RC land.
     * This wrapper accepts plugin-specific permissions, api.super, and admin.super
     * so site super admins are not locked out of their own toolbox.
     */
    private function requireCommanderPermission(ServerRequestInterface $request, string $permission): void
    {
        $user = $this->getUser($request);

        if ($this->userHasPermission($user, $permission)
            || $this->userHasPermission($user, 'api.super')
            || $this->userHasPermission($user, 'admin.super')) {
            return;
        }

        throw new ForbiddenException('Missing required permission: ' . $permission);
    }

    private function userHasPermission(?object $user, string $permission): bool
    {
        if (!$user) {
            return false;
        }

        if (method_exists($user, 'authorize') && (bool) $user->authorize($permission)) {
            return true;
        }

        if (method_exists($user, 'get') && (bool) $user->get('access.' . $permission)) {
            return true;
        }

        return false;
    }

    public function status(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireCommanderPermission($request, 'grav-commander.browse');
        return ApiResponse::create($this->service()->status());
    }

    public function roots(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireCommanderPermission($request, 'grav-commander.browse');
        return ApiResponse::create($this->service()->roots());
    }

    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireCommanderPermission($request, 'grav-commander.browse');
        $params = $request->getQueryParams();
        return ApiResponse::create($this->service()->list((string) ($params['root'] ?? 'pages'), (string) ($params['path'] ?? '')));
    }

    public function read(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireCommanderPermission($request, 'grav-commander.browse');
        $params = $request->getQueryParams();
        return ApiResponse::create($this->service()->read((string) ($params['root'] ?? 'pages'), (string) ($params['path'] ?? '')));
    }

    public function download(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireCommanderPermission($request, 'grav-commander.browse');
        $params = $request->getQueryParams();
        $root = (string) ($params['root'] ?? 'pages');
        $path = (string) ($params['path'] ?? '');
        $file = $this->service()->fileForDownload($root, $path);

        return $this->downloadResponse((string) $file['absolute'], (string) $file['name'], (string) $file['mime']);
    }

    public function write(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireCommanderPermission($request, 'grav-commander.write');
        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['root', 'path', 'content']);
        return ApiResponse::create($this->service()->write((string) $body['root'], (string) $body['path'], (string) $body['content']));
    }

    public function mkdir(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireCommanderPermission($request, 'grav-commander.write');
        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['root', 'path', 'name']);
        return ApiResponse::create($this->service()->mkdir((string) $body['root'], (string) $body['path'], (string) $body['name']));
    }

    public function upload(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireCommanderPermission($request, 'grav-commander.write');
        $params = $request->getQueryParams();
        $root = (string) ($params['root'] ?? 'pages');
        $path = (string) ($params['path'] ?? '');
        $uploadedFiles = $request->getUploadedFiles();
        $file = $uploadedFiles['file'] ?? null;
        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            throw new ValidationException('No file uploaded or upload error.');
        }
        return ApiResponse::create($this->service()->upload($root, $path, $file));
    }

    public function archiveZip(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireCommanderPermission($request, 'grav-commander.write');
        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['root', 'path']);
        return ApiResponse::create($this->service()->archiveZip(
            (string) $body['root'],
            (string) $body['path'],
            (string) ($body['name'] ?? '')
        ));
    }

    public function extractArchive(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireCommanderPermission($request, 'grav-commander.write');
        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['root', 'path']);
        return ApiResponse::create($this->service()->extractArchive(
            (string) $body['root'],
            (string) $body['path'],
            (string) ($body['dest_path'] ?? ''),
            (bool) ($body['overwrite'] ?? false)
        ));
    }

    public function rename(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireCommanderPermission($request, 'grav-commander.write');
        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['root', 'path', 'name']);
        return ApiResponse::create($this->service()->rename((string) $body['root'], (string) $body['path'], (string) $body['name']));
    }

    public function copy(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireCommanderPermission($request, 'grav-commander.write');
        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['root', 'path', 'dest_root', 'dest_path']);
        return ApiResponse::create($this->service()->copy((string) $body['root'], (string) $body['path'], (string) $body['dest_root'], (string) $body['dest_path']));
    }

    public function move(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireCommanderPermission($request, 'grav-commander.write');
        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['root', 'path', 'dest_root', 'dest_path']);
        return ApiResponse::create($this->service()->move((string) $body['root'], (string) $body['path'], (string) $body['dest_root'], (string) $body['dest_path']));
    }

    public function delete(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireCommanderPermission($request, 'grav-commander.write');
        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['root', 'path']);
        return ApiResponse::create($this->service()->delete((string) $body['root'], (string) $body['path']));
    }

    public function backupFile(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireCommanderPermission($request, 'grav-commander.backup');
        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['root', 'path']);
        return ApiResponse::create($this->service()->backupPath((string) $body['root'], (string) $body['path'], (string) ($body['reason'] ?? 'manual')));
    }

    public function backupSite(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireCommanderPermission($request, 'grav-commander.backup');
        $body = $this->getRequestBody($request);

        // Compatibility shim: Admin2 / API route caches in early Grav 2 RC builds can be stubborn
        // about newly-added plugin routes. The already-established backup/site endpoint can safely
        // dispatch config operations when the component sends an explicit action.
        $action = (string) ($body['__action'] ?? $body['action'] ?? '');
        if ($action === 'save_profiles') {
            $profiles = $body['profiles'] ?? null;
            if (!is_array($profiles)) {
                throw new ValidationException('profiles must be an object/map.');
            }
            return ApiResponse::create($this->service()->saveProfiles($profiles));
        }
        if ($action === 'save_schedules') {
            $schedules = $body['schedules'] ?? null;
            if (!is_array($schedules)) {
                throw new ValidationException('schedules must be an object/map.');
            }
            return ApiResponse::create($this->service()->saveSchedules($schedules));
        }
        if ($action === 'set_backup_path') {
            return ApiResponse::create($this->service()->saveBackupPath((string) ($body['path'] ?? '')));
        }

        return ApiResponse::create($this->service()->backupSite((string) ($body['reason'] ?? 'manual-site'), (string) ($body['profile'] ?? 'full_site'), (string) ($body['note'] ?? '')));
    }

    public function backups(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireCommanderPermission($request, 'grav-commander.backup');
        return ApiResponse::create($this->service()->backups());
    }

    public function profiles(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireCommanderPermission($request, 'grav-commander.backup');
        return ApiResponse::create($this->service()->profiles());
    }

    public function saveProfiles(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireCommanderPermission($request, 'grav-commander.backup');
        $body = $this->getRequestBody($request);
        $profiles = $body['profiles'] ?? null;
        if (!is_array($profiles)) {
            throw new ValidationException('profiles must be an object/map.');
        }
        return ApiResponse::create($this->service()->saveProfiles($profiles));
    }

    public function schedules(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireCommanderPermission($request, 'grav-commander.backup');
        return ApiResponse::create($this->service()->schedules());
    }

    public function saveSchedules(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireCommanderPermission($request, 'grav-commander.backup');
        $body = $this->getRequestBody($request);
        $schedules = $body['schedules'] ?? null;
        if (!is_array($schedules)) {
            throw new ValidationException('schedules must be an object/map.');
        }
        return ApiResponse::create($this->service()->saveSchedules($schedules));
    }

    public function runSchedule(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireCommanderPermission($request, 'grav-commander.backup');
        $key = (string) $this->getRouteParam($request, 'key');
        return ApiResponse::create($this->service()->runSchedule($key));
    }

    public function deleteSchedule(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireCommanderPermission($request, 'grav-commander.backup');
        $key = (string) $this->getRouteParam($request, 'key');
        return ApiResponse::create($this->service()->deleteSchedule($key));
    }


    public function createBackupDownloadToken(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireCommanderPermission($request, 'grav-commander.backup');
        $body = $this->getRequestBody($request);
        $name = (string) ($body['name'] ?? '');
        $data = $this->service()->createBackupDownloadToken($name);

        return ApiResponse::create($data);
    }

    public function directDownloadBackup(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $token = (string) ($params['token'] ?? '');
        $file = $this->service()->consumeBackupDownloadToken($token);

        return $this->downloadResponse((string) $file['absolute'], (string) $file['name'], 'application/zip');
    }

    public function downloadBackupQuery(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireCommanderPermission($request, 'grav-commander.backup');
        $params = $request->getQueryParams();
        $name = (string) ($params['name'] ?? '');
        $path = $this->service()->backupFilePath($name);

        return $this->downloadResponse($path, basename($path), 'application/zip');
    }

    public function downloadBackup(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireCommanderPermission($request, 'grav-commander.backup');
        $name = (string) $this->getRouteParam($request, 'name');
        $path = $this->service()->backupFilePath($name);

        return $this->downloadResponse($path, basename($path), 'application/zip');
    }

    private function downloadResponse(string $path, string $name, string $mime): ResponseInterface
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ValidationException('Unable to open file for download.');
        }

        // Direct output is intentional here. Some Admin2/API builds try to stringify
        // PSR-7 resource bodies, which can trigger a 500 or memory spike on large ZIPs.
        // We authenticate first, clear buffers, stream in chunks, then stop execution.
        $this->streamFileAndExit($path, $name, $mime);

        return new Response(204);
    }

    private function streamFileAndExit(string $path, string $name, string $mime): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        if (headers_sent()) {
            throw new ValidationException('Unable to send download headers; output has already started.');
        }

        $asciiName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: 'download.zip';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($path));
        header("Content-Disposition: attachment; filename=\"" . $asciiName . "\"; filename*=UTF-8''" . rawurlencode($name));
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new ValidationException('Unable to open file for download.');
        }

        while (!feof($handle)) {
            echo fread($handle, 1024 * 1024);
            flush();
        }
        fclose($handle);
        exit;
    }

    public function restore(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireCommanderPermission($request, 'grav-commander.restore');
        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['name', 'confirm']);
        return ApiResponse::create($this->service()->restore((string) $body['name'], (bool) $body['confirm']));
    }

    public function deleteBackup(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireCommanderPermission($request, 'grav-commander.backup');
        $name = (string) $this->getRouteParam($request, 'name');
        return ApiResponse::create($this->service()->deleteBackup($name));
    }
}
