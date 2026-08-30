<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Transport;

use InvalidArgumentException;

final readonly class HttpTransportConfig
{
    public function __construct(
        public int $connectTimeoutMilliseconds = 3000,
        public int $requestTimeoutMilliseconds = 15000,
        public int $maxRequestBytes = 1048576,
        public int $maxResponseBytes = 2097152,
        public int $maxResponseHeaderBytes = 65536
    ) {
        if ($this->connectTimeoutMilliseconds < 100
            || $this->connectTimeoutMilliseconds > 30000) {
            throw new InvalidArgumentException('HTTP connect timeout is outside the supported bounds.');
        }
        if ($this->requestTimeoutMilliseconds < $this->connectTimeoutMilliseconds
            || $this->requestTimeoutMilliseconds > 60000) {
            throw new InvalidArgumentException('HTTP request timeout is outside the supported bounds.');
        }
        if ($this->maxRequestBytes < 1 || $this->maxRequestBytes > 10485760) {
            throw new InvalidArgumentException('HTTP request-size limit is outside the supported bounds.');
        }
        if ($this->maxResponseBytes < 1 || $this->maxResponseBytes > 10485760) {
            throw new InvalidArgumentException('HTTP response-size limit is outside the supported bounds.');
        }
        if ($this->maxResponseHeaderBytes < 1024 || $this->maxResponseHeaderBytes > 262144) {
            throw new InvalidArgumentException('HTTP response-header limit is outside the supported bounds.');
        }
    }

    /** @return array<string, int|bool> */
    public function toArray(): array
    {
        return [
            'connect_timeout_ms' => $this->connectTimeoutMilliseconds,
            'request_timeout_ms' => $this->requestTimeoutMilliseconds,
            'max_request_bytes' => $this->maxRequestBytes,
            'max_response_bytes' => $this->maxResponseBytes,
            'max_response_header_bytes' => $this->maxResponseHeaderBytes,
            'https_only' => true,
            'redirects_allowed' => false,
            'environment_proxy_allowed' => false,
            'dns_pinning' => true,
        ];
    }
}
