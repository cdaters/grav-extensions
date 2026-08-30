<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Transport;

use Grav\Plugin\GravJarvis\Contracts\HttpRequest;
use Grav\Plugin\GravJarvis\Contracts\HttpResponse;

interface HttpExecutorInterface
{
    public function execute(
        HttpRequest $request,
        ResolvedDestination $destination,
        HttpTransportConfig $config
    ): HttpResponse;
}
