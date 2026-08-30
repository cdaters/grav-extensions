<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Testing;

use Grav\Plugin\GravJarvis\Contracts\Exception\HttpTransportException;
use Grav\Plugin\GravJarvis\Transport\DnsResolverInterface;

final class StaticDnsResolver implements DnsResolverInterface
{
    /** @param array<string, list<string>> $addresses */
    public function __construct(private readonly array $addresses)
    {
    }

    public function resolve(string $hostname): array
    {
        if (!isset($this->addresses[$hostname])) {
            throw new HttpTransportException('No deterministic DNS fixture matched the provider hostname.');
        }
        return $this->addresses[$hostname];
    }
}
