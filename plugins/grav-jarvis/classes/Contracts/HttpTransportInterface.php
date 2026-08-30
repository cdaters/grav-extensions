<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Contracts;

interface HttpTransportInterface
{
    public function send(HttpRequest $request): HttpResponse;
}
