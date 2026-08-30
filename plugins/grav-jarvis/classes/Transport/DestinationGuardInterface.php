<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Transport;

use Grav\Plugin\GravJarvis\Contracts\HttpRequest;

interface DestinationGuardInterface
{
    public function resolve(HttpRequest $request): ResolvedDestination;
}
