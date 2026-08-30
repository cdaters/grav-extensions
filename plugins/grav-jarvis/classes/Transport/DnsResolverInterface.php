<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Transport;

interface DnsResolverInterface
{
    /** @return list<string> */
    public function resolve(string $hostname): array;
}
