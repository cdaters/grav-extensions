<?php

declare(strict_types=1);

namespace Grav\Plugin\ImageFoundry\Service;

use Grav\Common\Grav;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use RuntimeException;

final class ImageFoundryService
{
    private const SCHEMA = 1;
    private const VERSION = '0.1.0';

    private Grav $grav;
    private array $config;
    private string $root;
    private string $storage;

    public function __construct()
    {
        $this->grav = Grav::instance();
        $this->config = (array) $this->grav['config']->get('plugins.image-foundry', []);
        $root = realpath(GRAV_ROOT);
        if ($root === false) {
            throw new RuntimeException('Unable to resolve the Grav root.');
        }
        $this->root = rtrim($this->slash($root), '/');
        $this->storage = $this->resolveStorage();
    }

    public function status(): array
    {
        $catalog = $this->loadCatalog();
        $sources = array_values((array) ($catalog['sources'] ?? []));
        $derivativeCount = 0;
        $generatedBytes = 0;
        $sourceBytes = 0;
        $stale = 0;

        foreach ($sources as $source) {
            $sourceBytes += (int) ($source['bytes'] ?? 0);
            if ((bool) ($source['stale'] ?? true)) {
                $stale++;
            }
            foreach ((array) ($source['derivatives'] ?? []) as $items) {
                foreach ((array) $items as $item) {
                    $derivativeCount++;
                    $generatedBytes += (int) ($item['bytes'] ?? 0);
                }
            }
        }

        usort($sources, static fn(array $a, array $b): int => strcmp((string) $a['path'], (string) $b['path']));

        return [
            'version' => self::VERSION,
            'gd_available' => extension_loaded('gd'),
            'webp_available' => function_exists('imagewebp'),
            'avif_available' => function_exists('imageavif'),
            'exif_available' => function_exists('exif_read_data'),
            'storage_path' => $this->storage,
            'source_roots' => $this->sourceRoots(),
            'policy' => $this->policy(),
            'source_count' => count($sources),
            'stale_count' => $stale,
            'derivative_count' => $derivativeCount,
            'source_bytes' => $sourceBytes,
            'generated_bytes' => $generatedBytes,
            'last_scan' => $catalog['last_scan'] ?? null,
            'last_build' => $catalog['last_build'] ?? null,
            'scan_errors' => array_values((array) ($catalog['scan_errors'] ?? [])),
            'sources' => $sources,
            'safety_message' => 'Original images are authoritative. Image Foundry writes only to its generated-data directory and never overwrites a source.',
        ];
    }

    public function scan(): array
    {
        return $this->withLock(function (): array {
            $catalog = $this->loadCatalog();
            $previous = (array) ($catalog['sources'] ?? []);
            $found = [];
            $policyHash = $this->policyHash();

            $scanErrors = [];
            foreach ($this->discoverSources() as $logical => $absolute) {
                try {
                    $metadata = $this->inspectSource($logical, $absolute);
                } catch (\Throwable $e) {
                    $scanErrors[] = ['path' => $logical, 'message' => $e->getMessage()];
                    continue;
                }
                $old = $previous[$logical] ?? null;
                $unchanged = is_array($old)
                    && hash_equals((string) ($old['sha256'] ?? ''), $metadata['sha256'])
                    && hash_equals((string) ($old['policy_hash'] ?? ''), $policyHash);

                if ($unchanged) {
                    $metadata['derivatives'] = $this->validDerivatives((array) ($old['derivatives'] ?? []));
                    $metadata['stale'] = !$this->derivativeSetComplete($metadata['derivatives'], (int) $metadata['width']);
                    $metadata['built_at'] = $old['built_at'] ?? null;
                } else {
                    if (is_array($old)) {
                        $this->removeDerivativeSet((array) ($old['derivatives'] ?? []));
                    }
                    $metadata['derivatives'] = [];
                    $metadata['stale'] = true;
                    $metadata['built_at'] = null;
                }
                $metadata['policy_hash'] = $policyHash;
                $found[$logical] = $metadata;
            }

            foreach ($previous as $logical => $old) {
                if (!isset($found[$logical]) && is_array($old)) {
                    $this->removeDerivativeSet((array) ($old['derivatives'] ?? []));
                }
            }

            $catalog['schema'] = self::SCHEMA;
            $catalog['version'] = self::VERSION;
            $catalog['last_scan'] = gmdate(DATE_ATOM);
            $catalog['scan_errors'] = $scanErrors;
            $catalog['sources'] = $found;
            $this->saveCatalog($catalog);

            return [
                'scanned' => count($found),
                'stale' => count(array_filter($found, static fn(array $item): bool => (bool) $item['stale'])),
                'errors' => $scanErrors,
                'last_scan' => $catalog['last_scan'],
            ];
        });
    }

    public function build(?string $source = null, bool $all = false, ?int $limit = null): array
    {
        $this->assertGd();
        $this->scan();
        $limit = max(1, min(500, $limit ?? (int) ($this->config['build_limit'] ?? 50)));

        return $this->withLock(function () use ($source, $all, $limit): array {
            $catalog = $this->loadCatalog();
            $sources = (array) ($catalog['sources'] ?? []);
            $targets = [];

            if ($source !== null && trim($source) !== '') {
                $logical = $this->normaliseLogicalPath($source);
                if (!isset($sources[$logical])) {
                    throw new NotFoundException('The requested image is not in the Image Foundry catalog.');
                }
                $targets[$logical] = $sources[$logical];
            } else {
                foreach ($sources as $logical => $item) {
                    if ($all || (bool) ($item['stale'] ?? true)) {
                        $targets[$logical] = $item;
                    }
                    if (!$all && count($targets) >= $limit) {
                        break;
                    }
                }
            }

            $built = 0;
            $failed = [];
            $created = 0;
            foreach ($targets as $logical => $item) {
                try {
                    $result = $this->buildSource((string) $logical, (array) $item);
                    $sources[$logical] = $result;
                    $built++;
                    foreach ((array) $result['derivatives'] as $variants) {
                        $created += count((array) $variants);
                    }
                } catch (\Throwable $e) {
                    $sources[$logical]['error'] = $e->getMessage();
                    $sources[$logical]['stale'] = true;
                    $failed[] = ['path' => $logical, 'message' => $e->getMessage()];
                    $this->grav['log']->error('[Image Foundry] ' . $logical . ': ' . $e->getMessage());
                }
            }

            $catalog['sources'] = $sources;
            $catalog['last_build'] = gmdate(DATE_ATOM);
            $this->saveCatalog($catalog);
            return [
                'built_sources' => $built,
                'created_derivatives' => $created,
                'failed' => $failed,
                'last_build' => $catalog['last_build'],
            ];
        });
    }

    public function purge(): array
    {
        return $this->withLock(function (): array {
            $catalog = $this->loadCatalog();
            $removed = 0;
            foreach ((array) ($catalog['sources'] ?? []) as $source) {
                $removed += $this->removeDerivativeSet((array) ($source['derivatives'] ?? []));
            }

            $directory = $this->derivativeDirectory();
            if (is_dir($directory)) {
                foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $file) {
                    if ($file->isFile() && preg_match('/^[a-f0-9]{32}\.(webp|avif)$/', $file->getFilename())) {
                        if (@unlink($file->getPathname())) {
                            $removed++;
                        }
                    }
                }
            }

            $this->saveCatalog($this->emptyCatalog());
            return ['removed_derivatives' => $removed];
        });
    }

    public function resolveAsset(string $id): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
            throw new NotFoundException('Derivative not found.');
        }
        $catalog = $this->loadCatalog();
        foreach ((array) ($catalog['sources'] ?? []) as $source) {
            foreach ((array) ($source['derivatives'] ?? []) as $format => $variants) {
                foreach ((array) $variants as $variant) {
                    if (hash_equals((string) ($variant['id'] ?? ''), $id)) {
                        $extension = $format === 'avif' ? 'avif' : 'webp';
                        $path = $this->derivativeDirectory() . '/' . $id . '.' . $extension;
                        $real = realpath($path);
                        $base = realpath($this->derivativeDirectory());
                        if ($real === false || $base === false || !$this->isContained($real, $base) || !is_file($real)) {
                            throw new NotFoundException('Derivative not found.');
                        }
                        return [
                            'path' => $real,
                            'mime' => $extension === 'avif' ? 'image/avif' : 'image/webp',
                            'etag' => '"' . hash_file('sha256', $real) . '"',
                            'bytes' => filesize($real) ?: 0,
                        ];
                    }
                }
            }
        }
        throw new NotFoundException('Derivative not found.');
    }

    public function pictureMarkup(
        string $source,
        string $fallbackUrl,
        string $alt = '',
        string $sizes = '100vw',
        string $class = '',
        string $loading = 'lazy'
    ): string {
        $fallback = htmlspecialchars($fallbackUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $attributes = 'src="' . $fallback . '" alt="' . htmlspecialchars($alt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        if ($class !== '') {
            $attributes .= ' class="' . htmlspecialchars($class, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }
        if (in_array($loading, ['lazy', 'eager'], true)) {
            $attributes .= ' loading="' . $loading . '"';
        }
        $fallbackImage = '<img ' . $attributes . '>';

        try {
            $logical = $this->normaliseLogicalPath($source);
            $entry = $this->loadCatalog()['sources'][$logical] ?? null;
            if (!is_array($entry) || (bool) ($entry['stale'] ?? true)) {
                return $fallbackImage;
            }
            $sourceTags = [];
            foreach (['avif' => 'image/avif', 'webp' => 'image/webp'] as $format => $mime) {
                $variants = array_values((array) ($entry['derivatives'][$format] ?? []));
                usort($variants, static fn(array $a, array $b): int => ((int) $a['width']) <=> ((int) $b['width']));
                if ($variants === []) {
                    continue;
                }
                $srcset = [];
                foreach ($variants as $variant) {
                    $srcset[] = $this->assetUrl((string) $variant['id']) . ' ' . (int) $variant['width'] . 'w';
                }
                $sourceTags[] = '<source type="' . $mime . '" srcset="'
                    . htmlspecialchars(implode(', ', $srcset), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    . '" sizes="' . htmlspecialchars($sizes, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
            }
            return $sourceTags === [] ? $fallbackImage : '<picture>' . implode('', $sourceTags) . $fallbackImage . '</picture>';
        } catch (\Throwable $e) {
            return $fallbackImage;
        }
    }

    private function buildSource(string $logical, array $item): array
    {
        $absolute = $this->absoluteSource($logical);
        $this->assertSourceStillMatches($absolute, $item);
        $image = $this->loadImage($absolute, (string) $item['mime']);
        $createdPaths = [];
        try {
            if ((bool) ($this->config['auto_orient_jpeg'] ?? true) && $item['mime'] === 'image/jpeg') {
                $image = $this->orientJpeg($image, $absolute);
            }
            $sourceWidth = imagesx($image);
            $sourceHeight = imagesy($image);
            $widths = array_values(array_filter($this->widths(), static fn(int $width): bool => $width < $sourceWidth));
            $widths[] = $sourceWidth;
            $widths = array_values(array_unique($widths));
            sort($widths, SORT_NUMERIC);

            $this->removeDerivativeSet((array) ($item['derivatives'] ?? []));
            $derivatives = [];
            foreach ($this->formats() as $format => $quality) {
                foreach ($widths as $width) {
                    $height = max(1, (int) round($sourceHeight * ($width / $sourceWidth)));
                    $target = imagecreatetruecolor($width, $height);
                    if (!$target) {
                        throw new RuntimeException('Unable to allocate a derivative canvas.');
                    }
                    $this->prepareTransparency($target);
                    if (!imagecopyresampled($target, $image, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight)) {
                        imagedestroy($target);
                        throw new RuntimeException('Image resampling failed.');
                    }

                    $id = substr(hash('sha256', $item['sha256'] . '|' . $this->policyHash() . '|' . $format . '|' . $width), 0, 32);
                    $path = $this->derivativeDirectory() . '/' . $id . '.' . $format;
                    $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
                    $ok = $format === 'avif'
                        ? imageavif($target, $temporary, $quality)
                        : imagewebp($target, $temporary, $quality);
                    imagedestroy($target);
                    if (!$ok || !is_file($temporary) || filesize($temporary) === 0) {
                        @unlink($temporary);
                        throw new RuntimeException('Failed to encode ' . strtoupper($format) . '.');
                    }
                    @chmod($temporary, 0644);
                    if (!@rename($temporary, $path)) {
                        @unlink($temporary);
                        throw new RuntimeException('Unable to atomically store a derivative.');
                    }
                    $createdPaths[] = $path;
                    $derivatives[$format][] = [
                        'id' => $id,
                        'width' => $width,
                        'height' => $height,
                        'bytes' => filesize($path) ?: 0,
                        'url' => $this->assetUrl($id),
                    ];
                }
            }

            $item['width'] = $sourceWidth;
            $item['height'] = $sourceHeight;
            $item['derivatives'] = $derivatives;
            $item['stale'] = false;
            $item['built_at'] = gmdate(DATE_ATOM);
            $item['error'] = null;
            return $item;
        } catch (\Throwable $e) {
            foreach ($createdPaths as $createdPath) {
                if (is_file($createdPath)) {
                    @unlink($createdPath);
                }
            }
            throw $e;
        } finally {
            if ($image instanceof \GdImage) {
                imagedestroy($image);
            }
        }
    }

    private function discoverSources(): array
    {
        $extensions = $this->sourceExtensions();
        $found = [];
        foreach ($this->sourceRoots() as $logicalRoot) {
            $absoluteRoot = $this->root . '/' . $logicalRoot;
            $realRoot = realpath($absoluteRoot);
            if ($realRoot === false || !is_dir($realRoot) || !$this->isContained($realRoot, $this->root)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($realRoot, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->isLink()) {
                    continue;
                }
                $extension = strtolower($file->getExtension());
                if (!in_array($extension, $extensions, true)) {
                    continue;
                }
                $real = realpath($file->getPathname());
                if ($real === false || !$this->isContained($real, $realRoot)) {
                    continue;
                }
                $logical = ltrim(substr($this->slash($real), strlen($this->root)), '/');
                $found[$logical] = $real;
            }
        }
        ksort($found, SORT_STRING);
        return $found;
    }

    private function inspectSource(string $logical, string $absolute): array
    {
        $bytes = filesize($absolute);
        if ($bytes === false || $bytes <= 0 || $bytes > (int) ($this->config['max_source_bytes'] ?? 52428800)) {
            throw new ValidationException('Source image exceeds the configured byte limit: ' . $logical);
        }
        $info = @getimagesize($absolute);
        if (!is_array($info) || !isset($info[0], $info[1], $info['mime'])) {
            throw new ValidationException('Unable to inspect image: ' . $logical);
        }
        $pixels = (int) $info[0] * (int) $info[1];
        if ($pixels <= 0 || $pixels > (int) ($this->config['max_source_pixels'] ?? 20000000)) {
            throw new ValidationException('Source image exceeds the configured pixel limit: ' . $logical);
        }
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/avif'];
        if (!in_array((string) $info['mime'], $allowedMimes, true)) {
            throw new ValidationException('Unsupported image type: ' . $logical);
        }
        $width = (int) $info[0];
        $height = (int) $info[1];
        if ((bool) ($this->config['auto_orient_jpeg'] ?? true)
            && $info['mime'] === 'image/jpeg'
            && function_exists('exif_read_data')) {
            $exif = @exif_read_data($absolute, 'IFD0');
            if (in_array((int) ($exif['Orientation'] ?? 1), [5, 6, 7, 8], true)) {
                [$width, $height] = [$height, $width];
            }
        }
        return [
            'path' => $logical,
            'sha256' => hash_file('sha256', $absolute),
            'bytes' => $bytes,
            'modified' => filemtime($absolute) ?: 0,
            'width' => $width,
            'height' => $height,
            'mime' => (string) $info['mime'],
        ];
    }

    private function loadImage(string $path, string $mime): \GdImage
    {
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            'image/avif' => function_exists('imagecreatefromavif') ? @imagecreatefromavif($path) : false,
            default => false,
        };
        if (!$image instanceof \GdImage) {
            throw new RuntimeException('GD could not decode this source image.');
        }
        return $image;
    }

    private function orientJpeg(\GdImage $image, string $path): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }
        $exif = @exif_read_data($path, 'IFD0');
        $orientation = (int) ($exif['Orientation'] ?? 1);
        if ($orientation === 2) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
            return $image;
        }
        if ($orientation === 4) {
            imageflip($image, IMG_FLIP_VERTICAL);
            return $image;
        }
        if (in_array($orientation, [5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }
        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            5, 8 => imagerotate($image, 90, 0),
            6, 7 => imagerotate($image, -90, 0),
            default => false,
        };
        if ($rotated instanceof \GdImage) {
            imagedestroy($image);
            return $rotated;
        }
        return $image;
    }

    private function prepareTransparency(\GdImage $image): void
    {
        if (!(bool) ($this->config['preserve_transparency'] ?? true)) {
            return;
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefilledrectangle($image, 0, 0, imagesx($image), imagesy($image), $transparent);
    }

    private function formats(): array
    {
        $formats = [];
        $configured = (array) ($this->config['formats'] ?? []);
        if ((bool) ($configured['webp']['enabled'] ?? true) && function_exists('imagewebp')) {
            $formats['webp'] = max(1, min(100, (int) ($configured['webp']['quality'] ?? 82)));
        }
        if ((bool) ($configured['avif']['enabled'] ?? true) && function_exists('imageavif')) {
            $formats['avif'] = max(1, min(100, (int) ($configured['avif']['quality'] ?? 56)));
        }
        if ($formats === []) {
            throw new ValidationException('No enabled image output format is supported by this PHP GD build.');
        }
        return $formats;
    }

    private function widths(): array
    {
        $widths = [];
        foreach ((array) ($this->config['widths'] ?? [480, 960, 1440, 1920]) as $width) {
            $value = (int) $width;
            if ($value >= 64 && $value <= 10000) {
                $widths[] = $value;
            }
        }
        $widths = array_values(array_unique($widths));
        sort($widths, SORT_NUMERIC);
        return $widths ?: [480, 960, 1440, 1920];
    }

    private function sourceRoots(): array
    {
        $roots = [];
        foreach ((array) ($this->config['source_roots'] ?? ['user/pages', 'user/themes']) as $root) {
            try {
                $roots[] = $this->normaliseLogicalPath((string) $root);
            } catch (\Throwable $e) {
                $this->grav['log']->warning('[Image Foundry] Ignoring unsafe source root: ' . (string) $root);
            }
        }
        return array_values(array_unique($roots));
    }

    private function sourceExtensions(): array
    {
        $extensions = [];
        foreach ((array) ($this->config['source_extensions'] ?? ['jpg', 'jpeg', 'png', 'webp', 'avif']) as $extension) {
            $value = strtolower(trim((string) $extension, " .\t\n\r\0\x0B"));
            if (in_array($value, ['jpg', 'jpeg', 'png', 'webp', 'avif'], true)) {
                $extensions[] = $value;
            }
        }
        return array_values(array_unique($extensions));
    }

    private function policy(): array
    {
        $available = [];
        try {
            $available = $this->formats();
        } catch (\Throwable) {
        }
        return [
            'widths' => $this->widths(),
            'formats' => $available,
            'preserve_transparency' => (bool) ($this->config['preserve_transparency'] ?? true),
            'auto_orient_jpeg' => (bool) ($this->config['auto_orient_jpeg'] ?? true),
        ];
    }

    private function policyHash(): string
    {
        return hash('sha256', json_encode($this->policy(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function assetUrl(string $id): string
    {
        $route = '/' . trim((string) ($this->config['route'] ?? '/image-foundry/asset'), '/');
        $base = method_exists($this->grav['uri'], 'rootUrl') ? rtrim((string) $this->grav['uri']->rootUrl(false), '/') : '';
        return $base . $route . '/' . rawurlencode($id);
    }

    private function resolveStorage(): string
    {
        $configured = trim((string) ($this->config['storage_path'] ?? '../image-foundry-data'));
        if ($configured === '' || str_contains($configured, "\0")) {
            throw new ValidationException('Image Foundry storage_path is invalid.');
        }
        $path = str_starts_with($configured, '/') ? $configured : $this->root . '/' . $configured;
        if (!is_dir($path) && !@mkdir($path, 0755, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create the Image Foundry storage directory.');
        }
        $real = realpath($path);
        if ($real === false || $this->isContained($real, $this->root)) {
            throw new ValidationException('Image Foundry storage must resolve outside the public Grav root.');
        }
        return rtrim($this->slash($real), '/');
    }

    private function derivativeDirectory(): string
    {
        $path = $this->storage . '/derivatives';
        if (!is_dir($path) && !@mkdir($path, 0755, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create the derivative directory.');
        }
        return $path;
    }

    private function catalogPath(): string
    {
        return $this->storage . '/catalog.json';
    }

    private function emptyCatalog(): array
    {
        return ['schema' => self::SCHEMA, 'version' => self::VERSION, 'last_scan' => null, 'last_build' => null, 'scan_errors' => [], 'sources' => []];
    }

    private function loadCatalog(): array
    {
        $path = $this->catalogPath();
        if (!is_file($path)) {
            return $this->emptyCatalog();
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded) || (int) ($decoded['schema'] ?? 0) !== self::SCHEMA || !is_array($decoded['sources'] ?? null)) {
            throw new RuntimeException('The Image Foundry catalog is invalid.');
        }
        return $decoded;
    }

    private function saveCatalog(array $catalog): void
    {
        ksort($catalog['sources'], SORT_STRING);
        $temporary = $this->catalogPath() . '.tmp-' . bin2hex(random_bytes(6));
        $json = json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($temporary, $json, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write the Image Foundry catalog.');
        }
        @chmod($temporary, 0644);
        if (!@rename($temporary, $this->catalogPath())) {
            @unlink($temporary);
            throw new RuntimeException('Unable to atomically replace the Image Foundry catalog.');
        }
    }

    private function validDerivatives(array $derivatives): array
    {
        $valid = [];
        foreach ($derivatives as $format => $variants) {
            if (!in_array($format, ['webp', 'avif'], true)) {
                continue;
            }
            foreach ((array) $variants as $variant) {
                $id = (string) ($variant['id'] ?? '');
                $path = $this->derivativeDirectory() . '/' . $id . '.' . $format;
                if (preg_match('/^[a-f0-9]{32}$/', $id) && is_file($path) && filesize($path) > 0) {
                    $variant['bytes'] = filesize($path);
                    $variant['url'] = $this->assetUrl($id);
                    $valid[$format][] = $variant;
                }
            }
        }
        return $valid;
    }

    private function derivativeSetComplete(array $derivatives, int $sourceWidth): bool
    {
        $expectedWidths = array_values(array_filter($this->widths(), static fn(int $width): bool => $width < $sourceWidth));
        $expectedWidths[] = $sourceWidth;
        $expectedWidths = array_values(array_unique($expectedWidths));
        sort($expectedWidths, SORT_NUMERIC);

        foreach (array_keys($this->formats()) as $format) {
            $actual = array_map(static fn(array $item): int => (int) ($item['width'] ?? 0), (array) ($derivatives[$format] ?? []));
            sort($actual, SORT_NUMERIC);
            if ($actual !== $expectedWidths) {
                return false;
            }
        }
        return $expectedWidths !== [];
    }

    private function removeDerivativeSet(array $derivatives): int
    {
        $removed = 0;
        foreach ($derivatives as $format => $variants) {
            if (!in_array($format, ['webp', 'avif'], true)) {
                continue;
            }
            foreach ((array) $variants as $variant) {
                $id = (string) ($variant['id'] ?? '');
                if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
                    continue;
                }
                $path = $this->derivativeDirectory() . '/' . $id . '.' . $format;
                if (is_file($path) && @unlink($path)) {
                    $removed++;
                }
            }
        }
        return $removed;
    }

    private function absoluteSource(string $logical): string
    {
        $logical = $this->normaliseLogicalPath($logical);
        $path = realpath($this->root . '/' . $logical);
        if ($path === false || !is_file($path) || !$this->isContained($path, $this->root)) {
            throw new NotFoundException('Source image no longer exists.');
        }
        $allowed = false;
        foreach ($this->sourceRoots() as $root) {
            $rootPath = realpath($this->root . '/' . $root);
            if ($rootPath !== false && $this->isContained($path, $rootPath)) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            throw new ValidationException('Source image is outside configured roots.');
        }
        return $path;
    }

    private function assertSourceStillMatches(string $absolute, array $item): void
    {
        $sha = hash_file('sha256', $absolute);
        if (!hash_equals((string) ($item['sha256'] ?? ''), $sha)) {
            throw new ValidationException('Source changed after the catalog scan. Scan again before building.');
        }
    }

    private function normaliseLogicalPath(string $path): string
    {
        $path = trim($this->slash($path));
        $path = ltrim($path, '/');
        if ($path === ''
            || str_contains($path, "\0")
            || preg_match('#(^|/)\.\.(/|$)#', $path)
            || preg_match('/[\x00-\x1F\x7F]/u', $path)) {
            throw new ValidationException('Unsafe Grav-relative image path.');
        }
        $segments = array_values(array_filter(explode('/', $path), static fn(string $part): bool => $part !== '' && $part !== '.'));
        if ($segments === []) {
            throw new ValidationException('Empty image path.');
        }
        return implode('/', $segments);
    }

    private function assertGd(): void
    {
        if (!extension_loaded('gd') || !function_exists('imagecreatetruecolor')) {
            throw new ValidationException('Image Foundry requires the PHP GD extension.');
        }
        $this->formats();
    }

    private function withLock(callable $callback): mixed
    {
        $handle = fopen($this->storage . '/catalog.lock', 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('Unable to acquire the Image Foundry catalog lock.');
        }
        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function isContained(string $path, string $base): bool
    {
        $path = rtrim($this->slash($path), '/');
        $base = rtrim($this->slash($base), '/');
        return $path === $base || str_starts_with($path . '/', $base . '/');
    }

    private function slash(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
