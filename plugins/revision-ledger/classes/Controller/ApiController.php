<?php

declare(strict_types=1);

namespace Grav\Plugin\RevisionLedger\Controller;

use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\RevisionLedger\Service\RevisionLedgerService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ApiController extends AbstractApiController
{
    public function status(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireLedgerPermission($request, 'revision-ledger.read');
        return ApiResponse::create((new RevisionLedgerService())->status());
    }

    public function pages(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireLedgerPermission($request, 'revision-ledger.read');
        return ApiResponse::create(['pages' => (new RevisionLedgerService())->pageCatalog()]);
    }

    public function revisions(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireLedgerPermission($request, 'revision-ledger.read');
        $query = $request->getQueryParams();
        return ApiResponse::create(['revisions' => (new RevisionLedgerService())->revisions(isset($query['route']) ? (string) $query['route'] : null)]);
    }

    public function revision(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireLedgerPermission($request, 'revision-ledger.read');
        return ApiResponse::create((new RevisionLedgerService())->revision((string) $this->getRouteParam($request, 'id')));
    }

    public function compare(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireLedgerPermission($request, 'revision-ledger.read');
        return ApiResponse::create((new RevisionLedgerService())->compare((string) $this->getRouteParam($request, 'id')));
    }

    public function checkpoint(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireLedgerPermission($request, 'revision-ledger.manage');
        $body = $this->getRequestBody($request);
        $route = trim((string) ($body['route'] ?? ''));
        if ($route === '') {
            throw new \RuntimeException('A page route is required.');
        }
        return ApiResponse::create((new RevisionLedgerService())->checkpointRoute(
            $route,
            (string) ($body['reason'] ?? ''),
            'manual',
            $this->userIdentity($request)
        ));
    }

    public function restore(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireLedgerPermission($request, 'revision-ledger.restore');
        $body = $this->getRequestBody($request);
        return ApiResponse::create((new RevisionLedgerService())->restore(
            (string) $this->getRouteParam($request, 'id'),
            (string) ($body['confirmation'] ?? ''),
            $this->userIdentity($request)
        ));
    }

    public function prune(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireLedgerPermission($request, 'revision-ledger.manage');
        $body = $this->getRequestBody($request);
        return ApiResponse::create((new RevisionLedgerService())->prune(
            isset($body['route']) && trim((string) $body['route']) !== '' ? (string) $body['route'] : null,
            (bool) ($body['execute'] ?? false)
        ));
    }

    private function requireLedgerPermission(ServerRequestInterface $request, string $permission): void
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

    private function userIdentity(ServerRequestInterface $request): string
    {
        $user = $this->getUser($request);
        foreach (['fullname', 'username', 'email'] as $key) {
            $value = $user && method_exists($user, 'get') ? trim((string) $user->get($key)) : '';
            if ($value !== '') {
                return $value;
            }
        }
        return 'System';
    }
}
