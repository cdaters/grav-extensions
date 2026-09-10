<?php

declare(strict_types=1);

namespace Grav\Plugin\LanternSearch\Service;

use Grav\Common\Grav;
use RocketTheme\Toolbox\Event\Event;

final class SearchIndexService
{
    private const SCHEMA = 1;

    private Grav $grav;
    private array $config;

    public function __construct()
    {
        $this->grav = Grav::instance();
        $this->config = (array) $this->grav['config']->get('plugins.lantern-search', []);
    }

    public function status(): array
    {
        $index = $this->readIndex();
        return [
            'version' => '0.1.4',
            'indexed_pages' => count((array) ($index['documents'] ?? [])),
            'built_at' => $index['built_at'] ?? null,
            'dirty' => is_file($this->dirtyFile()),
            'storage' => $this->storageDirectory(),
            'index_size' => is_file($this->indexFile()) ? (int) filesize($this->indexFile()) : 0,
            'query_route' => '/' . trim((string) $this->value('route', '/lantern-search/query'), '/'),
            'features' => ['weighted ranking', 'facets', 'prefix matching', 'typo tolerance', 'incremental indexing'],
        ];
    }

    public function markDirty(string $reason = 'content changed'): void
    {
        $this->ensureStorage();
        file_put_contents($this->dirtyFile(), gmdate(DATE_ATOM) . ' ' . $reason . "\n", LOCK_EX);
    }

    public function build(bool $force = false): array
    {
        $this->ensureStorage();
        $lock = fopen($this->storageDirectory() . '/index.lock', 'c+');
        if (!$lock || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Unable to lock the Lantern Search index.');
        }

        try {
            $old = $force ? [] : (array) ($this->readIndex()['documents'] ?? []);
            $documents = [];
            $updated = 0;
            $reused = 0;
            $excluded = 0;

            foreach ($this->pages() as $page) {
                if (!$this->isIndexable($page)) {
                    $excluded++;
                    continue;
                }
                $route = $this->pageRoute($page);
                $hash = $this->sourceHash($page);
                if (!$force && isset($old[$route]) && ($old[$route]['source_hash'] ?? '') === $hash) {
                    $documents[$route] = $old[$route];
                    $reused++;
                    continue;
                }

                $document = $this->documentFromPage($page, $hash);
                $event = new Event(['page' => $page, 'document' => $document, 'include' => true]);
                $this->grav->fireEvent('onLanternSearchIndexPage', $event);
                if (($event['include'] ?? true) === false) {
                    $excluded++;
                    continue;
                }
                $candidate = $event['document'] ?? $document;
                if (is_array($candidate)) {
                    $documents[$route] = $candidate;
                    $updated++;
                }
            }

            ksort($documents, SORT_NATURAL | SORT_FLAG_CASE);
            $payload = [
                'schema' => self::SCHEMA,
                'built_at' => gmdate(DATE_ATOM),
                'documents' => $documents,
            ];
            $this->writeIndex($payload);
            @unlink($this->dirtyFile());

            return [
                'indexed_pages' => count($documents),
                'updated' => $updated,
                'reused' => $reused,
                'removed' => count(array_diff_key($old, $documents)),
                'excluded' => $excluded,
                'built_at' => $payload['built_at'],
            ];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function search(string $query, array $filters = [], ?int $requestedLimit = null): array
    {
        $query = trim(preg_replace('/\s+/u', ' ', $query) ?? $query);
        $minimum = (int) $this->value('search.minimum_length', 2);
        $maximum = (int) $this->value('search.maximum_length', 120);
        if (mb_strlen($query) < $minimum) {
            return ['query' => $query, 'total' => 0, 'results' => [], 'facets' => [], 'message' => "Enter at least {$minimum} characters."];
        }
        if (mb_strlen($query) > $maximum) {
            $query = mb_substr($query, 0, $maximum);
        }

        if (!is_file($this->indexFile()) || (is_file($this->dirtyFile()) && (bool) $this->value('index.auto_refresh', true))) {
            $this->build(false);
        }
        $index = $this->readIndex();
        $tokens = $this->tokenize($query);
        $operator = (string) $this->value('search.operator', 'all');
        $weights = (array) $this->value('search.weights', []);
        $matches = [];

        foreach ((array) ($index['documents'] ?? []) as $document) {
            if (!$this->matchesFilters($document, $filters)) {
                continue;
            }
            $score = 0.0;
            $matchedTokens = 0;
            foreach ($tokens as $token) {
                $tokenScore = 0.0;
                foreach (['title', 'taxonomy', 'description', 'content'] as $field) {
                    $fieldWeight = (float) ($weights[$field] ?? ['title' => 8, 'taxonomy' => 5, 'description' => 4, 'content' => 1][$field]);
                    $tokenScore += $fieldWeight * $this->bestTermScore($token, (array) ($document['terms'][$field] ?? []));
                }
                if ($tokenScore > 0) {
                    $matchedTokens++;
                    $score += $tokenScore;
                }
            }
            if (($operator === 'all' && $matchedTokens < count($tokens)) || ($operator !== 'all' && $matchedTokens === 0)) {
                continue;
            }
            $needle = $this->normalize($query);
            if ($needle !== '' && str_contains($this->normalize((string) ($document['title'] ?? '')), $needle)) {
                $score += 24;
            } elseif ($needle !== '' && str_contains($this->normalize((string) ($document['description'] ?? '')), $needle)) {
                $score += 10;
            }
            $score *= max(0.1, (float) ($document['boost'] ?? 1));
            $document['score'] = round($score, 3);
            $document['excerpt'] = $this->excerpt((string) (($document['description'] ?? '') ?: ($document['content'] ?? '')), $tokens);
            unset($document['terms'], $document['content'], $document['source_hash'], $document['boost']);
            $matches[] = $document;
        }

        usort($matches, static fn (array $a, array $b): int => ($b['score'] <=> $a['score']) ?: strcasecmp((string) $a['title'], (string) $b['title']));
        $facets = $this->facets($matches);
        $total = count($matches);
        $limit = min(
            max(1, $requestedLimit ?? (int) $this->value('search.default_limit', 12)),
            (int) $this->value('search.maximum_limit', 30)
        );
        $result = [
            'query' => $query,
            'total' => $total,
            'results' => array_slice($matches, 0, $limit),
            'facets' => $facets,
            'built_at' => $index['built_at'] ?? null,
        ];
        $event = new Event(['query' => $query, 'filters' => $filters, 'result' => $result]);
        $this->grav->fireEvent('onLanternSearchResults', $event);
        return is_array($event['result'] ?? null) ? $event['result'] : $result;
    }

    private function documentFromPage(object $page, string $hash): array
    {
        $header = $this->pageHeader($page);
        $title = trim((string) (method_exists($page, 'title') ? $page->title() : ($header['title'] ?? '')));
        $description = trim((string) ($header['meta_pilot']['description'] ?? $header['metadata']['description'] ?? $header['description'] ?? ''));
        $content = $this->plainText($page);
        if ($description === '') {
            $description = mb_substr($content, 0, 220);
        }
        $taxonomy = $this->taxonomy($header);
        $language = method_exists($page, 'language') ? (string) $page->language() : '';
        $template = method_exists($page, 'template') ? (string) $page->template() : '';
        $boost = max(0.1, min(10, (float) ($header['lantern_search']['boost'] ?? 1)));

        return [
            'route' => $this->pageRoute($page),
            'url' => $this->pageRoute($page),
            'title' => $title,
            'description' => $description,
            'content' => mb_substr($content, 0, 120000),
            'categories' => $taxonomy['category'],
            'tags' => $taxonomy['tag'],
            'language' => $language,
            'template' => $template,
            'modified' => method_exists($page, 'modified') ? (int) $page->modified() : 0,
            'boost' => $boost,
            'source_hash' => $hash,
            'terms' => [
                'title' => $this->termFrequency($title),
                'taxonomy' => $this->termFrequency(implode(' ', [...$taxonomy['category'], ...$taxonomy['tag']])),
                'description' => $this->termFrequency($description),
                'content' => $this->termFrequency($content),
            ],
        ];
    }

    private function isIndexable(object $page): bool
    {
        if ((method_exists($page, 'published') && !$page->published())
            || (method_exists($page, 'routable') && !$page->routable())
            || (method_exists($page, 'modular') && $page->modular())) {
            return false;
        }
        $route = $this->pageRoute($page);
        if ($route === '') {
            return false;
        }
        foreach ((array) $this->value('index.exclude_routes', []) as $excluded) {
            $excluded = '/' . trim((string) $excluded, '/');
            if ($excluded !== '/' && ($route === $excluded || str_starts_with($route, $excluded . '/'))) {
                return false;
            }
        }
        $header = $this->pageHeader($page);
        if (isset($header['lantern_search']['enabled']) && !(bool) $header['lantern_search']['enabled']) {
            return false;
        }
        if ((bool) $this->value('index.exclude_access_protected', true) && $this->hasAccessRules($header['access'] ?? [])) {
            return false;
        }
        if ((bool) $this->value('index.exclude_noindex', true) && $this->isNoIndex($header)) {
            return false;
        }
        return true;
    }

    private function hasAccessRules(mixed $access): bool
    {
        if (is_object($access) && method_exists($access, 'toArray')) {
            $access = $access->toArray();
        }
        if (!is_array($access)) {
            return trim((string) $access) !== '';
        }
        foreach ($access as $value) {
            if ($this->hasAccessRules($value)) {
                return true;
            }
        }
        return false;
    }

    private function isNoIndex(array $header): bool
    {
        $values = [
            $header['robots'] ?? '',
            $header['metadata']['robots'] ?? '',
            $header['meta_pilot']['robots'] ?? '',
        ];
        foreach ($values as $value) {
            if (is_array($value)) {
                $value = implode(',', $value);
            }
            if (stripos((string) $value, 'noindex') !== false) {
                return true;
            }
        }
        return false;
    }

    private function bestTermScore(string $query, array $terms): float
    {
        if (isset($terms[$query])) {
            return 1 + log(1 + (int) $terms[$query]);
        }
        $best = 0.0;
        foreach ($terms as $term => $frequency) {
            if (mb_strlen($query) >= 3 && str_starts_with((string) $term, $query)) {
                $best = max($best, 0.65 * (1 + log(1 + (int) $frequency)));
                continue;
            }
            if (!(bool) $this->value('search.fuzzy', true) || abs(strlen((string) $term) - strlen($query)) > 2 || mb_substr((string) $term, 0, 1) !== mb_substr($query, 0, 1)) {
                continue;
            }
            $distance = $this->editDistance($query, (string) $term);
            $tolerance = (int) $this->value('search.typo_tolerance', 1);
            if ($distance <= $tolerance) {
                $best = max($best, (0.48 - ($distance * 0.08)) * (1 + log(1 + (int) $frequency)));
            }
        }
        return $best;
    }

    private function editDistance(string $left, string $right): int
    {
        $distance = levenshtein($left, $right);
        if ($distance <= 1 || strlen($left) !== strlen($right)) {
            return $distance;
        }
        $length = strlen($left);
        for ($index = 0; $index < $length - 1; $index++) {
            if ($left[$index] !== $right[$index] && $left[$index] === $right[$index + 1] && $left[$index + 1] === $right[$index]) {
                $candidate = substr($left, 0, $index) . $left[$index + 1] . $left[$index] . substr($left, $index + 2);
                if ($candidate === $right) {
                    return 1;
                }
            }
        }
        return $distance;
    }

    private function matchesFilters(array $document, array $filters): bool
    {
        foreach (['category' => 'categories', 'tag' => 'tags'] as $filter => $field) {
            $needle = $this->normalize((string) ($filters[$filter] ?? ''));
            if ($needle !== '' && !in_array($needle, array_map(fn ($v) => $this->normalize((string) $v), (array) ($document[$field] ?? [])), true)) {
                return false;
            }
        }
        foreach (['language', 'template'] as $filter) {
            $needle = $this->normalize((string) ($filters[$filter] ?? ''));
            if ($needle !== '' && $needle !== $this->normalize((string) ($document[$filter] ?? ''))) {
                return false;
            }
        }
        return true;
    }

    private function facets(array $matches): array
    {
        $facets = ['category' => [], 'tag' => [], 'language' => [], 'template' => []];
        foreach ($matches as $document) {
            foreach (['category' => 'categories', 'tag' => 'tags'] as $facet => $field) {
                foreach ((array) ($document[$field] ?? []) as $value) {
                    $value = trim((string) $value);
                    if ($value !== '') $facets[$facet][$value] = ($facets[$facet][$value] ?? 0) + 1;
                }
            }
            foreach (['language', 'template'] as $facet) {
                $value = trim((string) ($document[$facet] ?? ''));
                if ($value !== '') $facets[$facet][$value] = ($facets[$facet][$value] ?? 0) + 1;
            }
        }
        foreach ($facets as &$values) arsort($values);
        return $facets;
    }

    private function excerpt(string $text, array $tokens): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        $position = null;
        $lower = mb_strtolower($text);
        foreach ($tokens as $token) {
            $candidate = mb_stripos($lower, $token);
            if ($candidate !== false && ($position === null || $candidate < $position)) $position = $candidate;
        }
        $start = max(0, (int) ($position ?? 0) - 80);
        $snippet = mb_substr($text, $start, 240);
        return ($start > 0 ? '…' : '') . $snippet . (mb_strlen($text) > $start + 240 ? '…' : '');
    }

    private function termFrequency(string $text): array
    {
        $frequency = [];
        foreach ($this->tokenize($text) as $term) $frequency[$term] = ($frequency[$term] ?? 0) + 1;
        return $frequency;
    }

    private function tokenize(string $text): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $this->normalize($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_filter($parts, static fn (string $term): bool => mb_strlen($term) >= 2 || ctype_digit($term)));
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        if (class_exists('Normalizer')) $text = \Normalizer::normalize($text, \Normalizer::FORM_KC) ?: $text;
        return $text;
    }

    private function taxonomy(array $header): array
    {
        $taxonomy = $header['taxonomy'] ?? [];
        if (is_object($taxonomy) && method_exists($taxonomy, 'toArray')) $taxonomy = $taxonomy->toArray();
        $result = ['category' => [], 'tag' => []];
        foreach (['category', 'tag'] as $key) {
            $value = is_array($taxonomy) ? ($taxonomy[$key] ?? $taxonomy[$key . 's'] ?? []) : [];
            $result[$key] = array_values(array_filter(array_map('strval', is_array($value) ? $value : [$value])));
        }
        return $result;
    }

    private function plainText(object $page): string
    {
        $content = method_exists($page, 'rawMarkdown') ? (string) $page->rawMarkdown() : (method_exists($page, 'content') ? (string) $page->content() : '');
        // Fenced blocks on public archive pages contain searchable primary
        // sources. Remove the Markdown fence markers without discarding the
        // transcript or other readable preformatted content inside them.
        $content = preg_replace('/```[^\n]*\n|```/u', ' ', $content) ?? $content;
        $content = preg_replace('/\[([^\]]+)\]\([^\)]+\)/u', '$1', $content) ?? $content;
        $content = preg_replace('/\[(?:file-vault|file-download|prism|lightbox)[^\]]*\](?:.*?\[\/(?:file-vault|file-download|prism|lightbox)\])?/isu', ' ', $content) ?? $content;
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? $content);
    }

    private function sourceHash(object $page): string
    {
        $header = $this->pageHeader($page);
        return hash('sha256', json_encode([
            $this->pageRoute($page),
            method_exists($page, 'title') ? $page->title() : '',
            method_exists($page, 'rawMarkdown') ? $page->rawMarkdown() : '',
            $header['taxonomy'] ?? [], $header['description'] ?? '', $header['metadata'] ?? [],
            $header['meta_pilot'] ?? [], $header['lantern_search'] ?? [], $header['access'] ?? [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    private function pages(): array
    {
        $pages = $this->grav['pages'] ?? null;
        if (!$pages || !method_exists($pages, 'all')) return [];
        if (method_exists($pages, 'enablePages')) $pages->enablePages();
        if (method_exists($pages, 'init')) $pages->init();
        $all = $pages->all();
        return is_array($all) ? array_values($all) : iterator_to_array($all);
    }

    private function pageRoute(object $page): string
    {
        $route = method_exists($page, 'route') ? (string) $page->route() : '';
        return $route === '' ? '' : '/' . trim($route, '/');
    }

    private function pageHeader(object $page): array
    {
        $header = method_exists($page, 'header') ? $page->header() : [];
        $header = $this->normalArray($header);
        return is_array($header) ? $header : [];
    }

    private function normalArray(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = method_exists($value, 'toArray') ? $value->toArray() : get_object_vars($value);
        }
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->normalArray($item);
        }
        return $value;
    }

    private function storageDirectory(): string
    {
        $resource = (string) $this->value('index.storage', 'user://data/lantern-search');
        $path = $this->grav['locator']->findResource($resource, true, true);
        if (is_string($path) && $path !== '') return rtrim($path, '/');
        return rtrim(GRAV_ROOT, '/') . '/user/data/lantern-search';
    }

    private function ensureStorage(): void
    {
        $directory = $this->storageDirectory();
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) throw new \RuntimeException('Unable to create Lantern Search storage.');
    }

    private function indexFile(): string { return $this->storageDirectory() . '/index.json'; }
    private function dirtyFile(): string { return $this->storageDirectory() . '/.dirty'; }

    private function readIndex(): array
    {
        if (!is_file($this->indexFile())) return [];
        $decoded = json_decode((string) file_get_contents($this->indexFile()), true);
        return is_array($decoded) && ($decoded['schema'] ?? null) === self::SCHEMA ? $decoded : [];
    }

    private function writeIndex(array $payload): void
    {
        $temporary = $this->indexFile() . '.tmp-' . bin2hex(random_bytes(4));
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false || file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $this->indexFile())) {
            @unlink($temporary);
            throw new \RuntimeException('Unable to write the Lantern Search index.');
        }
        @chmod($this->indexFile(), 0640);
    }

    private function value(string $path, mixed $default = null): mixed
    {
        $value = $this->config;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) return $default;
            $value = $value[$segment];
        }
        return $value;
    }
}
