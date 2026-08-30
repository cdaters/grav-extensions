<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Contracts;

use InvalidArgumentException;
use LogicException;

final class HttpRequest
{
    public readonly string $method;
    public readonly string $uri;
    /** @var array<string, string> */
    private array $headers;
    /** @var array<string, CredentialValueInterface> */
    private array $credentialHeaders;
    public readonly ?string $body;

    /**
     * Credential strings are deliberately accepted only as CredentialValueInterface
     * instances, never in the ordinary header map.
     *
     * @param array<string, string> $headers
     * @param array<string, CredentialValueInterface> $credentialHeaders
     */
    public function __construct(
        string $method,
        string $uri,
        array $headers = [],
        ?string $body = null,
        array $credentialHeaders = []
    ) {
        $method = strtoupper(trim($method));
        $uri = trim($uri);
        if (preg_match('/^[A-Z]{3,12}$/D', $method) !== 1) {
            throw new InvalidArgumentException('HTTP methods must be uppercase alphabetic tokens.');
        }
        $parts = parse_url($uri);
        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])) {
            throw new InvalidArgumentException('HTTP request URIs must be absolute HTTP(S) URLs without credentials or fragments.');
        }
        if (isset($parts['query'])) {
            parse_str((string) $parts['query'], $query);
            if (self::containsSensitiveQueryKey($query)) {
                throw new InvalidArgumentException('HTTP credentials cannot be placed in URI query parameters.');
            }
        }

        $normalizedHeaders = [];
        foreach ($headers as $name => $value) {
            self::assertHeader($name, $value);
            if (self::sensitiveHeader($name)) {
                throw new InvalidArgumentException('Credential headers require CredentialValueInterface values.');
            }
            $normalizedHeaders[strtolower($name)] = $value;
        }
        $normalizedCredentialHeaders = [];
        foreach ($credentialHeaders as $name => $credential) {
            self::assertHeaderName($name);
            if (!$credential instanceof CredentialValueInterface) {
                throw new InvalidArgumentException('Credential headers require CredentialValueInterface values.');
            }
            $normalizedCredentialHeaders[strtolower($name)] = $credential;
        }
        ksort($normalizedHeaders, SORT_STRING);
        ksort($normalizedCredentialHeaders, SORT_STRING);

        $this->method = $method;
        $this->uri = $uri;
        $this->headers = $normalizedHeaders;
        $this->credentialHeaders = $normalizedCredentialHeaders;
        $this->body = $body;
    }

    /** @return array<string, string> */
    public function publicHeaders(): array
    {
        return $this->headers;
    }

    /**
     * This is the only transport-facing method that reveals credential values.
     * Callers must never log, serialize, cache, or persist its return value.
     *
     * @return array<string, string>
     */
    public function headersForTransport(): array
    {
        $headers = $this->headers;
        foreach ($this->credentialHeaders as $name => $credential) {
            $headers[$name] = $credential->reveal();
        }
        ksort($headers, SORT_STRING);
        return $headers;
    }

    /**
     * Sanitized diagnostic form. Raw body content is represented only by its
     * byte length and digest.
     *
     * @return array{method: string, uri: string, headers: array<string, string>, body_bytes: int, body_sha256: ?string}
     */
    public function toArray(): array
    {
        $headers = $this->headers;
        foreach ($this->credentialHeaders as $name => $_credential) {
            $headers[$name] = '[REDACTED]';
        }
        ksort($headers, SORT_STRING);
        return [
            'method' => $this->method,
            'uri' => $this->uri,
            'headers' => $headers,
            'body_bytes' => strlen($this->body ?? ''),
            'body_sha256' => $this->body === null ? null : hash('sha256', $this->body),
        ];
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode(
            $this->toArray(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ));
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return $this->toArray();
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('HTTP requests containing credential references cannot be serialized.');
    }

    private static function assertHeader(string $name, mixed $value): void
    {
        self::assertHeaderName($name);
        if (!is_string($value) || preg_match('/[\r\n]/', $value) === 1) {
            throw new InvalidArgumentException('HTTP header values must be strings without line breaks.');
        }
    }

    private static function assertHeaderName(string $name): void
    {
        if (preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/D', $name) !== 1) {
            throw new InvalidArgumentException('HTTP header names are invalid.');
        }
    }

    private static function sensitiveHeader(string $name): bool
    {
        return preg_match('/(?:authorization|api[-_]?key|token|secret|password|credential)/i', $name) === 1;
    }

    /** @param array<mixed> $query */
    private static function containsSensitiveQueryKey(array $query): bool
    {
        foreach ($query as $key => $value) {
            if (is_string($key) && self::sensitiveHeader($key)) {
                return true;
            }
            if (is_array($value) && self::containsSensitiveQueryKey($value)) {
                return true;
            }
        }
        return false;
    }
}
