<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Transport;

use InvalidArgumentException;

final readonly class ResolvedDestination
{
    public function __construct(
        public string $hostname,
        public int $port,
        public string $address
    ) {
        if ($this->hostname === '' || $this->port < 1 || $this->port > 65535) {
            throw new InvalidArgumentException('Resolved HTTP destination is invalid.');
        }
        if (filter_var($this->address, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException('Resolved HTTP destination address is invalid.');
        }
    }

    /** @return array{hostname: string, port: int, address: string} */
    public function toArray(): array
    {
        return [
            'hostname' => $this->hostname,
            'port' => $this->port,
            'address' => $this->address,
        ];
    }
}
