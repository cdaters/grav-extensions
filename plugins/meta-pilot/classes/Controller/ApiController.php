<?php

declare(strict_types=1);

namespace Grav\Plugin\MetaPilot\Controller;

use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\MetaPilot\Service\MetaPilotService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ApiController extends AbstractApiController
{
    public function status(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request);
        return ApiResponse::create((new MetaPilotService())->status());
    }

    public function report(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request);
        return ApiResponse::create((new MetaPilotService())->report());
    }

    protected function requirePermission(ServerRequestInterface $request): void
    {
        $user = $this->getUser($request);
        foreach (['meta-pilot.read', 'api.super', 'admin.super'] as $candidate) {
            if ($user && ((method_exists($user, 'authorize') && (bool) $user->authorize($candidate))
                || (method_exists($user, 'get') && (bool) $user->get('access.' . $candidate)))) {
                return;
            }
        }
        throw new ForbiddenException('Missing required permission: meta-pilot.read');
    }
}
