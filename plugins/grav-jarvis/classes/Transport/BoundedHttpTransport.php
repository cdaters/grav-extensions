<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Transport;

use Grav\Plugin\GravJarvis\Contracts\Exception\HttpTransportException;
use Grav\Plugin\GravJarvis\Contracts\HttpRequest;
use Grav\Plugin\GravJarvis\Contracts\HttpResponse;
use Grav\Plugin\GravJarvis\Contracts\HttpTransportInterface;

final class BoundedHttpTransport implements HttpTransportInterface
{
    public function __construct(
        private readonly DestinationGuardInterface $destinations,
        private readonly HttpExecutorInterface $executor,
        private readonly HttpTransportConfig $config = new HttpTransportConfig()
    ) {
    }

    /** @param list<string> $allowedBaseUris */
    public static function forAllowedBaseUris(
        array $allowedBaseUris,
        ?HttpTransportConfig $config = null
    ): self {
        return new self(
            new PublicHttpsDestinationGuard($allowedBaseUris, new NativeDnsResolver()),
            new NativeCurlHttpExecutor(),
            $config ?? new HttpTransportConfig()
        );
    }

    public function send(HttpRequest $request): HttpResponse
    {
        foreach (array_keys($request->publicHeaders()) as $headerName) {
            if (in_array(strtolower($headerName), [
                'connection',
                'content-length',
                'host',
                'proxy-connection',
                'te',
                'trailer',
                'transfer-encoding',
                'upgrade',
            ], true)) {
                throw new HttpTransportException(
                    'The provider request contains a transport-controlled HTTP header.'
                );
            }
        }
        if (strlen($request->body ?? '') > $this->config->maxRequestBytes) {
            throw new HttpTransportException('The provider request exceeded the configured size limit.');
        }
        $destination = $this->destinations->resolve($request);
        return $this->executor->execute($request, $destination, $this->config);
    }
}
