<?php

declare(strict_types=1);

namespace Grav\Plugin\MetaPilot\Service;

use Grav\Common\Grav;
use Grav\Common\Utils;

final class MetaPilotService
{
    private const VERSION = '0.2.2';

    private Grav $grav;
    private array $config;

    public function __construct()
    {
        $this->grav = Grav::instance();
        $this->config = (array) $this->grav['config']->get('plugins.meta-pilot', []);
    }

    public function status(): array
    {
        $report = $this->report();
        return [
            'version' => self::VERSION,
            'site_name' => $this->siteName(),
            'sitemap_enabled' => (bool) $this->value('sitemap.enabled', true),
            'sitemap_url' => $this->absoluteUrl((string) $this->value('sitemap.route', '/sitemap.xml')),
            'robots_enabled' => (bool) $this->value('robots.enabled', true),
            'robots_url' => $this->absoluteUrl((string) $this->value('robots.route', '/robots.txt')),
            'features' => [
                'canonical' => (bool) $this->value('metadata.canonical', true),
                'robots' => (bool) $this->value('metadata.robots', true),
                'open_graph' => (bool) $this->value('metadata.open_graph', true),
                'twitter_cards' => (bool) $this->value('metadata.twitter_cards', true),
                'json_ld' => (bool) $this->value('metadata.json_ld', true),
            ],
            'summary' => $report['summary'],
        ];
    }

    public function report(): array
    {
        $rows = [];
        $buckets = ['title' => [], 'description' => [], 'canonical' => []];
        foreach ($this->pages() as $page) {
            if (!$this->isPublicPage($page, false)) {
                continue;
            }
            $meta = $this->metadataForPage($page);
            if (!$meta['enabled']) {
                continue;
            }
            $issues = [];
            $titleLength = mb_strlen($meta['title']);
            $descriptionLength = (int) $meta['description_source_length'];
            if ($titleLength === 0) {
                $issues[] = $this->issue('missing-title', 'error', 'No page title is available.');
            } elseif ($titleLength > 65) {
                $issues[] = $this->issue('long-title', 'warning', 'The page title is longer than 65 characters.');
            }
            if ($descriptionLength === 0) {
                $issues[] = $this->issue('missing-description', 'error', 'No description or usable page text is available.');
            } elseif ($descriptionLength < 50) {
                $issues[] = $this->issue('short-description', 'warning', 'The description is shorter than 50 characters.');
            } elseif ($descriptionLength > 170) {
                $issues[] = $this->issue('long-description', 'warning', 'The description is longer than 170 characters.');
            }
            if ($meta['image'] === '') {
                $issues[] = $this->issue('missing-image', 'warning', 'No page or default social image was found.');
            }
            if (str_contains(strtolower($meta['robots']), 'noindex')) {
                $issues[] = $this->issue('noindex', 'info', 'This page is intentionally marked noindex.');
            }

            $row = [
                'route' => $this->pageRoute($page),
                'title' => $meta['title'],
                'description' => $meta['description'],
                'description_length' => $descriptionLength,
                'canonical' => $meta['canonical'],
                'image' => $meta['image'],
                'robots' => $meta['robots'],
                'schema_type' => $meta['schema_type'],
                'issues' => $issues,
            ];
            $rows[] = $row;
            foreach (array_keys($buckets) as $field) {
                $key = mb_strtolower(trim((string) $row[$field]));
                if ($key !== '') {
                    $buckets[$field][$key][] = count($rows) - 1;
                }
            }
        }

        foreach ($buckets as $field => $values) {
            foreach ($values as $indexes) {
                if (count($indexes) < 2) {
                    continue;
                }
                foreach ($indexes as $index) {
                    $rows[$index]['issues'][] = $this->issue(
                        'duplicate-' . $field,
                        'warning',
                        ucfirst($field) . ' is shared by ' . count($indexes) . ' public pages.'
                    );
                }
            }
        }

        $counts = ['error' => 0, 'warning' => 0, 'info' => 0];
        foreach ($rows as &$row) {
            $penalty = 0;
            foreach ($row['issues'] as $issue) {
                $counts[$issue['severity']]++;
                $penalty += $issue['severity'] === 'error' ? 24 : ($issue['severity'] === 'warning' ? 8 : 0);
            }
            $row['score'] = max(0, 100 - $penalty);
        }
        unset($row);

        usort($rows, static fn(array $a, array $b): int => count($b['issues']) <=> count($a['issues']) ?: strcmp($a['route'], $b['route']));
        $score = $rows === [] ? 100 : (int) round(array_sum(array_column($rows, 'score')) / count($rows));
        return [
            'generated_at' => gmdate('c'),
            'summary' => [
                'pages' => count($rows),
                'sitemap_pages' => count(array_filter($this->pages(), fn($page): bool => $this->isPublicPage($page, true))),
                'score' => $score,
                'errors' => $counts['error'],
                'warnings' => $counts['warning'],
                'informational' => $counts['info'],
            ],
            'pages' => $rows,
        ];
    }

    public function rewriteHtml(string $html): string
    {
        $page = $this->grav['page'] ?? null;
        if (!is_object($page)) {
            return $html;
        }
        $meta = $this->metadataForPage($page);
        if (!$meta['enabled']) {
            return $html;
        }

        $headEnd = stripos($html, '</head>');
        if ($headEnd === false) {
            return $html;
        }
        $head = substr($html, 0, $headEnd);
        $tail = substr($html, $headEnd);

        $patterns = [];
        if ($this->value('metadata.canonical', true)) {
            $patterns[] = '#\s*<link\b[^>]*\brel=["\']canonical["\'][^>]*>\s*#i';
        }
        foreach (['description'] as $name) {
            $patterns[] = '#\s*<meta\b(?=[^>]*\bname=["\']' . preg_quote($name, '#') . '["\'])[^>]*>\s*#i';
        }
        if ($this->value('metadata.robots', true)) {
            $patterns[] = '#\s*<meta\b(?=[^>]*\bname=["\']robots["\'])[^>]*>\s*#i';
        }
        if ($this->value('metadata.twitter_cards', true)) {
            foreach (['twitter:card', 'twitter:title', 'twitter:description', 'twitter:image', 'twitter:site'] as $name) {
                $patterns[] = '#\s*<meta\b(?=[^>]*\bname=["\']' . preg_quote($name, '#') . '["\'])[^>]*>\s*#i';
            }
        }
        if ($this->value('metadata.open_graph', true)) {
            foreach (['og:title', 'og:description', 'og:type', 'og:url', 'og:site_name', 'og:image'] as $property) {
                $patterns[] = '#\s*<meta\b(?=[^>]*\bproperty=["\']' . preg_quote($property, '#') . '["\'])[^>]*>\s*#i';
            }
        }
        if ($this->value('metadata.json_ld', true)) {
            $patterns[] = '#\s*<script\b[^>]*\bdata-meta-pilot=["\']json-ld["\'][^>]*>.*?</script>\s*#is';
        }
        $head = preg_replace($patterns, "\n", $head) ?? $head;

        return $head . "\n" . $this->headMarkup($meta) . "\n" . $tail;
    }

    public function sitemapXml(): string
    {
        $entries = [];
        foreach ($this->pages() as $page) {
            if (!$this->isPublicPage($page, true)) {
                continue;
            }
            $header = $this->pageHeader($page);
            $pilot = $this->nestedArray($header, 'meta_pilot.sitemap');
            $legacy = $this->nestedArray($header, 'sitemap');
            $lastmod = method_exists($page, 'modified') ? (int) $page->modified() : 0;
            $changefreq = (string) ($pilot['changefreq'] ?? $legacy['changefreq'] ?? $this->value('sitemap.default_changefreq', 'monthly'));
            $priority = (float) ($pilot['priority'] ?? $legacy['priority'] ?? $this->value('sitemap.default_priority', 0.5));
            $meta = $this->metadataForPage($page);
            $entry = "  <url>\n    <loc>" . $this->xml($meta['canonical']) . '</loc>';
            if ($lastmod > 0) {
                $entry .= "\n    <lastmod>" . gmdate('Y-m-d\TH:i:s\Z', $lastmod) . '</lastmod>';
            }
            if (in_array($changefreq, ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'], true)) {
                $entry .= "\n    <changefreq>" . $changefreq . '</changefreq>';
            }
            $entry .= "\n    <priority>" . number_format(max(0, min(1, $priority)), 1, '.', '') . "</priority>\n  </url>";
            $entries[] = $entry;
        }
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
            . implode("\n", $entries) . "\n</urlset>\n";
    }

    public function robotsText(): string
    {
        $body = trim((string) $this->value('robots.body', "User-agent: *\nAllow: /"));
        if ($this->value('robots.include_sitemap', true) && $this->value('sitemap.enabled', true)) {
            $body .= "\n\nSitemap: " . $this->absoluteUrl((string) $this->value('sitemap.route', '/sitemap.xml'));
        }
        return $body . "\n";
    }

    private function metadataForPage(object $page): array
    {
        $header = $this->pageHeader($page);
        $pilot = $this->nestedArray($header, 'meta_pilot');
        $standard = $this->metadataMap($header['metadata'] ?? []);
        $title = trim((string) ($pilot['title'] ?? $standard['og:title'] ?? $this->pageTitle($page)));
        $siteName = $this->siteName();
        $description = trim((string) ($pilot['description'] ?? $standard['description'] ?? $standard['og:description'] ?? $header['description'] ?? ''));
        if ($description === '') {
            $description = $this->generatedDescription($page);
        }
        if ($description === '') {
            $description = trim((string) ($this->value('default_description', '') ?: $this->grav['config']->get('site.metadata.description', '')));
        }
        $description = trim(preg_replace('/\s+/u', ' ', $description) ?? $description);
        $descriptionSourceLength = mb_strlen($description);
        $description = $this->truncate($description, (int) $this->value('metadata.description_max_length', 160));
        $canonical = trim((string) ($pilot['canonical'] ?? ''));
        if ($canonical === '') {
            $canonical = $this->pageUrl($page);
        } else {
            $canonical = $this->absoluteUrl($canonical);
        }
        $image = $this->resolveImage($page, $pilot, $standard, $header);
        $robots = trim((string) ($pilot['robots'] ?? $standard['robots'] ?? $this->defaultRobots()));
        $ogType = trim((string) ($pilot['type'] ?? $standard['og:type'] ?? ''));
        if ($ogType === '') {
            $ogType = isset($header['date']) || isset($header['publish_date']) ? 'article' : 'website';
        }
        $schemaType = trim((string) ($pilot['schema_type'] ?? ($ogType === 'article' ? 'Article' : 'WebPage')));
        $fullTitle = $title;
        if ($siteName !== '' && $title !== '' && strcasecmp($title, $siteName) !== 0) {
            $fullTitle .= (string) $this->value('title_separator', ' | ') . $siteName;
        }
        return [
            'enabled' => !array_key_exists('enabled', $pilot) || (bool) $pilot['enabled'],
            'title' => $title,
            'full_title' => $fullTitle,
            'site_name' => $siteName,
            'description' => $description,
            'description_source_length' => $descriptionSourceLength,
            'canonical' => $canonical,
            'image' => $image,
            'robots' => $robots,
            'og_type' => $ogType,
            'schema_type' => $schemaType,
            'twitter_card' => trim((string) ($pilot['twitter_card'] ?? ($image !== '' ? 'summary_large_image' : 'summary'))),
            'date_published' => $this->dateValue($header['publish_date'] ?? $header['date'] ?? null),
            'date_modified' => method_exists($page, 'modified') ? $this->dateValue($page->modified()) : '',
        ];
    }

    private function headMarkup(array $meta): string
    {
        $lines = [];
        if ($this->value('metadata.canonical', true)) {
            $lines[] = '<link rel="canonical" href="' . $this->html($meta['canonical']) . '" data-meta-pilot="canonical">';
        }
        if ($this->value('metadata.robots', true)) {
            $lines[] = '<meta name="robots" content="' . $this->html($meta['robots']) . '" data-meta-pilot="robots">';
        }
        $lines[] = '<meta name="description" content="' . $this->html($meta['description']) . '" data-meta-pilot="description">';
        if ($this->value('metadata.open_graph', true)) {
            foreach ([
                'og:title' => $meta['full_title'],
                'og:description' => $meta['description'],
                'og:type' => $meta['og_type'],
                'og:url' => $meta['canonical'],
                'og:site_name' => $meta['site_name'],
                'og:image' => $meta['image'],
            ] as $property => $content) {
                if ($content !== '') {
                    $lines[] = '<meta property="' . $property . '" content="' . $this->html($content) . '" data-meta-pilot="open-graph">';
                }
            }
        }
        if ($this->value('metadata.twitter_cards', true)) {
            foreach ([
                'twitter:card' => $meta['twitter_card'],
                'twitter:title' => $meta['full_title'],
                'twitter:description' => $meta['description'],
                'twitter:image' => $meta['image'],
                'twitter:site' => trim((string) $this->value('twitter_site', '')),
            ] as $name => $content) {
                if ($content !== '') {
                    $lines[] = '<meta name="' . $name . '" content="' . $this->html($content) . '" data-meta-pilot="twitter">';
                }
            }
        }
        if ($this->value('metadata.json_ld', true)) {
            $schema = array_filter([
                '@context' => 'https://schema.org',
                '@type' => $meta['schema_type'],
                'name' => $meta['full_title'],
                'headline' => $meta['schema_type'] === 'Article' ? $meta['title'] : null,
                'description' => $meta['description'],
                'url' => $meta['canonical'],
                'image' => $meta['image'] !== '' ? [$meta['image']] : null,
                'datePublished' => $meta['date_published'] ?: null,
                'dateModified' => $meta['date_modified'] ?: null,
                'isPartOf' => ['@type' => 'WebSite', 'name' => $meta['site_name'], 'url' => $this->absoluteUrl('/')],
            ], static fn($value): bool => $value !== null && $value !== '');
            $lines[] = '<script type="application/ld+json" data-meta-pilot="json-ld">'
                . json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)
                . '</script>';
        }
        return implode("\n", $lines);
    }

    private function isPublicPage(object $page, bool $forSitemap): bool
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
        $header = $this->pageHeader($page);
        $pilot = $this->nestedArray($header, 'meta_pilot');
        if (array_key_exists('enabled', $pilot) && !$pilot['enabled']) {
            return false;
        }
        if (!$forSitemap) {
            return true;
        }
        $pilotSitemap = $this->nestedArray($header, 'meta_pilot.sitemap');
        $legacy = $this->nestedArray($header, 'sitemap');
        if (($pilotSitemap['exclude'] ?? false) || ($legacy['ignore'] ?? false)) {
            return false;
        }
        if ($this->value('sitemap.ignore_protected', true) && !empty($header['access'])) {
            return false;
        }
        if ($this->value('sitemap.exclude_noindex', true)) {
            $robots = strtolower((string) ($pilot['robots'] ?? $this->metadataMap($header['metadata'] ?? [])['robots'] ?? $this->defaultRobots()));
            if (str_contains($robots, 'noindex')) {
                return false;
            }
        }
        return true;
    }

    private function resolveImage(object $page, array $pilot, array $standard, array $header): string
    {
        $configured = trim((string) ($pilot['image'] ?? $standard['og:image'] ?? $standard['twitter:image'] ?? ''));
        if ($configured !== '') {
            $mediaUrl = $this->pageMediaUrl($page, $configured);
            return $mediaUrl !== '' ? $mediaUrl : $this->absoluteUrl($configured);
        }
        $hero = $this->nestedArray($header, 'hero');
        $candidates = array_filter([
            (string) ($hero['image'] ?? ''),
            'og-image.jpg', 'og-image.png', 'og-image.webp', 'social-card.jpg', 'social-card.png',
        ]);
        foreach ($candidates as $candidate) {
            $url = $this->pageMediaUrl($page, $candidate);
            if ($url !== '') {
                return $url;
            }
        }
        try {
            $media = method_exists($page, 'media') ? $page->media() : null;
            $all = $media && method_exists($media, 'all') ? $media->all() : [];
            foreach ($all as $name => $medium) {
                if (preg_match('/\.(?:avif|webp|png|jpe?g)$/i', (string) $name) && is_object($medium) && method_exists($medium, 'url')) {
                    return $this->absoluteUrl((string) $medium->url());
                }
            }
        } catch (\Throwable) {
        }
        $fallback = trim((string) $this->value('default_image', ''));
        return $fallback !== '' ? $this->absoluteUrl($fallback) : '';
    }

    private function pageMediaUrl(object $page, string $name): string
    {
        if ($name === '' || preg_match('#^(?:https?:)?//#i', $name)) {
            return $name !== '' ? $this->absoluteUrl($name) : '';
        }
        try {
            $media = method_exists($page, 'media') ? $page->media() : null;
            if ($media && isset($media[$name]) && is_object($media[$name]) && method_exists($media[$name], 'url')) {
                return $this->absoluteUrl((string) $media[$name]->url());
            }
        } catch (\Throwable) {
        }
        return '';
    }

    // Preserve a site's publishing policy when adding Meta Pilot. Explicit page
    // overrides still take precedence, as they do for other metadata fields.
    private function defaultRobots(): string
    {
        $siteRobots = trim((string) $this->grav['config']->get('site.metadata.robots', ''));
        return $siteRobots !== '' ? $siteRobots : (string) $this->value('metadata.default_robots', 'index, follow, max-image-preview:large');
    }

    private function generatedDescription(object $page): string
    {
        // Empty source is valid. Rendering it here can invoke Twig in an API
        // request before Twig is initialized (or execute page-specific code).
        $markdown = method_exists($page, 'rawMarkdown') ? (string) $page->rawMarkdown() : '';
        if (!method_exists($page, 'rawMarkdown') && method_exists($page, 'content')) {
            $markdown = (string) $page->content();
        }
        $markdown = preg_replace('/\[([^\]]+)\]\([^\)]+\)/u', '$1', $markdown) ?? $markdown;
        $markdown = preg_replace('/\[(?:file-vault|file-download|prism|lightbox)[^\]]*\](?:.*?\[\/(?:file-vault|file-download|prism|lightbox)\])?/isu', ' ', $markdown) ?? $markdown;
        $text = html_entity_decode(strip_tags($markdown), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/(?:^|\s)[#>*_`~\-]+(?=\s|$)/u', ' ', $text) ?? $text;
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private function pages(): array
    {
        $pages = $this->grav['pages'] ?? null;
        if (!$pages || !method_exists($pages, 'all')) {
            return [];
        }

        // Grav's API disables the public page tree for performance and installs
        // only a virtual root. Page-aware API controllers opt back in through
        // Pages::enablePages(), which flips that API-only flag and initializes
        // the real tree. Public and CLI requests can safely call it as a no-op.
        if (method_exists($pages, 'enablePages')) {
            $pages->enablePages();
        } elseif (method_exists($pages, 'init')) {
            $pages->init();
        }

        $all = $pages->all();
        // Collection iterator keys are folder slugs, not unique routes.
        // Discard keys so /about and /guides/about both survive.
        return is_array($all) ? array_values($all) : iterator_to_array($all, false);
    }

    private function pageHeader(object $page): array
    {
        $header = method_exists($page, 'header') ? $page->header() : [];
        return $this->toArray($header);
    }

    private function pageTitle(object $page): string
    {
        return method_exists($page, 'title') ? trim((string) $page->title()) : '';
    }

    private function pageRoute(object $page): string
    {
        return method_exists($page, 'route') ? (string) $page->route() : '';
    }

    private function pageUrl(object $page): string
    {
        if (method_exists($page, 'url')) {
            try {
                return (string) $page->url(true, true);
            } catch (\Throwable) {
            }
        }
        return $this->absoluteUrl($this->pageRoute($page) ?: '/');
    }

    private function siteName(): string
    {
        return trim((string) ($this->value('site_name', '') ?: $this->grav['config']->get('site.title', '')));
    }

    private function absoluteUrl(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (str_starts_with($value, '//')) {
            $scheme = method_exists($this->grav['uri'], 'scheme') ? (string) $this->grav['uri']->scheme() : 'https';
            return $scheme . ':' . $value;
        }
        if (preg_match('#^https?://#i', $value)) {
            return $value;
        }
        try {
            return (string) Utils::url($value, true);
        } catch (\Throwable) {
            $root = method_exists($this->grav['uri'], 'rootUrl') ? rtrim((string) $this->grav['uri']->rootUrl(true), '/') : '';
            return $root . '/' . ltrim($value, '/');
        }
    }

    private function metadataMap(mixed $metadata): array
    {
        $metadata = $this->toArray($metadata);
        $result = [];
        foreach ($metadata as $key => $value) {
            if (is_string($key) && !is_array($value)) {
                $result[strtolower($key)] = trim((string) $value);
                continue;
            }
            if (is_array($value)) {
                $name = strtolower((string) ($value['name'] ?? $value['property'] ?? ''));
                if ($name !== '') {
                    $result[$name] = trim((string) ($value['content'] ?? ''));
                }
            }
        }
        return $result;
    }

    private function nestedArray(array $array, string $path): array
    {
        $value = $array;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return [];
            }
            $value = $value[$segment];
        }
        return $this->toArray($value);
    }

    private function value(string $path, mixed $default = null): mixed
    {
        $value = $this->config;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    private function toArray(mixed $value): array
    {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        if (!is_array($value)) {
            return [];
        }
        foreach ($value as $key => $item) {
            if (is_object($item)) {
                $value[$key] = $this->toArray($item);
            }
        }
        return $value;
    }

    private function truncate(string $value, int $length): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        if (mb_strlen($value) <= $length) {
            return $value;
        }
        $short = mb_substr($value, 0, max(1, $length - 1));
        $space = mb_strrpos($short, ' ');
        if ($space !== false && $space > (int) ($length * 0.6)) {
            $short = mb_substr($short, 0, $space);
        }
        return rtrim($short, " \t\n\r\0\x0B.,;:-") . '…';
    }

    private function dateValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $timestamp = is_numeric($value) ? (int) $value : strtotime((string) $value);
        return $timestamp > 0 ? gmdate('c', $timestamp) : '';
    }

    private function issue(string $code, string $severity, string $message): array
    {
        return compact('code', 'severity', 'message');
    }

    private function html(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
