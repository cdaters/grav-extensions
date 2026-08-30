<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Transport;

use Grav\Plugin\GravJarvis\Contracts\Exception\HttpTransportException;
use Grav\Plugin\GravJarvis\Contracts\HttpRequest;
use InvalidArgumentException;

final class PublicHttpsDestinationGuard implements DestinationGuardInterface
{
    /** @var list<array{hostname: string, port: int, path: string}> */
    private array $allowedBases;

    /** @param list<string> $allowedBaseUris */
    public function __construct(
        array $allowedBaseUris,
        private readonly DnsResolverInterface $dns
    ) {
        if ($allowedBaseUris === []) {
            throw new InvalidArgumentException('At least one HTTPS provider base URI is required.');
        }

        $allowed = [];
        foreach ($allowedBaseUris as $baseUri) {
            if (!is_string($baseUri)) {
                throw new InvalidArgumentException('Provider base URIs must be strings.');
            }
            $parts = $this->parseHttpsUri($baseUri, true);
            $key = $parts['hostname'] . ':' . $parts['port'] . $parts['path'];
            $allowed[$key] = $parts;
        }
        ksort($allowed, SORT_STRING);
        $this->allowedBases = array_values($allowed);
    }

    public function resolve(HttpRequest $request): ResolvedDestination
    {
        try {
            $destination = $this->parseHttpsUri($request->uri, false);
        } catch (InvalidArgumentException) {
            throw new HttpTransportException('The provider request destination is unsafe.');
        }

        $allowed = false;
        foreach ($this->allowedBases as $base) {
            if ($destination['hostname'] !== $base['hostname']
                || $destination['port'] !== $base['port']) {
                continue;
            }
            if ($destination['path'] === $base['path']
                || str_starts_with($destination['path'], rtrim($base['path'], '/') . '/')) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            throw new HttpTransportException('The provider request destination is outside its allowed base URI.');
        }

        $addresses = $this->dns->resolve($destination['hostname']);
        if ($addresses === []) {
            throw new HttpTransportException('The provider hostname could not be resolved.');
        }
        foreach ($addresses as $address) {
            if (!is_string($address) || !$this->isPublicAddress($address)) {
                throw new HttpTransportException('The provider hostname resolved to a non-public address.');
            }
        }
        $addresses = array_values(array_unique($addresses));
        sort($addresses, SORT_STRING);

        return new ResolvedDestination(
            $destination['hostname'],
            $destination['port'],
            $addresses[0]
        );
    }

    /**
     * @return array{hostname: string, port: int, path: string}
     */
    private function parseHttpsUri(string $uri, bool $baseUri): array
    {
        $parts = parse_url(trim($uri));
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || ($baseUri && isset($parts['query']))) {
            throw new InvalidArgumentException('Provider destinations must be absolute HTTPS URLs.');
        }

        $hostname = strtolower((string) $parts['host']);
        if (str_ends_with($hostname, '.')
            || strlen($hostname) > 253
            || filter_var($hostname, FILTER_VALIDATE_IP) !== false
            || preg_match('/^[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?$/D', $hostname) !== 1
            || !str_contains($hostname, '.')) {
            throw new InvalidArgumentException('Provider destinations require a bounded DNS hostname.');
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : 443;
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Provider destination port is invalid.');
        }

        $path = (string) ($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        if (!str_starts_with($path, '/')
            || preg_match('/[\\\\\x00-\x1F\x7F]/', $path) === 1
            || preg_match('/%(?:25|2e|2f|5c)/i', $path) === 1) {
            throw new InvalidArgumentException('Provider destination path is unsafe.');
        }
        foreach (explode('/', rawurldecode($path)) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('Provider destination path traversal is not allowed.');
            }
        }
        if ($baseUri && strlen($path) > 1) {
            $path = rtrim($path, '/');
        }

        if (!$baseUri && isset($parts['query'])) {
            $query = (string) $parts['query'];
            if (strlen($query) > 2048
                || preg_match('/[\x00-\x1F\x7F]/', rawurldecode($query)) === 1) {
                throw new InvalidArgumentException('Provider destination query is unsafe.');
            }
        }

        return ['hostname' => $hostname, 'port' => $port, 'path' => $path];
    }

    private function isPublicAddress(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
