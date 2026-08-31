<?php

declare(strict_types=1);

namespace Grav\Plugin\GravCaxton\Contracts;

interface CaxtonExtensionInterface
{
    public function id(): string;

    public function version(): string;

    /** @return list<string> */
    public function capabilities(): array;

    /** @return list<string> */
    public function constructTypes(): array;
}
