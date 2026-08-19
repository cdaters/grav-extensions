<?php

declare(strict_types=1);

namespace Grav\Plugin\ImageFoundry\Controller;

use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\ImageFoundry\Service\ImageFoundryService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ApiController extends AbstractApiController
{
    public function status(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireFoundryPermission($request);
        return ApiResponse::create((new ImageFoundryService())->status());
    }

    public function scan(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireFoundryPermission($request);
        return ApiResponse::create((new ImageFoundryService())->scan());
    }

    public function build(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireFoundryPermission($request);
        $body = $this->getRequestBody($request);
        return ApiResponse::create((new ImageFoundryService())->build(
            isset($body['source']) ? (string) $body['source'] : null,
            (bool) ($body['all'] ?? false),
            isset($body['limit']) ? (int) $body['limit'] : null
        ));
    }

    public function purge(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireFoundryPermission($request);
        return ApiResponse::create((new ImageFoundryService())->purge());
    }

    private function requireFoundryPermission(ServerRequestInterface $request): void
    {
        $user = $this->getUser($request);
        foreach (['image-foundry.manage', 'api.super', 'admin.super'] as $candidate) {
            if ($user && ((method_exists($user, 'authorize') && (bool) $user->authorize($candidate))
                || (method_exists($user, 'get') && (bool) $user->get('access.' . $candidate)))) {
                return;
            }
        }
        throw new ForbiddenException('Missing required permission: image-foundry.manage');
    }
}
