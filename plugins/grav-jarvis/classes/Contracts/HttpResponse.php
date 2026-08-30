<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Contracts;

use InvalidArgumentException;
use LogicException;

final class HttpResponse
{
    public readonly int $status;
    /** @var array<string, string> */
    private array $headers;
    public readonly string $body;

    /** @param array<string, string> $headers */
    public function __construct(int $status, array $headers = [], string $body = '')
    {
        if ($status < 100 || $status > 599) {
            throw new InvalidArgumentException('HTTP response status must be between 100 and 599.');
        }
        $normalized = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name) || !is_string($value)
                || preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/D', $name) !== 1
                || preg_match('/[\r\n]/', $value) === 1) {
                throw new InvalidArgumentException('HTTP response headers are invalid.');
            }
            $normalized[strtolower($name)] = $value;
        }
        ksort($normalized, SORT_STRING);

        $this->status = $status;
        $this->headers = $normalized;
        $this->body = $body;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'status' => $this->status,
            'header_names' => array_keys($this->headers),
            'body_bytes' => strlen($this->body),
        ];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Raw HTTP responses cannot be serialized.');
    }
}
