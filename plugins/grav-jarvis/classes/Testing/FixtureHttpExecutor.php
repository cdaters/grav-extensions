<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Testing;

use Grav\Plugin\GravJarvis\Contracts\Exception\HttpTransportException;
use Grav\Plugin\GravJarvis\Contracts\HttpRequest;
use Grav\Plugin\GravJarvis\Contracts\HttpResponse;
use Grav\Plugin\GravJarvis\Transport\HttpExecutorInterface;
use Grav\Plugin\GravJarvis\Transport\HttpTransportConfig;
use Grav\Plugin\GravJarvis\Transport\ResolvedDestination;
use Throwable;

final class FixtureHttpExecutor implements HttpExecutorInterface
{
    /** @var array<string, HttpResponse|Throwable> */
    private array $fixtures = [];
    /** @var list<array<string, mixed>> */
    private array $requests = [];

    public function addResponse(HttpRequest $request, HttpResponse $response): void
    {
        $this->fixtures[$request->fingerprint()] = $response;
    }

    public function addFailure(HttpRequest $request, Throwable $failure): void
    {
        $this->fixtures[$request->fingerprint()] = $failure;
    }

    public function execute(
        HttpRequest $request,
        ResolvedDestination $destination,
        HttpTransportConfig $config
    ): HttpResponse {
        $this->requests[] = [
            'request' => $request->toArray(),
            'destination' => $destination->toArray(),
            'config' => $config->toArray(),
        ];
        $fingerprint = $request->fingerprint();
        if (!array_key_exists($fingerprint, $this->fixtures)) {
            throw new HttpTransportException('No deterministic HTTP executor fixture matched the sanitized request.');
        }
        $fixture = $this->fixtures[$fingerprint];
        if ($fixture instanceof Throwable) {
            throw $fixture;
        }
        return $fixture;
    }

    /** @return list<array<string, mixed>> */
    public function requests(): array
    {
        return $this->requests;
    }
}
