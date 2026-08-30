<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Transport;

use Grav\Plugin\GravJarvis\Contracts\Exception\HttpTransportException;

final class NativeDnsResolver implements DnsResolverInterface
{
    public function resolve(string $hostname): array
    {
        $addresses = [];
        $records = function_exists('dns_get_record')
            && defined('DNS_A')
            && defined('DNS_AAAA')
            ? @dns_get_record($hostname, DNS_A | DNS_AAAA)
            : false;
        if (is_array($records)) {
            foreach ($records as $record) {
                $address = $record['ip'] ?? $record['ipv6'] ?? null;
                if (is_string($address) && $address !== '') {
                    $addresses[$address] = true;
                }
            }
        }

        if ($addresses === []) {
            $ipv4Addresses = function_exists('gethostbynamel')
                ? @gethostbynamel($hostname)
                : false;
            if (is_array($ipv4Addresses)) {
                foreach ($ipv4Addresses as $address) {
                    if (is_string($address) && $address !== '') {
                        $addresses[$address] = true;
                    }
                }
            }
        }

        if ($addresses === []) {
            throw new HttpTransportException('The provider hostname could not be resolved.');
        }

        $resolved = array_keys($addresses);
        sort($resolved, SORT_STRING);
        return $resolved;
    }
}
