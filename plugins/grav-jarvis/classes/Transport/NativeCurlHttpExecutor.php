<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Transport;

use Grav\Plugin\GravJarvis\Contracts\Exception\HttpTransportException;
use Grav\Plugin\GravJarvis\Contracts\HttpRequest;
use Grav\Plugin\GravJarvis\Contracts\HttpResponse;

final class NativeCurlHttpExecutor implements HttpExecutorInterface
{
    public function execute(
        HttpRequest $request,
        ResolvedDestination $destination,
        HttpTransportConfig $config
    ): HttpResponse {
        if (!function_exists('curl_init')) {
            throw new HttpTransportException('The PHP cURL extension is required for provider HTTP requests.');
        }

        $handle = curl_init();
        if ($handle === false) {
            throw new HttpTransportException('The HTTP transport could not be initialized.');
        }

        $body = '';
        $responseHeaders = [];
        $responseBytesExceeded = false;
        $responseHeaderBytesExceeded = false;
        $responseHeadersMalformed = false;
        $headerBytes = 0;
        $headersForTransport = $request->headersForTransport();
        $headerLines = [];
        $requestHeaderBytes = 0;

        try {
            foreach ($headersForTransport as $name => $value) {
                $line = $name . ': ' . $value;
                $requestHeaderBytes += strlen($line) + 2;
                if ($requestHeaderBytes > 65536) {
                    throw new HttpTransportException('The provider request headers exceeded the configured safety limit.');
                }
                $headerLines[] = $line;
            }
            unset($headersForTransport);

            $pinnedAddress = str_contains($destination->address, ':')
                ? '[' . $destination->address . ']'
                : $destination->address;

            $options = [
                CURLOPT_URL => $request->uri,
                CURLOPT_CUSTOMREQUEST => $request->method,
                CURLOPT_HTTPHEADER => $headerLines,
                CURLOPT_CONNECTTIMEOUT_MS => $config->connectTimeoutMilliseconds,
                CURLOPT_TIMEOUT_MS => $config->requestTimeoutMilliseconds,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROXY => '',
                CURLOPT_NOPROXY => '*',
                CURLOPT_NOSIGNAL => true,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_HEADER => false,
                CURLOPT_ENCODING => '',
                CURLOPT_USERAGENT => 'Grav-Jarvis/0.2.1',
                CURLOPT_RESOLVE => [
                    $destination->hostname . ':' . $destination->port . ':' . $pinnedAddress,
                ],
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (
                    &$body,
                    &$responseBytesExceeded,
                    $config
                ): int {
                    if (strlen($body) + strlen($chunk) > $config->maxResponseBytes) {
                        $responseBytesExceeded = true;
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
                CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (
                    &$responseHeaders,
                    &$headerBytes,
                    &$responseHeaderBytesExceeded,
                    &$responseHeadersMalformed,
                    $config
                ): int {
                    $headerBytes += strlen($line);
                    if ($headerBytes > $config->maxResponseHeaderBytes) {
                        $responseHeaderBytesExceeded = true;
                        return 0;
                    }
                    $trimmed = trim($line);
                    if ($trimmed === '') {
                        return strlen($line);
                    }
                    if (str_starts_with(strtoupper($trimmed), 'HTTP/')) {
                        $responseHeaders = [];
                        return strlen($line);
                    }
                    if (!str_contains($line, ':')) {
                        $responseHeadersMalformed = true;
                        return strlen($line);
                    }
                    [$name, $value] = explode(':', $line, 2);
                    $name = strtolower(trim($name));
                    $value = trim($value);
                    if (preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/D', $name) !== 1
                        || preg_match('/[\r\n]/', $value) === 1) {
                        $responseHeadersMalformed = true;
                        return strlen($line);
                    }
                    $responseHeaders[$name] = isset($responseHeaders[$name])
                        ? $responseHeaders[$name] . ', ' . $value
                        : $value;
                    return strlen($line);
                },
            ];
            if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
                $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
            }
            if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
                $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
            }
            if ($request->body !== null) {
                $options[CURLOPT_POSTFIELDS] = $request->body;
            }

            if (!curl_setopt_array($handle, $options)) {
                throw new HttpTransportException('The HTTP transport could not be configured safely.');
            }
            unset($options, $headerLines);

            $success = curl_exec($handle);
            $errorNumber = curl_errno($handle);
            if ($responseBytesExceeded) {
                throw new HttpTransportException('The provider response exceeded the configured size limit.');
            }
            if ($responseHeaderBytesExceeded) {
                throw new HttpTransportException('The provider response headers exceeded the configured size limit.');
            }
            if ($responseHeadersMalformed) {
                throw new HttpTransportException('The provider returned malformed HTTP response headers.');
            }
            if ($success === false) {
                if (defined('CURLE_OPERATION_TIMEDOUT') && $errorNumber === CURLE_OPERATION_TIMEDOUT) {
                    throw new HttpTransportException('The provider HTTP request timed out.');
                }
                throw new HttpTransportException('The provider HTTP request failed with transport code ' . $errorNumber . '.');
            }

            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($status < 100 || $status > 599) {
                throw new HttpTransportException('The provider returned an invalid HTTP status.');
            }
            return new HttpResponse($status, $responseHeaders, $body);
        } finally {
            unset($headersForTransport, $headerLines);
            curl_close($handle);
        }
    }
}
