<?php

declare(strict_types=1);

namespace Grav\Plugin\RevisionLedger\Service;

use Grav\Common\Grav;

final class RevisionLedgerService
{
    private const FORMAT_VERSION = 1;

    private $grav;

    public function __construct()
    {
        $this->grav = Grav::instance();
    }

    public function status(): array
    {
        $revisions = $this->allRevisions();
        $routes = [];
        $bytes = 0;
        $latest = null;
        foreach ($revisions as $revision) {
            $routes[$revision['route']] = true;
            $bytes += (int) ($revision['bytes'] ?? 0);
            if ($latest === null || strcmp((string) $revision['created_at'], (string) $latest['created_at']) > 0) {
                $latest = $revision;
            }
        }
        return [
            'storage' => $this->storageDirectory(),
            'pages' => count($routes),
            'revisions' => count($revisions),
            'bytes' => $bytes,
            'latest' => $latest ? $this->summary($latest) : null,
            'retention' => $this->retentionConfig(),
        ];
    }

    public function pageCatalog(): array
    {
        $catalog = [];
        foreach ($this->allRevisions() as $revision) {
            $route = (string) $revision['route'];
            if (!isset($catalog[$route])) {
                $catalog[$route] = [
                    'route' => $route,
                    'title' => (string) ($revision['title'] ?: $route),
                    'count' => 0,
                    'latest_at' => null,
                ];
            }
            ++$catalog[$route]['count'];
            if ($catalog[$route]['latest_at'] === null || strcmp($revision['created_at'], $catalog[$route]['latest_at']) > 0) {
                $catalog[$route]['latest_at'] = $revision['created_at'];
                $catalog[$route]['title'] = (string) ($revision['title'] ?: $route);
            }
        }
        ksort($catalog, SORT_NATURAL | SORT_FLAG_CASE);
        return array_values($catalog);
    }

    public function revisions(?string $route = null): array
    {
        $items = array_map(fn (array $revision): array => $this->summary($revision), $this->allRevisions($route));
        usort($items, static fn (array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));
        return $items;
    }

    public function editorContext(string $adminPath): array
    {
        $needle = $this->normalizeRoute($adminPath);
        $pages = $this->grav['pages'];
        // The API request pipeline normally keeps Grav's public page tree
        // disabled. Editor helpers must explicitly opt back in before walking
        // routes (including modular children such as /home/_spitfire).
        if (method_exists($pages, 'enablePages')) {
            $pages->enablePages();
        } elseif (method_exists($pages, 'init')) {
            $pages->init();
        }
        foreach ($pages->all() as $page) {
            if (!is_object($page) || !method_exists($page, 'route')) {
                continue;
            }
            $route = $this->normalizeRoute((string) $page->route());
            $rawRoute = method_exists($page, 'rawRoute')
                ? $this->normalizeRoute((string) $page->rawRoute())
                : $route;
            if ($needle !== $route && $needle !== $rawRoute) {
                continue;
            }
            return [
                'route' => $route,
                'raw_route' => $rawRoute,
                'title' => method_exists($page, 'title') ? (string) $page->title() : $route,
                'count' => count($this->revisions($route)),
            ];
        }
        throw new \RuntimeException('The page editor route could not be resolved: ' . $needle);
    }

    public function revision(string $id): array
    {
        $revision = $this->findRevision($id);
        $revision['content'] = $this->decodeContent($revision);
        unset($revision['content_base64']);
        return $revision;
    }

    public function checkpointRoute(string $route, string $reason = '', string $source = 'manual', ?string $author = null): array
    {
        $page = $this->pageByRoute($route);
        if (!$page) {
            throw new \RuntimeException('Page not found: ' . $route);
        }
        $path = (string) $page->filePath();
        if ($path === '' || !is_file($path)) {
            throw new \RuntimeException('The page has no readable content file: ' . $route);
        }
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException('Unable to read page content: ' . $route);
        }
        return $this->checkpointContent(
            $route,
            (string) $page->title(),
            $path,
            $content,
            $reason,
            $source,
            $author
        );
    }

    public function checkpointPage(object $page, string $content, string $reason = '', string $source = 'admin-auto', ?string $author = null): array
    {
        return $this->checkpointContent(
            (string) $page->route(),
            (string) $page->title(),
            (string) $page->filePath(),
            $content,
            $reason,
            $source,
            $author
        );
    }

    public function checkpointContent(
        string $route,
        string $title,
        string $filePath,
        string $content,
        string $reason = '',
        string $source = 'manual',
        ?string $author = null
    ): array {
        $route = $this->normalizeRoute($route);
        $hash = hash('sha256', $content);
        $latest = $this->latestForRoute($route);
        if ($latest && hash_equals((string) $latest['sha256'], $hash)) {
            $latest['deduplicated'] = true;
            return $this->summary($latest);
        }
        $id = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $revision = [
            'format_version' => self::FORMAT_VERSION,
            'id' => $id,
            'route' => $route,
            'title' => trim($title),
            'page_file' => $this->relativePageFile($filePath),
            'created_at' => gmdate('c'),
            'timestamp' => time(),
            'author' => trim((string) ($author ?? $this->currentAuthor())) ?: 'System',
            'reason' => trim($reason),
            'source' => trim($source) ?: 'manual',
            'sha256' => $hash,
            'bytes' => strlen($content),
            'content_encoding' => 'base64',
            'content_base64' => base64_encode($content),
        ];
        $directory = $this->routeDirectory($route);
        $this->ensureDirectory($directory);
        $this->atomicWrite($directory . '/' . $id . '.json', json_encode($revision, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        $this->prune($route, true);
        return $this->summary($revision);
    }

    public function compare(string $id): array
    {
        $revision = $this->findRevision($id);
        $before = $this->decodeContent($revision);
        $page = $this->pageByRoute((string) $revision['route']);
        $path = $page ? (string) $page->filePath() : $this->absoluteStoredPageFile((string) $revision['page_file']);
        $after = is_file($path) ? (string) file_get_contents($path) : '';
        return [
            'revision' => $this->summary($revision),
            'snapshot' => $before,
            'current' => $after,
            'changed' => !hash_equals(hash('sha256', $before), hash('sha256', $after)),
            'unified' => $this->unifiedDiff($before, $after, 'snapshot/' . $id, 'current/' . ltrim($revision['route'], '/')),
        ];
    }

    public function restore(string $id, string $confirmation, ?string $author = null): array
    {
        if (!hash_equals('RESTORE PAGE', $confirmation)) {
            throw new \RuntimeException('The exact confirmation phrase RESTORE PAGE is required.');
        }
        $revision = $this->findRevision($id);
        $route = (string) $revision['route'];
        $page = $this->pageByRoute($route);
        $path = $page ? (string) $page->filePath() : $this->absoluteStoredPageFile((string) $revision['page_file']);
        $this->assertPagePath($path);
        if (!is_file($path)) {
            throw new \RuntimeException('The current page file no longer exists; automatic recreation is intentionally disabled.');
        }
        $current = file_get_contents($path);
        if ($current === false) {
            throw new \RuntimeException('Unable to read the current page before restore.');
        }
        $title = $page ? (string) $page->title() : (string) $revision['title'];
        $safety = $this->checkpointContent($route, $title, $path, $current, 'Automatic safety checkpoint before restoring ' . $id, 'pre-restore', $author);
        $this->atomicWrite($path, $this->decodeContent($revision));
        $this->clearCache();
        return [
            'restored' => $this->summary($revision),
            'safety_checkpoint' => $safety,
            'message' => 'Page restored. A safety checkpoint of the replaced content was retained.',
        ];
    }

    public function prune(?string $route = null, bool $execute = false): array
    {
        $config = $this->retentionConfig();
        $routes = $route ? [$this->normalizeRoute($route)] : array_column($this->pageCatalog(), 'route');
        $now = time();
        $candidates = [];
        foreach ($routes as $candidateRoute) {
            $items = $this->allRevisions($candidateRoute);
            usort($items, static fn (array $a, array $b): int => ((int) $b['timestamp']) <=> ((int) $a['timestamp']));
            foreach ($items as $index => $revision) {
                if ($index === 0) {
                    continue;
                }
                $manual = in_array((string) $revision['source'], ['manual', 'pre-restore', 'plugin'], true);
                if ($manual && $config['keep_manual']) {
                    continue;
                }
                $overCount = $config['max_per_page'] > 0 && $index >= $config['max_per_page'];
                $overAge = $config['max_age_days'] > 0 && (int) $revision['timestamp'] < $now - ($config['max_age_days'] * 86400);
                if (!$overCount && !$overAge) {
                    continue;
                }
                $path = (string) $revision['_path'];
                $candidates[] = $this->summary($revision);
                if ($execute && is_file($path) && !unlink($path)) {
                    throw new \RuntimeException('Unable to remove expired revision: ' . $revision['id']);
                }
            }
        }
        return ['executed' => $execute, 'count' => count($candidates), 'revisions' => $candidates];
    }

    private function allRevisions(?string $route = null): array
    {
        $base = $this->storageDirectory();
        if (!is_dir($base)) {
            return [];
        }
        $files = $route
            ? (glob($this->routeDirectory($this->normalizeRoute($route)) . '/*.json') ?: [])
            : (glob($base . '/pages/*/*.json') ?: []);
        $items = [];
        foreach ($files as $file) {
            try {
                $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($data) || !isset($data['id'], $data['route'], $data['sha256'], $data['content_base64'])) {
                    continue;
                }
                $data['_path'] = $file;
                $items[] = $data;
            } catch (\Throwable $e) {
                $this->grav['log']->warning('[Revision Ledger] Skipped malformed revision ' . $file . ': ' . $e->getMessage());
            }
        }
        return $items;
    }

    private function findRevision(string $id): array
    {
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $id)) {
            throw new \RuntimeException('Invalid revision identifier.');
        }
        foreach ($this->allRevisions() as $revision) {
            if (hash_equals((string) $revision['id'], $id)) {
                return $revision;
            }
        }
        throw new \RuntimeException('Revision not found: ' . $id);
    }

    private function latestForRoute(string $route): ?array
    {
        $items = $this->allRevisions($route);
        usort($items, static fn (array $a, array $b): int => ((int) $b['timestamp']) <=> ((int) $a['timestamp']));
        return $items[0] ?? null;
    }

    private function summary(array $revision): array
    {
        return array_intersect_key($revision, array_flip([
            'id', 'route', 'title', 'page_file', 'created_at', 'timestamp', 'author', 'reason', 'source', 'sha256', 'bytes', 'deduplicated',
        ]));
    }

    private function decodeContent(array $revision): string
    {
        $content = base64_decode((string) $revision['content_base64'], true);
        if ($content === false || !hash_equals((string) $revision['sha256'], hash('sha256', $content))) {
            throw new \RuntimeException('Revision content failed its SHA-256 integrity check.');
        }
        return $content;
    }

    private function pageByRoute(string $route): ?object
    {
        $pages = $this->grav['pages'];
        if (method_exists($pages, 'enablePages')) {
            $pages->enablePages();
        }
        if (method_exists($pages, 'init')) {
            $pages->init();
        }
        $page = $pages->find($this->normalizeRoute($route));
        return is_object($page) ? $page : null;
    }

    private function currentAuthor(): string
    {
        $user = $this->grav['user'] ?? null;
        if (!$user) {
            return 'System';
        }
        foreach (['fullname', 'username', 'email'] as $key) {
            $value = method_exists($user, 'get') ? trim((string) $user->get($key)) : '';
            if ($value !== '') {
                return $value;
            }
        }
        return 'System';
    }

    private function storageDirectory(): string
    {
        $configured = trim((string) $this->grav['config']->get('plugins.revision-ledger.storage.directory', '../revision-ledger-data'));
        $path = str_starts_with($configured, '/') ? $configured : GRAV_ROOT . '/' . $configured;
        $path = $this->normalizeFilesystemPath($path);
        if ((bool) $this->grav['config']->get('plugins.revision-ledger.storage.require_outside_root', true)
            && ($path === GRAV_ROOT || str_starts_with($path . '/', rtrim(GRAV_ROOT, '/') . '/'))) {
            throw new \RuntimeException('Revision Ledger storage must resolve outside the public Grav root.');
        }
        $this->ensureDirectory($path);
        return $path;
    }

    private function routeDirectory(string $route): string
    {
        return $this->storageDirectory() . '/pages/' . hash('sha256', $route);
    }

    private function relativePageFile(string $path): string
    {
        $this->assertPagePath($path);
        return ltrim(substr($this->normalizeFilesystemPath($path), strlen(rtrim(GRAV_ROOT, '/'))), '/');
    }

    private function absoluteStoredPageFile(string $relative): string
    {
        if ($relative === '' || str_contains($relative, '..')) {
            throw new \RuntimeException('Stored page path is unsafe.');
        }
        $path = $this->normalizeFilesystemPath(GRAV_ROOT . '/' . ltrim($relative, '/'));
        $this->assertPagePath($path);
        return $path;
    }

    private function assertPagePath(string $path): void
    {
        $normalized = $this->normalizeFilesystemPath($path);
        $pagesRoot = $this->normalizeFilesystemPath(GRAV_ROOT . '/user/pages');
        if (!str_starts_with($normalized . '/', rtrim($pagesRoot, '/') . '/')) {
            throw new \RuntimeException('Revision target is outside user/pages.');
        }
    }

    private function normalizeRoute(string $route): string
    {
        $route = '/' . trim(rawurldecode($route), '/');
        if ($route !== '/' && preg_match('#(?:^|/)\.\.(?:/|$)#', $route)) {
            throw new \RuntimeException('Invalid page route.');
        }
        return $route;
    }

    private function normalizeFilesystemPath(string $path): string
    {
        $absolute = str_starts_with($path, '/');
        $parts = [];
        foreach (preg_split('#/+#', str_replace('\\', '/', $path)) ?: [] as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
            } else {
                $parts[] = $part;
            }
        }
        return ($absolute ? '/' : '') . implode('/', $parts);
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create Revision Ledger directory: ' . $directory);
        }
        @chmod($directory, 0700);
    }

    private function atomicWrite(string $path, string $content): void
    {
        $this->ensureDirectory(dirname($path));
        $temp = $path . '.tmp-' . bin2hex(random_bytes(4));
        if (file_put_contents($temp, $content, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write temporary revision data.');
        }
        @chmod($temp, 0600);
        if (!rename($temp, $path)) {
            @unlink($temp);
            throw new \RuntimeException('Unable to atomically publish revision data.');
        }
        @chmod($path, 0600);
    }

    private function clearCache(): void
    {
        try {
            if (isset($this->grav['cache']) && method_exists($this->grav['cache'], 'clearCache')) {
                $this->grav['cache']->clearCache('all');
            }
        } catch (\Throwable $e) {
            $this->grav['log']->warning('[Revision Ledger] Restore succeeded but cache clearing failed: ' . $e->getMessage());
        }
    }

    private function retentionConfig(): array
    {
        return [
            'max_per_page' => max(0, (int) $this->grav['config']->get('plugins.revision-ledger.retention.max_per_page', 50)),
            'max_age_days' => max(0, (int) $this->grav['config']->get('plugins.revision-ledger.retention.max_age_days', 365)),
            'keep_manual' => (bool) $this->grav['config']->get('plugins.revision-ledger.retention.keep_manual', true),
        ];
    }

    private function unifiedDiff(string $old, string $new, string $oldName, string $newName): string
    {
        if ($old === $new) {
            return "--- {$oldName}\n+++ {$newName}\n";
        }
        if (function_exists('xdiff_string_diff')) {
            $diff = xdiff_string_diff($old, $new, 3);
            if (is_string($diff)) {
                return "--- {$oldName}\n+++ {$newName}\n" . $diff;
            }
        }
        $a = preg_split('/\R/', $old) ?: [];
        $b = preg_split('/\R/', $new) ?: [];
        $prefix = 0;
        while (isset($a[$prefix], $b[$prefix]) && $a[$prefix] === $b[$prefix]) {
            ++$prefix;
        }
        $suffix = 0;
        while ($suffix < count($a) - $prefix && $suffix < count($b) - $prefix
            && $a[count($a) - 1 - $suffix] === $b[count($b) - 1 - $suffix]) {
            ++$suffix;
        }
        $contextStart = max(0, $prefix - 3);
        $oldEnd = count($a) - $suffix;
        $newEnd = count($b) - $suffix;
        $lines = ["--- {$oldName}", "+++ {$newName}", sprintf('@@ -%d,%d +%d,%d @@', $contextStart + 1, max(0, $oldEnd - $contextStart), $contextStart + 1, max(0, $newEnd - $contextStart))];
        for ($i = $contextStart; $i < $prefix; ++$i) {
            $lines[] = ' ' . $a[$i];
        }
        for ($i = $prefix; $i < $oldEnd; ++$i) {
            $lines[] = '-' . $a[$i];
        }
        for ($i = $prefix; $i < $newEnd; ++$i) {
            $lines[] = '+' . $b[$i];
        }
        $contextEnd = min(count($a), $oldEnd + 3);
        for ($i = $oldEnd; $i < $contextEnd; ++$i) {
            $lines[] = ' ' . $a[$i];
        }
        return implode("\n", $lines) . "\n";
    }
}
