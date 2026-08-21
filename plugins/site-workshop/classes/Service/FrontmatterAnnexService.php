<?php

declare(strict_types=1);

namespace Grav\Plugin\SiteWorkshop\Service;

use Grav\Common\Grav;
use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class FrontmatterAnnexService
{
    private Grav $grav;
    private array $appliedPages = [];

    public function __construct(?Grav $grav = null)
    {
        $this->grav = $grav ?? Grav::instance();
    }

    public function enabled(): bool
    {
        return (bool) $this->config('modules.frontmatter_annex.enabled', true);
    }

    public function status(): array
    {
        return [
            'enabled' => $this->enabled(),
            'annex_count' => count($this->list()),
            'storage_path' => (string) $this->config('modules.frontmatter_annex.storage_path', 'user://data/site-workshop/frontmatter'),
            'default_precedence' => $this->normalizePrecedence((string) $this->config('modules.frontmatter_annex.default_precedence', 'page')),
        ];
    }

    public function list(): array
    {
        $directory = $this->directory(false);
        if (!is_dir($directory)) {
            return [];
        }

        $items = [];
        foreach (glob($directory . '/*.yaml') ?: [] as $file) {
            try {
                $yaml = (string) file_get_contents($file);
                $data = $this->parseDocument($yaml);
                $items[] = [
                    'slug' => basename($file, '.yaml'),
                    'yaml' => $yaml,
                    'data' => $data,
                    'modified' => filemtime($file) ?: null,
                    'bytes' => filesize($file) ?: 0,
                ];
            } catch (\Throwable $e) {
                $items[] = [
                    'slug' => basename($file, '.yaml'),
                    'yaml' => (string) @file_get_contents($file),
                    'data' => [],
                    'modified' => filemtime($file) ?: null,
                    'bytes' => filesize($file) ?: 0,
                    'error' => $e->getMessage(),
                ];
            }
        }

        usort($items, static fn (array $a, array $b): int => strnatcasecmp($a['slug'], $b['slug']));
        return $items;
    }

    public function save(array $input): array
    {
        if (!$this->enabled()) {
            throw new RuntimeException('Frontmatter Annex is disabled.');
        }

        $slug = $this->normalizeSlug((string) ($input['slug'] ?? ''));
        $yaml = trim((string) ($input['yaml'] ?? ''));
        if ($yaml === '') {
            throw new RuntimeException('Annex YAML cannot be empty.');
        }

        $data = $this->parseDocument($yaml);
        if (array_key_exists('site_workshop', $data)) {
            throw new RuntimeException('Annex documents cannot define the reserved site_workshop key.');
        }

        $directory = $this->directory(true);
        $path = $directory . '/' . $slug . '.yaml';
        $normalized = rtrim(Yaml::dump($data, 8, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK)) . "\n";
        $temporary = tempnam($directory, '.annex-');
        if ($temporary === false || file_put_contents($temporary, $normalized, LOCK_EX) === false || !rename($temporary, $path)) {
            if (is_string($temporary) && is_file($temporary)) {
                @unlink($temporary);
            }
            throw new RuntimeException('Unable to save the annex document.');
        }

        return ['saved' => true, 'annex' => $this->find($slug)];
    }

    public function delete(string $slug): array
    {
        $slug = $this->normalizeSlug($slug);
        $path = $this->directory(false) . '/' . $slug . '.yaml';
        if (!is_file($path)) {
            throw new RuntimeException('Annex not found: ' . $slug);
        }
        if (!unlink($path)) {
            throw new RuntimeException('Unable to delete the annex document.');
        }
        return ['deleted' => true, 'slug' => $slug];
    }

    public function applyToPage(object $page): bool
    {
        if (!$this->enabled() || !method_exists($page, 'header')) {
            return false;
        }

        $id = spl_object_id($page);
        if (isset($this->appliedPages[$id])) {
            return false;
        }
        $this->appliedPages[$id] = true;

        $header = $this->toArray($page->header());
        $control = $this->toArray($header['site_workshop'] ?? []);
        $slugs = $control['annexes'] ?? [];
        if (is_string($slugs)) {
            $slugs = [$slugs];
        }
        if (!is_array($slugs) || $slugs === []) {
            return false;
        }

        $annexHeader = [];
        foreach ($slugs as $slug) {
            try {
                $annexHeader = $this->merge($annexHeader, $this->load((string) $slug));
            } catch (\Throwable $e) {
                $this->logWarning('Frontmatter Annex skipped "' . (string) $slug . '": ' . $e->getMessage());
            }
        }
        if ($annexHeader === []) {
            return false;
        }

        $precedence = $this->normalizePrecedence((string) ($control['precedence'] ?? $this->config('modules.frontmatter_annex.default_precedence', 'page')));
        $merged = $precedence === 'annex'
            ? $this->merge($header, $annexHeader)
            : $this->merge($annexHeader, $header);
        $merged['site_workshop'] = $control;
        $page->header($merged);
        return true;
    }

    public function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (isset($base[$key]) && is_array($base[$key]) && is_array($value)
                && !array_is_list($base[$key]) && !array_is_list($value)) {
                $base[$key] = $this->merge($base[$key], $value);
                continue;
            }
            $base[$key] = $value;
        }
        return $base;
    }

    private function find(string $slug): array
    {
        foreach ($this->list() as $item) {
            if (($item['slug'] ?? '') === $slug) {
                return $item;
            }
        }
        throw new RuntimeException('Saved annex could not be reloaded.');
    }

    private function load(string $slug): array
    {
        $slug = $this->normalizeSlug($slug);
        $path = $this->directory(false) . '/' . $slug . '.yaml';
        if (!is_file($path)) {
            throw new RuntimeException('Annex not found: ' . $slug);
        }
        return $this->parseDocument((string) file_get_contents($path));
    }

    private function parseDocument(string $yaml): array
    {
        try {
            $data = Yaml::parse($yaml);
        } catch (ParseException $e) {
            throw new RuntimeException('Invalid YAML: ' . $e->getMessage(), 0, $e);
        }
        if ($data === null) {
            return [];
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new RuntimeException('An annex must be a YAML mapping at its root.');
        }
        return $this->toArray($data);
    }

    private function normalizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            throw new RuntimeException('Annex names must use lowercase kebab-case.');
        }
        return $slug;
    }

    private function normalizePrecedence(string $precedence): string
    {
        return strtolower($precedence) === 'annex' ? 'annex' : 'page';
    }

    private function directory(bool $create): string
    {
        $locator = $this->grav['locator'];
        $path = trim((string) $this->config('modules.frontmatter_annex.storage_path', 'user://data/site-workshop/frontmatter'));
        if (!str_starts_with($path, 'user://data') || str_contains($path, '\\')) {
            throw new RuntimeException('Frontmatter Annex storage must remain inside user://data.');
        }

        $relative = ltrim(substr($path, strlen('user://data')), '/');
        $segments = $relative === '' ? [] : explode('/', $relative);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('Frontmatter Annex storage contains an unsafe path segment.');
            }
        }

        $root = (string) $locator->findResource('user://data', true, true);
        if ($root === '') {
            throw new RuntimeException('Unable to resolve Frontmatter Annex storage.');
        }
        $directory = rtrim($root, '/') . ($relative === '' ? '' : '/' . $relative);
        if ($create && !is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create Frontmatter Annex storage.');
        }
        return rtrim($directory, '/');
    }

    private function toArray(mixed $value): array
    {
        if (is_object($value)) {
            $value = method_exists($value, 'toArray') ? $value->toArray() : get_object_vars($value);
        }
        if (!is_array($value)) {
            return [];
        }
        foreach ($value as $key => $item) {
            if (is_object($item)) {
                $value[$key] = $this->toArray($item);
            } elseif (is_array($item)) {
                $value[$key] = array_is_list($item)
                    ? array_map(fn (mixed $entry): mixed => is_array($entry) || is_object($entry) ? $this->toArray($entry) : $entry, $item)
                    : $this->toArray($item);
            }
        }
        return $value;
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return $this->grav['config']->get('plugins.site-workshop.' . $key, $default);
    }

    private function logWarning(string $message): void
    {
        $log = $this->grav['log'] ?? null;
        if (is_object($log) && method_exists($log, 'warning')) {
            $log->warning('[Site Workshop] ' . $message);
        }
    }
}
