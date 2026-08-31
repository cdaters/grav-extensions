<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Reliability;

use Grav\Plugin\GravJarvis\Contracts\CompletionRequest;
use Grav\Plugin\GravJarvis\Contracts\ReliabilityContext;

final class CacheKeyFactory
{
    public const SCHEMA = 'grav-jarvis-response-v1';

    public function key(CompletionRequest $request, ReliabilityContext $context): string
    {
        return hash('sha256', json_encode([
            'schema' => self::SCHEMA,
            'scope_sha256' => $context->scopeHash(),
            'action' => $context->actionId,
            'request_sha256' => hash('sha256', $request->canonicalPayload()),
            'provider' => $request->providerId,
            'model' => $request->model,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
