<?php

declare(strict_types=1);

namespace Grav\Plugin\GravCaxton\Controller;

use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Exceptions\ApiException;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\GravCaxton\Jarvis\CaxtonJarvisException;
use Grav\Plugin\GravCaxton\Jarvis\CaxtonJarvisService;
use Grav\Plugin\GravCaxton\Jarvis\CaxtonProposalStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

final class ApiController extends AbstractApiController
{
    public function jarvisStatus(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'grav-caxton.use');
        $this->requirePermission($request, 'grav-jarvis.use');
        return ApiResponse::create($this->jarvis()->status());
    }

    public function jarvisModels(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'grav-caxton.use');
        $this->requirePermission($request, 'grav-jarvis.use');
        return $this->run(fn (): array => $this->jarvis()->models($this->providerId($request)));
    }

    public function jarvisValidate(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'grav-caxton.use');
        $this->requirePermission($request, 'grav-jarvis.use');
        return $this->run(fn (): array => $this->jarvis()->validate($this->providerId($request)));
    }

    public function jarvisPropose(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'grav-caxton.use');
        $this->requirePermission($request, 'grav-jarvis.use');
        $body = $this->body($request, [
            'route', 'source', 'source_sha256', 'from', 'to', 'context_from', 'context_to',
            'block_kind', 'action', 'provider_id', 'model', 'custom_instruction',
        ]);
        $route = $this->route($body['route'] ?? null);
        $page = $this->page($route);
        $this->authorizePageAction($request, $page, 'read', 'api.pages.read');
        return $this->run(fn (): array => $this->jarvis()->propose(
            $this->actor($request),
            $route,
            $this->string($body, 'source', CaxtonJarvisService::MAX_SOURCE_BYTES, true),
            $this->string($body, 'source_sha256', 64),
            $this->integer($body, 'from'),
            $this->integer($body, 'to'),
            $this->integer($body, 'context_from'),
            $this->integer($body, 'context_to'),
            $this->string($body, 'block_kind', 32),
            $this->string($body, 'action', 32),
            $this->string($body, 'provider_id', 64),
            $this->optionalString($body, 'model', 256),
            $this->optionalString($body, 'custom_instruction', 4000)
        ));
    }

    public function jarvisAccept(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'grav-caxton.use');
        $this->requirePermission($request, 'grav-jarvis.approve');
        $body = $this->body($request, ['route', 'source', 'source_sha256', 'from', 'to', 'output']);
        $route = $this->route($body['route'] ?? null);
        $page = $this->page($route);
        $this->authorizePageAction($request, $page, 'update', 'api.pages.write');
        return $this->run(fn (): array => $this->jarvis()->accept(
            $this->actor($request),
            $this->proposalId($request),
            $route,
            $this->string($body, 'source', CaxtonJarvisService::MAX_SOURCE_BYTES, true),
            $this->string($body, 'source_sha256', 64),
            $this->integer($body, 'from'),
            $this->integer($body, 'to'),
            $this->string($body, 'output', CaxtonJarvisService::MAX_PROPOSAL_BYTES)
        ));
    }

    public function jarvisDiscard(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'grav-caxton.use');
        $this->requirePermission($request, 'grav-jarvis.use');
        $body = $this->body($request, ['route']);
        $route = $this->route($body['route'] ?? null);
        $page = $this->page($route);
        $this->authorizePageAction($request, $page, 'read', 'api.pages.read');
        return $this->run(function () use ($request, $route): array {
            $this->jarvis()->discard($this->actor($request), $this->proposalId($request), $route);
            return ['discarded' => true];
        });
    }

    /** @param list<string> $allowed @return array<string, mixed> */
    private function body(ServerRequestInterface $request, array $allowed): array
    {
        $body = $this->getRequestBody($request);
        if (array_diff(array_keys($body), $allowed) !== []) throw new ValidationException('The request contains unsupported fields.');
        return $body;
    }

    private function page(string $route): PageInterface
    {
        $page = $this->resolvePageByRoute($route);
        if (!$page instanceof PageInterface) throw new NotFoundException('The requested page was not found.');
        return $page;
    }

    private function route(mixed $value): string
    {
        if (!is_string($value)) throw new ValidationException('The route field must be a string.');
        $route = '/' . ltrim(trim($value), '/');
        if ($route === '/' || strlen($route) > 1024 || str_contains($route, "\0")) throw new ValidationException('The page route is invalid.');
        return $route;
    }

    /** @param array<string, mixed> $body */
    private function string(array $body, string $key, int $limit, bool $allowEmpty = false): string
    {
        if (!array_key_exists($key, $body) || !is_string($body[$key])) throw new ValidationException("The {$key} field must be a string.");
        $value = $body[$key];
        if ((!$allowEmpty && trim($value) === '') || strlen($value) > $limit
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new ValidationException("The {$key} field is invalid or exceeds its safety limit.");
        }
        return $value;
    }

    /** @param array<string, mixed> $body */
    private function optionalString(array $body, string $key, int $limit): ?string
    {
        if (!array_key_exists($key, $body) || $body[$key] === null || $body[$key] === '') return null;
        return $this->string($body, $key, $limit);
    }

    /** @param array<string, mixed> $body */
    private function integer(array $body, string $key): int
    {
        if (!array_key_exists($key, $body) || !is_int($body[$key])) throw new ValidationException("The {$key} field must be an integer.");
        return $body[$key];
    }

    private function providerId(ServerRequestInterface $request): string
    {
        $value = rawurldecode((string) $this->getRouteParam($request, 'id'));
        if (preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $value) !== 1) throw new ValidationException('The provider identifier is invalid.');
        return $value;
    }

    private function proposalId(ServerRequestInterface $request): string
    {
        $value = (string) $this->getRouteParam($request, 'id');
        if (preg_match('/^[a-f0-9]{32}$/D', $value) !== 1) throw new ValidationException('The proposal identifier is invalid.');
        return $value;
    }

    private function actor(ServerRequestInterface $request): string
    {
        $user = $this->getUser($request);
        foreach (['username', 'email', 'id'] as $property) {
            if (method_exists($user, 'get')) {
                $value = $user->get($property);
                if (is_scalar($value) && (string) $value !== '') return $property . ':' . (string) $value;
            }
        }
        return 'authenticated:' . spl_object_id($user);
    }

    private function jarvis(): CaxtonJarvisService
    {
        $cache = (string) $this->grav['locator']->findResource('cache://');
        if ($cache === '') throw new ApiException(503, 'Service Unavailable', 'Caxton temporary storage is unavailable.');
        $userPath = (string) $this->grav['locator']->findResource('user://');
        return new CaxtonJarvisService(
            $this->grav,
            new CaxtonProposalStore(rtrim($cache, '/\\') . '/grav-caxton/proposals'),
            'site:' . hash('sha256', $userPath !== '' ? $userPath : 'grav-default-site')
        );
    }

    private function run(callable $operation): ResponseInterface
    {
        try { return ApiResponse::create($operation()); }
        catch (CaxtonJarvisException $error) {
            [$status, $title] = match ($error->category) {
                'rate_limited' => [429, 'Provider Rate Limited'],
                'proposal_conflict' => [409, 'Proposal Conflict'],
                'invalid_action', 'invalid_model', 'invalid_target', 'protected_target', 'source_invalid', 'proposal_read_only' => [422, 'Invalid Caxton Request'],
                'budget_exceeded' => [422, 'Jarvis Budget Reached'],
                'response_invalid' => [502, 'Invalid Provider Response'],
                default => [503, 'Jarvis Unavailable'],
            };
            throw new ApiException($status, $title, $error->getMessage(), 'caxton_' . $error->category);
        } catch (Throwable) {
            throw new ApiException(503, 'Service Unavailable', 'Caxton could not complete the Jarvis action.');
        }
    }
}
