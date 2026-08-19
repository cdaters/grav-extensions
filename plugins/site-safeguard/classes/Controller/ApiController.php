<?php

declare(strict_types=1);

namespace Grav\Plugin\SiteSafeguard\Controller;

use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\SiteSafeguard\Service\SafeguardService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class ApiController extends AbstractApiController
{
    private function service(): SafeguardService
    {
        return new SafeguardService();
    }

    public function status(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireSafeguardPermission($request, 'site-safeguard.manage');
        return ApiResponse::create($this->service()->status());
    }

    public function createPackage(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireSafeguardPermission($request, 'site-safeguard.manage');
        $body = $this->getRequestBody($request);
        $package = $this->service()->createPackage(
            (string) ($body['profile'] ?? 'portable_site'),
            (string) ($body['note'] ?? '')
        );
        return ApiResponse::created(
            $package,
            '/site-safeguard/packages/' . rawurlencode((string) $package['name'])
        );
    }

    public function uploadPackage(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireSafeguardPermission($request, 'site-safeguard.manage');
        $files = $request->getUploadedFiles();
        $package = $files['package'] ?? null;
        if (!$package) {
            throw new ValidationException('No package was uploaded.');
        }
        $imported = $this->service()->importPackage($package);
        return ApiResponse::created(
            $imported,
            '/site-safeguard/packages/' . rawurlencode((string) $imported['name'])
        );
    }

    public function inspectPackage(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireSafeguardPermission($request, 'site-safeguard.manage');
        return ApiResponse::create($this->service()->inspectPackage($this->packageName($request)));
    }

    public function stagePackage(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireSafeguardPermission($request, 'site-safeguard.stage');
        $stage = $this->service()->stagePackage($this->packageName($request));
        return ApiResponse::created(
            $stage,
            '/site-safeguard/stages/' . rawurlencode((string) $stage['id'])
        );
    }

    public function restoreStage(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireSafeguardPermission($request, 'site-safeguard.restore');
        $body = $this->getRequestBody($request);
        $id = rawurldecode((string) $this->getRouteParam($request, 'id'));
        return ApiResponse::create($this->service()->launchRestore(
            $id,
            (string) ($body['confirmation'] ?? '')
        ), 202);
    }

    public function createDownloadToken(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireSafeguardPermission($request, 'site-safeguard.manage');
        return ApiResponse::create($this->service()->createDownloadToken($this->packageName($request)));
    }

    public function deletePackage(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireSafeguardPermission($request, 'site-safeguard.manage');
        return ApiResponse::create($this->service()->deletePackage($this->packageName($request)));
    }

    public function deleteStage(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireSafeguardPermission($request, 'site-safeguard.stage');
        $id = rawurldecode((string) $this->getRouteParam($request, 'id'));
        return ApiResponse::create($this->service()->deleteStage($id));
    }

    private function packageName(ServerRequestInterface $request): string
    {
        return rawurldecode((string) $this->getRouteParam($request, 'name'));
    }

    private function requireSafeguardPermission(ServerRequestInterface $request, string $permission): void
    {
        $user = $this->getUser($request);
        foreach ([$permission, 'api.super', 'admin.super'] as $candidate) {
            if ($user && ((method_exists($user, 'authorize') && (bool) $user->authorize($candidate))
                || (method_exists($user, 'get') && (bool) $user->get('access.' . $candidate)))) {
                return;
            }
        }
        throw new ForbiddenException('Missing required permission: ' . $permission);
    }
}
