<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Testing;

use Grav\Plugin\GravJarvis\Contracts\Exception\HttpTransportException;
use Grav\Plugin\GravJarvis\Contracts\HttpRequest;
use Grav\Plugin\GravJarvis\Contracts\HttpResponse;
use Grav\Plugin\GravJarvis\Contracts\HttpTransportInterface;
use Throwable;

final class FixtureHttpTransport implements HttpTransportInterface
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

    public function send(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request->toArray();
        $fingerprint = $request->fingerprint();
        if (!array_key_exists($fingerprint, $this->fixtures)) {
            throw new HttpTransportException('No deterministic HTTP fixture matched the sanitized request.');
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
