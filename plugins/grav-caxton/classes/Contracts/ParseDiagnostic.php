<?php

declare(strict_types=1);

namespace Grav\Plugin\GravCaxton\Contracts;

use InvalidArgumentException;

final readonly class ParseDiagnostic
{
    public function __construct(
        public string $code,
        public string $severity,
        public string $message,
        public int $offset
    ) {
        if ($code === '' || !in_array($severity, ['info', 'warning', 'error'], true) || $offset < 0) {
            throw new InvalidArgumentException('Invalid parse diagnostic.');
        }
    }
}
