<?php

declare(strict_types=1);

namespace Grav\Plugin\LanternSearch\Controller;

use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\LanternSearch\Service\SearchIndexService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ApiController extends AbstractApiController
{
    public function status(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'lantern-search.read');
        return ApiResponse::create((new SearchIndexService())->status());
    }

    public function query(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'lantern-search.read');
        $query = $request->getQueryParams();
        return ApiResponse::create((new SearchIndexService())->search((string) ($query['q'] ?? ''), [
            'category' => (string) ($query['category'] ?? ''),
            'tag' => (string) ($query['tag'] ?? ''),
            'language' => (string) ($query['language'] ?? ''),
            'template' => (string) ($query['template'] ?? ''),
        ], isset($query['limit']) ? (int) $query['limit'] : null));
    }

    public function rebuild(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'lantern-search.rebuild');
        return ApiResponse::create((new SearchIndexService())->build(true));
    }

    protected function requirePermission(ServerRequestInterface $request, string $permission): void
    {
        $user = $this->getUser($request);
        foreach ([$permission, 'api.super', 'admin.super'] as $candidate) {
            if ($user && ((method_exists($user, 'authorize') && (bool) $user->authorize($candidate)) || (method_exists($user, 'get') && (bool) $user->get('access.' . $candidate)))) return;
        }
        throw new ForbiddenException('Missing required permission: ' . $permission);
    }
}
