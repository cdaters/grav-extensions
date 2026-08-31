<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Controller;

use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Exceptions\ApiException;
use Grav\Plugin\Api\Exceptions\ConflictException;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\GravJarvis\Admin\ActionPromptLibrary;
use Grav\Plugin\GravJarvis\Admin\BoundedContextBuilder;
use Grav\Plugin\GravJarvis\Admin\JarvisAdminService;
use Grav\Plugin\GravJarvis\Admin\TransientProposalStore;
use Grav\Plugin\GravJarvis\Contracts\JarvisServiceInterface;
use Grav\Plugin\GravJarvis\Contracts\Exception\ProviderFailureException;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;

final class ApiController extends AbstractApiController
{
    private const MAX_BODY_CONTENT_BYTES = 2097152;

    public function bootstrap(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'grav-jarvis.access');
        $canApprove = $this->hasPermissionWithinScope($request, 'grav-jarvis.approve');
        return ApiResponse::create($this->admin()->bootstrap($canApprove));
    }

    public function validateProvider(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'grav-jarvis.access');
        return ApiResponse::create($this->admin()->validation($this->providerId($request)));
    }

    public function models(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'grav-jarvis.access');
        return ApiResponse::create($this->admin()->models($this->providerId($request)));
    }

    public function complete(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'grav-jarvis.use');
        $body = $this->body($request, ['provider_id', 'model', 'prompt']);
        try {
            return ApiResponse::create($this->admin()->complete(
                $this->providerFromBody($body),
                $this->optionalString($body, 'model', 256),
                $this->requiredString($body, 'prompt', 8000)
            ));
        } catch (ProviderFailureException) {
            throw $this->providerUnavailable();
        } catch (InvalidArgumentException $error) {
            throw new ValidationException($this->safeValidationMessage($error));
        } catch (Throwable) {
            throw new ApiException(503, 'Service Unavailable', 'Jarvis could not complete the prompt.');
        }
    }

    public function pageContext(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'grav-jarvis.use');
        $query = $request->getQueryParams();
        $route = $this->routeValue($query['route'] ?? null);
        $page = $this->page($route);
        $this->authorizePageAction($request, $page, 'read', 'api.pages.read');
        return ApiResponse::create($this->admin()->pageContext($this->pageData($page, [])));
    }

    public function propose(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'grav-jarvis.use');
        $body = $this->body($request, [
            'provider_id', 'model', 'action', 'custom_instruction', 'route',
            'content', 'title', 'template', 'language',
        ]);
        $route = $this->routeValue($body['route'] ?? null);
        $page = $this->page($route);
        $this->authorizePageAction($request, $page, 'read', 'api.pages.read');
        $content = $this->requiredString($body, 'content', self::MAX_BODY_CONTENT_BYTES, true);

        try {
            return ApiResponse::create($this->admin()->propose(
                $this->actor($request),
                $this->providerFromBody($body),
                $this->optionalString($body, 'model', 256),
                $this->requiredString($body, 'action', 32),
                $this->optionalString($body, 'custom_instruction', 4000),
                $this->pageData($page, $body),
                $content
            ));
        } catch (ProviderFailureException) {
            throw $this->providerUnavailable();
        } catch (InvalidArgumentException $error) {
            throw new ValidationException($this->safeValidationMessage($error));
        } catch (Throwable) {
            throw new ApiException(503, 'Service Unavailable', 'Jarvis could not create a proposal.');
        }
    }

    public function accept(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'grav-jarvis.approve');
        $body = $this->body($request, ['route', 'current_content', 'proposed_content']);
        $route = $this->routeValue($body['route'] ?? null);
        $page = $this->page($route);
        $this->authorizePageAction($request, $page, 'update', 'api.pages.write');
        $proposalId = (string) $this->getRouteParam($request, 'id');

        try {
            return ApiResponse::create($this->admin()->accept(
                $this->actor($request),
                $proposalId,
                $route,
                $this->requiredString($body, 'current_content', self::MAX_BODY_CONTENT_BYTES, true),
                $this->requiredString($body, 'proposed_content', self::MAX_BODY_CONTENT_BYTES, true)
            ));
        } catch (InvalidArgumentException $error) {
            throw new ValidationException($this->safeValidationMessage($error));
        } catch (RuntimeException) {
            throw new ConflictException('This Jarvis proposal is stale, expired, or already accepted. Generate a new proposal.');
        }
    }

    /** @return array<string, mixed> */
    private function body(ServerRequestInterface $request, array $allowed): array
    {
        $body = $this->getRequestBody($request);
        $unknown = array_diff(array_keys($body), $allowed);
        if ($unknown !== []) {
            throw new ValidationException('The request contains unsupported fields.');
        }
        return $body;
    }

    private function providerId(ServerRequestInterface $request): string
    {
        return $this->knownProvider((string) $this->getRouteParam($request, 'id'));
    }

    /** @param array<string, mixed> $body */
    private function providerFromBody(array $body): string
    {
        return $this->knownProvider($this->requiredString($body, 'provider_id', 128));
    }

    private function knownProvider(string $providerId): string
    {
        $providerId = trim(rawurldecode($providerId));
        if ($providerId === '' || preg_match('/^[a-z0-9][a-z0-9._-]{0,127}$/D', $providerId) !== 1
            || !in_array($providerId, $this->service()->providerIds(), true)) {
            throw new ValidationException('The selected Jarvis provider is unavailable.');
        }
        return $providerId;
    }

    /** @param array<string, mixed> $values */
    private function requiredString(array $values, string $key, int $bytes, bool $allowEmpty = false): string
    {
        if (!array_key_exists($key, $values) || !is_string($values[$key])) {
            throw new ValidationException("The {$key} field must be a string.");
        }
        $value = $values[$key];
        if ((!$allowEmpty && trim($value) === '') || strlen($value) > $bytes
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new ValidationException("The {$key} field is invalid or exceeds its safety limit.");
        }
        return $value;
    }

    /** @param array<string, mixed> $values */
    private function optionalString(array $values, string $key, int $bytes): ?string
    {
        if (!array_key_exists($key, $values) || $values[$key] === null || $values[$key] === '') {
            return null;
        }
        return $this->requiredString($values, $key, $bytes);
    }

    private function routeValue(mixed $route): string
    {
        if (!is_string($route)) {
            throw new ValidationException('The route field must be a string.');
        }
        $route = '/' . ltrim(trim($route), '/');
        if ($route === '/' || strlen($route) > 1024 || str_contains($route, "\0")) {
            throw new ValidationException('The page route is invalid.');
        }
        return $route;
    }

    private function page(string $route): PageInterface
    {
        $page = $this->resolvePageByRoute($route);
        if (!$page instanceof PageInterface) {
            throw new NotFoundException('The requested page was not found.');
        }
        return $page;
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function pageData(PageInterface $page, array $overrides): array
    {
        $header = $page->header();
        $frontmatter = is_object($header) && method_exists($header, 'toArray')
            ? $header->toArray()
            : (is_array($header) || is_object($header) ? (array) $header : []);
        $media = [];
        foreach ($page->media() as $filename => $medium) {
            try {
                $metadata = is_object($medium) && method_exists($medium, 'metadata') ? $medium->metadata() : [];
            } catch (Throwable) {
                $metadata = [];
            }
            $metadata = is_array($metadata) || is_object($metadata) ? (array) $metadata : [];
            $media[] = [
                'filename' => is_string($filename) ? $filename : (string) ($metadata['filename'] ?? ''),
                'type' => (string) ($metadata['type'] ?? ''),
                'mime' => (string) ($metadata['mime'] ?? $metadata['MimeType'] ?? ''),
                'width' => is_numeric($metadata['width'] ?? null) ? (int) $metadata['width'] : null,
                'height' => is_numeric($metadata['height'] ?? null) ? (int) $metadata['height'] : null,
                'alt' => (string) ($metadata['alt'] ?? ''),
                'title' => (string) ($metadata['title'] ?? ''),
            ];
        }
        return [
            'route' => (string) $page->rawRoute(),
            'title' => $this->override($overrides, 'title', (string) $page->title(), 512),
            'template' => $this->override($overrides, 'template', (string) $page->template(), 256),
            'language' => $this->override($overrides, 'language', (string) $page->language(), 64),
            'frontmatter' => is_array($frontmatter) ? $frontmatter : [],
            'media' => $media,
        ];
    }

    /** @param array<string, mixed> $values */
    private function override(array $values, string $key, string $fallback, int $bytes): string
    {
        return array_key_exists($key, $values)
            ? (string) ($this->optionalString($values, $key, $bytes) ?? '')
            : $fallback;
    }

    private function actor(ServerRequestInterface $request): string
    {
        $user = $this->getUser($request);
        foreach (['username', 'email', 'id'] as $property) {
            if (method_exists($user, 'get')) {
                $value = $user->get($property);
                if (is_scalar($value) && (string) $value !== '') {
                    return $property . ':' . (string) $value;
                }
            }
        }
        return 'authenticated:' . spl_object_id($user);
    }

    private function service(): JarvisServiceInterface
    {
        $service = $this->grav['gravJarvis'] ?? null;
        if (!$service instanceof JarvisServiceInterface) {
            throw new ApiException(503, 'Service Unavailable', 'Jarvis is disabled or unavailable.');
        }
        return $service;
    }

    private function admin(): JarvisAdminService
    {
        $cache = (string) $this->grav['locator']->findResource('cache://');
        if ($cache === '') {
            throw new ApiException(503, 'Service Unavailable', 'Jarvis temporary storage is unavailable.');
        }
        return new JarvisAdminService(
            $this->service(),
            new BoundedContextBuilder(),
            new ActionPromptLibrary(),
            new TransientProposalStore(rtrim($cache, '/\\') . '/grav-jarvis/proposals')
        );
    }

    private function providerUnavailable(): ApiException
    {
        return new ApiException(503, 'Service Unavailable', 'The selected Jarvis provider is unavailable. Try again later.');
    }

    private function safeValidationMessage(InvalidArgumentException $error): string
    {
        $message = $error->getMessage();
        return str_starts_with($message, 'The ') || str_starts_with($message, 'Custom ')
            ? $message
            : 'The Jarvis request is invalid.';
    }
}
