<?php

declare(strict_types=1);

namespace Grav\Plugin\PrismGallery\Service;

use Grav\Common\Grav;

/**
 * Opaque, expiring delivery for local page media.
 *
 * This is hotlink deterrence, not DRM: a browser that can display a public
 * asset can ultimately save it. Raw filesystem and page-media paths are never
 * included in the public token.
 */
class ProtectedMediaService
{
    private Grav $grav;

    public function __construct()
    {
        $this->grav = Grav::instance();
    }

    public function registerUrl(string $url): string
    {
        $path = rawurldecode((string) (parse_url($url, PHP_URL_PATH) ?? ''));
        foreach (['/user/pages/', '/images/'] as $marker) {
            $position = strpos($path, $marker);
            if ($position !== false) {
                $relative = ltrim(substr($path, $position + 1), '/');
                return $this->registerPath(GRAV_ROOT . '/' . $relative);
            }
        }
        return '';
    }

    public function registerPath(string $path): string
    {
        $real = realpath($path);
        if ($real === false || !is_file($real) || !$this->allowedPath($real)) {
            return '';
        }

        $relative = ltrim(str_replace('\\', '/', substr($real, strlen(GRAV_ROOT))), '/');
        $id = substr(hash_hmac('sha256', $relative, $this->secret()), 0, 32);
        $mime = function_exists('mime_content_type') ? (string) mime_content_type($real) : 'application/octet-stream';
        $this->storeEntry($id, [
            'path' => $relative,
            'name' => basename($real),
            'mime' => $mime ?: 'application/octet-stream',
        ]);

        $root = rtrim((string) $this->grav['uri']->rootUrl(true), '/');
        return $root . '/prism-gallery/authorize?id=' . rawurlencode($id);
    }

    /** @return array{url:string,expires:int} */
    public function authorize(string $id): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $id) || !$this->entry($id)) {
            throw new \RuntimeException('Unknown media identifier.');
        }

        $ttl = max(60, min(86400, (int) $this->grav['config']->get('plugins.prism-gallery.protection.token_ttl', 900)));
        $expires = time() + $ttl;
        $payload = $id . '.' . $expires;
        $signature = $this->base64Url(hash_hmac('sha256', $payload, $this->secret(), true));
        $root = rtrim((string) $this->grav['uri']->rootUrl(true), '/');

        return [
            'url' => $root . '/prism-gallery/media?token=' . rawurlencode($payload . '.' . $signature),
            'expires' => $expires,
        ];
    }

    public function stream(string $token): void
    {
        $parts = explode('.', $token, 3);
        if (count($parts) !== 3) {
            throw new \RuntimeException('Malformed media token.');
        }

        [$id, $expires, $signature] = $parts;
        $payload = $id . '.' . $expires;
        $expected = $this->base64Url(hash_hmac('sha256', $payload, $this->secret(), true));
        if (!preg_match('/^[a-f0-9]{32}$/', $id) || !ctype_digit($expires) || (int) $expires < time() || !hash_equals($expected, $signature)) {
            throw new \RuntimeException('Invalid or expired media token.');
        }

        $entry = $this->entry($id);
        if (!$entry) {
            throw new \RuntimeException('Unknown media identifier.');
        }

        $path = realpath(GRAV_ROOT . '/' . ltrim((string) $entry['path'], '/'));
        if ($path === false || !is_file($path) || !$this->allowedPath($path)) {
            throw new \RuntimeException('Protected media is unavailable.');
        }

        $this->streamFile($path, (string) ($entry['name'] ?? basename($path)), (string) ($entry['mime'] ?? 'application/octet-stream'));
    }

    /** @param array{path:string,name:string,mime:string} $entry */
    private function storeEntry(string $id, array $entry): void
    {
        $path = $this->registryPath();
        $this->ensureDirectory(dirname($path));
        $handle = fopen($path, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new \RuntimeException('Unable to lock the protected media registry.');
        }

        $contents = stream_get_contents($handle);
        $registry = json_decode((string) $contents, true);
        $registry = is_array($registry) ? $registry : [];
        $registry[$id] = $entry;
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, (string) json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /** @return array<string,string>|null */
    private function entry(string $id): ?array
    {
        $path = $this->registryPath();
        if (!is_file($path)) {
            return null;
        }
        $registry = json_decode((string) file_get_contents($path), true);
        $entry = is_array($registry) ? ($registry[$id] ?? null) : null;
        return is_array($entry) ? $entry : null;
    }

    private function streamFile(string $path, string $name, string $mime): void
    {
        $size = (int) filesize($path);
        $start = 0;
        $end = max(0, $size - 1);
        $status = 200;
        $range = (string) ($_SERVER['HTTP_RANGE'] ?? '');

        if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $matches)) {
            if ($matches[1] === '' && $matches[2] !== '') {
                $suffix = min($size, (int) $matches[2]);
                $start = $size - $suffix;
            } else {
                $start = (int) ($matches[1] ?: 0);
                $end = $matches[2] !== '' ? min($end, (int) $matches[2]) : $end;
            }
            if ($start > $end || $start >= $size) {
                header('HTTP/1.1 416 Range Not Satisfiable');
                header('Content-Range: bytes */' . $size);
                exit;
            }
            $status = 206;
        }

        $length = $end - $start + 1;
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: 'media.bin';
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        http_response_code($status);
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . $length);
        header('Accept-Ranges: bytes');
        if ($status === 206) {
            header(sprintf('Content-Range: bytes %d-%d/%d', $start, $end, $size));
        }
        header('Content-Disposition: inline; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow, noarchive');

        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'HEAD') {
            exit;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open protected media.');
        }
        fseek($handle, $start);
        $remaining = $length;
        while ($remaining > 0 && !feof($handle)) {
            $chunk = fread($handle, min(1024 * 1024, $remaining));
            if ($chunk === false) {
                break;
            }
            echo $chunk;
            $remaining -= strlen($chunk);
            flush();
        }
        fclose($handle);
        exit;
    }

    private function registryPath(): string
    {
        return GRAV_ROOT . '/user/data/prism-gallery/media.json';
    }

    private function secret(): string
    {
        $path = GRAV_ROOT . '/user/data/prism-gallery/signing.key';
        if (is_file($path)) {
            $secret = trim((string) file_get_contents($path));
            if ($secret !== '') {
                return $secret;
            }
        }

        $this->ensureDirectory(dirname($path));
        $secret = bin2hex(random_bytes(32));
        if (file_put_contents($path, $secret . "\n", LOCK_EX) === false) {
            throw new \RuntimeException('Unable to persist the Prism Gallery signing key.');
        }
        @chmod($path, 0600);
        return $secret;
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new \RuntimeException('Unable to create the protected media directory.');
        }
    }

    private function within(string $path, string $root): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $root = rtrim(str_replace('\\', '/', $root), '/');
        return $path === $root || str_starts_with($path, $root . '/');
    }

    private function allowedPath(string $path): bool
    {
        foreach ([GRAV_ROOT . '/user/pages', GRAV_ROOT . '/images'] as $candidate) {
            $root = realpath($candidate);
            if ($root !== false && $this->within($path, $root)) {
                return true;
            }
        }
        return false;
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
