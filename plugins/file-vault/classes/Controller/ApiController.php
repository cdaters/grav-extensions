<?php

declare(strict_types=1);

namespace Grav\Plugin\FileVault\Controller;

use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\FileVault\Service\FileVaultService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class ApiController extends AbstractApiController
{
    private function service(): FileVaultService
    {
        return new FileVaultService();
    }

    private function requireManagePermission(ServerRequestInterface $request): void
    {
        $user = $this->getUser($request);
        foreach (['file-vault.manage', 'api.super', 'admin.super'] as $permission) {
            if (($user && method_exists($user, 'authorize') && $user->authorize($permission))
                || ($user && method_exists($user, 'get') && $user->get('access.' . $permission))) {
                return;
            }
        }
        throw new ForbiddenException('Missing required permission: file-vault.manage');
    }

    public function status(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireManagePermission($request);
        return ApiResponse::create($this->service()->adminStatus());
    }

    public function upload(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireManagePermission($request);
        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file) {
            throw new ValidationException('No file was uploaded.');
        }
        $params = $request->getQueryParams();
        $listed = !array_key_exists('listed', $params)
            || filter_var($params['listed'], FILTER_VALIDATE_BOOL);
        return ApiResponse::create($this->service()->upload($file, (string) ($params['category'] ?? ''), $listed));
    }

    public function createUrlItem(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireManagePermission($request);
        return ApiResponse::create($this->service()->createUrlItem($this->getRequestBody($request)));
    }

    public function saveItem(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireManagePermission($request);
        $id = (string) $this->getRouteParam($request, 'id');
        $body = $this->getRequestBody($request);
        return ApiResponse::create($this->service()->saveItem($id, $body));
    }

    public function deleteItem(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireManagePermission($request);
        $id = (string) $this->getRouteParam($request, 'id');
        $params = $request->getQueryParams();
        $deleteFile = filter_var($params['delete_file'] ?? false, FILTER_VALIDATE_BOOL);
        return ApiResponse::create($this->service()->deleteItem($id, $deleteFile));
    }

    public function resetCount(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireManagePermission($request);
        $id = (string) $this->getRouteParam($request, 'id');
        return ApiResponse::create($this->service()->resetCount($id));
    }

    public function createCategory(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireManagePermission($request);
        return ApiResponse::create($this->service()->createCategory($this->getRequestBody($request)));
    }

    public function saveCategory(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireManagePermission($request);
        $id = (string) $this->getRouteParam($request, 'id');
        return ApiResponse::create($this->service()->saveCategory($id, $this->getRequestBody($request)));
    }

    public function deleteCategory(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireManagePermission($request);
        $id = (string) $this->getRouteParam($request, 'id');
        $params = $request->getQueryParams();
        return ApiResponse::create($this->service()->deleteCategory($id, (string) ($params['move_to'] ?? '')));
    }

    public function savePublicSettings(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireManagePermission($request);
        return ApiResponse::create($this->service()->savePublicSettings($this->getRequestBody($request)));
    }

    public function saveVaultSettings(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireManagePermission($request);
        return ApiResponse::create($this->service()->saveVaultSettings($this->getRequestBody($request)));
    }

    public function activity(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireManagePermission($request);
        $params = $request->getQueryParams();
        return ApiResponse::create($this->service()->activity((int) ($params['limit'] ?? 100)));
    }

    public function purgeActivity(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireManagePermission($request);
        return ApiResponse::create($this->service()->purgeActivity());
    }
}
