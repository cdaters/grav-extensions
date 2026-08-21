<?php

declare(strict_types=1);

namespace Grav\Plugin\SiteWorkshop\Controller;

use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\SiteWorkshop\Service\IconBenchService;
use Grav\Plugin\SiteWorkshop\Service\FrontmatterAnnexService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ApiController extends AbstractApiController
{
    public function status(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'site-workshop.read');
        return ApiResponse::create((new IconBenchService())->status());
    }

    public function icons(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'site-workshop.read');
        $query = $request->getQueryParams();
        return ApiResponse::create((new IconBenchService())->search(
            (string) ($query['q'] ?? ''),
            (string) ($query['pack'] ?? ''),
            (int) ($query['page'] ?? 1),
            (int) ($query['per_page'] ?? 48)
        ));
    }

    public function refreshIcons(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'site-workshop.manage');
        return ApiResponse::create((new IconBenchService())->refresh());
    }

    public function annexes(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'site-workshop.read');
        $service = new FrontmatterAnnexService();
        return ApiResponse::create(['items' => $service->list(), 'status' => $service->status()]);
    }

    public function saveAnnex(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'site-workshop.manage');
        return ApiResponse::create((new FrontmatterAnnexService())->save($this->getRequestBody($request)));
    }

    public function deleteAnnex(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'site-workshop.manage');
        $slug = rawurldecode((string) $this->getRouteParam($request, 'slug'));
        return ApiResponse::create((new FrontmatterAnnexService())->delete($slug));
    }

    protected function requirePermission(ServerRequestInterface $request, string $permission): void
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
