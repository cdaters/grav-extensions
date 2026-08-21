<?php

declare(strict_types=1);

namespace Grav\Plugin\SiteWorkshop\Service;

use DOMDocument;
use DOMElement;
use DOMNode;
use Grav\Common\Grav;

final class IconBenchService
{
    private Grav $grav;

    /** @var array<string,array{label:string,source:string,path:string,icons:list<string>}>|null */
    private ?array $catalog = null;

    public function __construct(?Grav $grav = null)
    {
        $this->grav = $grav ?? Grav::instance();
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        $packs = $this->catalog();
        $count = array_sum(array_map(static fn (array $pack): int => count($pack['icons']), $packs));

        return [
            'version' => '0.2.0',
            'settings_url' => '/plugins/site-workshop',
            'modules' => [
                'icon_bench' => [
                    'label' => 'Icon Bench',
                    'status' => $this->enabled() ? 'available' : 'disabled',
                    'description' => 'Safe SVG packs, discovery, preview, Twig, and shortcode output.',
                ],
                'frontmatter_annex' => [
                    'label' => 'Frontmatter Annex',
                    'status' => (new FrontmatterAnnexService($this->grav))->enabled() ? 'available' : 'disabled',
                    'description' => 'External, reusable frontmatter sources with explicit precedence.',
                ],
                'cache_hearth' => [
                    'label' => 'Cache Hearth',
                    'status' => 'planned',
                    'description' => 'Bounded cache warming with progress, budgets, and exclusions.',
                ],
                'feed_relay' => [
                    'label' => 'Feed Relay',
                    'status' => 'planned',
                    'description' => 'Purpose-built RSS and JSON feeds for automation services.',
                ],
            ],
            'icon_bench' => [
                'enabled' => $this->enabled(),
                'default_pack' => $this->defaultPack(),
                'pack_count' => count($packs),
                'icon_count' => $count,
                'packs' => array_values(array_map(
                    static fn (string $slug, array $pack): array => [
                        'slug' => $slug,
                        'label' => $pack['label'],
                        'source' => $pack['source'],
                        'count' => count($pack['icons']),
                    ],
                    array_keys($packs),
                    $packs
                )),
                'shortcode_available' => isset($this->grav['shortcode']),
                'twig_available' => true,
                'dom_sanitizer_available' => class_exists(DOMDocument::class),
            ],
            'frontmatter_annex' => (new FrontmatterAnnexService($this->grav))->status(),
        ];
    }

    /** @return array<string,mixed> */
    public function search(string $query = '', string $pack = '', int $page = 1, int $perPage = 48): array
    {
        $query = mb_strtolower(mb_substr(trim($query), 0, 80));
        $pack = $this->slug($pack);
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $items = [];

        foreach ($this->catalog() as $packSlug => $descriptor) {
            if ($pack !== '' && $pack !== $packSlug) {
                continue;
            }
            foreach ($descriptor['icons'] as $icon) {
                $reference = $packSlug . '/' . $icon;
                if ($query !== '' && !str_contains(mb_strtolower($reference), $query)) {
                    continue;
                }
                $items[] = [
                    'pack' => $packSlug,
                    'pack_label' => $descriptor['label'],
                    'name' => $icon,
                    'reference' => $reference,
                    'svg' => $this->renderReference($reference, ['class' => 'site-workshop-preview-icon']),
                    'shortcode' => '[workshop-icon icon="' . $reference . '" /]',
                    'twig' => "{{ workshop_icon('" . $reference . "') }}",
                ];
            }
        }

        usort($items, static fn (array $a, array $b): int => strnatcasecmp($a['reference'], $b['reference']));
        $total = count($items);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);

        return [
            'items' => array_slice($items, ($page - 1) * $perPage, $perPage),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'pages' => $pages,
                'total' => $total,
            ],
            'query' => $query,
            'pack' => $pack,
        ];
    }

    /** @return array<string,mixed> */
    public function refresh(): array
    {
        $this->catalog = null;
        return $this->status();
    }

    /** @param array<string,mixed>|string $options */
    public function renderReference(string $reference, array|string $options = []): string
    {
        if (!$this->enabled() || !class_exists(DOMDocument::class)) {
            return '';
        }

        [$pack, $icon] = $this->parseReference($reference);
        $catalog = $this->catalog();
        if (!isset($catalog[$pack]) || !in_array($icon, $catalog[$pack]['icons'], true)) {
            return '';
        }

        $root = realpath($catalog[$pack]['path']);
        $file = realpath($catalog[$pack]['path'] . DIRECTORY_SEPARATOR . $icon . '.svg');
        if ($root === false || $file === false || !is_file($file) || !$this->inside($file, $root)) {
            return '';
        }

        $maximum = max(1024, min(1048576, (int) $this->config('modules.icon_bench.maximum_svg_bytes', 262144)));
        $size = filesize($file);
        if ($size === false || $size < 1 || $size > $maximum) {
            return '';
        }
        $raw = file_get_contents($file);
        if ($raw === false || preg_match('/<!DOCTYPE|<!ENTITY/i', $raw)) {
            return '';
        }

        return $this->sanitize($raw, is_string($options) ? ['class' => $options] : $options);
    }

    /** @return array{0:string,1:string} */
    private function parseReference(string $reference): array
    {
        $reference = trim(str_replace('\\', '/', $reference), '/ ');
        $parts = array_values(array_filter(explode('/', $reference), static fn (string $part): bool => $part !== ''));
        if (count($parts) === 1) {
            return [$this->defaultPack(), $this->slug($parts[0])];
        }
        return [$this->slug($parts[0] ?? ''), $this->slug($parts[1] ?? '')];
    }

    /** @return array<string,array{label:string,source:string,path:string,icons:list<string>}> */
    private function catalog(): array
    {
        if ($this->catalog !== null) {
            return $this->catalog;
        }

        $packs = [];
        $this->addPack($packs, 'workshop', 'Workshop Essentials', 'Bundled', dirname(__DIR__, 2) . '/icons/workshop');
        foreach ((array) $this->config('modules.icon_bench.custom_paths', []) as $resource) {
            $root = $this->resolveResource((string) $resource);
            if ($root === '' || !is_dir($root)) {
                continue;
            }
            $children = scandir($root) ?: [];
            foreach ($children as $child) {
                if ($child === '.' || $child === '..' || !is_dir($root . DIRECTORY_SEPARATOR . $child)) {
                    continue;
                }
                $slug = $this->slug($child);
                if ($slug !== '' && !isset($packs[$slug])) {
                    $this->addPack($packs, $slug, $this->label($slug), 'Custom', $root . DIRECTORY_SEPARATOR . $child);
                }
            }
        }

        ksort($packs, SORT_NATURAL | SORT_FLAG_CASE);
        return $this->catalog = $packs;
    }

    /** @param array<string,array{label:string,source:string,path:string,icons:list<string>}> $packs */
    private function addPack(array &$packs, string $slug, string $label, string $source, string $path): void
    {
        $real = realpath($path);
        if ($real === false || !is_dir($real)) {
            return;
        }
        $icons = [];
        foreach (scandir($real) ?: [] as $file) {
            if (!preg_match('/^([a-z0-9][a-z0-9-]*)\.svg$/i', $file, $match)) {
                continue;
            }
            $candidate = realpath($real . DIRECTORY_SEPARATOR . $file);
            if ($candidate !== false && is_file($candidate) && $this->inside($candidate, $real)) {
                $icons[] = strtolower($match[1]);
            }
        }
        if ($icons === []) {
            return;
        }
        sort($icons, SORT_NATURAL | SORT_FLAG_CASE);
        $packs[$slug] = ['label' => $label, 'source' => $source, 'path' => $real, 'icons' => array_values(array_unique($icons))];
    }

    /** @param array<string,mixed> $options */
    private function sanitize(string $raw, array $options): string
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadXML($raw, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $document->documentElement;
        if (!$loaded || !$root instanceof DOMElement || strtolower($root->tagName) !== 'svg') {
            return '';
        }

        $this->sanitizeNode($root);
        if (!$root->hasAttribute('viewBox')) {
            $width = $this->numericDimension($root->getAttribute('width'));
            $height = $this->numericDimension($root->getAttribute('height'));
            if ($width > 0 && $height > 0) {
                $root->setAttribute('viewBox', '0 0 ' . $width . ' ' . $height);
            }
        }
        if (!$root->hasAttribute('viewBox')) {
            return '';
        }

        $class = $this->classNames((string) ($options['class'] ?? ''));
        if ($class !== '') {
            $root->setAttribute('class', $class);
        }
        $size = trim((string) ($options['size'] ?? '1em'));
        if (!preg_match('/^(?:\d+(?:\.\d+)?(?:px|rem|em|%)?|auto)$/', $size)) {
            $size = '1em';
        }
        $root->setAttribute('width', $size);
        $root->setAttribute('height', $size);
        $root->setAttribute('focusable', 'false');

        $title = trim(mb_substr((string) ($options['title'] ?? $options['aria_label'] ?? ''), 0, 160));
        if ($title !== '') {
            $titleNode = $document->createElement('title');
            $titleNode->appendChild($document->createTextNode($title));
            $root->insertBefore($titleNode, $root->firstChild);
            $root->setAttribute('role', 'img');
            $root->setAttribute('aria-label', $title);
            $root->removeAttribute('aria-hidden');
        } else {
            $root->setAttribute('aria-hidden', 'true');
        }

        return $document->saveXML($root) ?: '';
    }

    private function sanitizeNode(DOMNode $node): void
    {
        $allowedElements = ['svg', 'g', 'path', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'rect', 'title', 'desc'];
        $allowedAttributes = [
            'xmlns', 'viewbox', 'width', 'height', 'fill', 'stroke', 'stroke-width', 'stroke-linecap',
            'stroke-linejoin', 'fill-rule', 'clip-rule', 'd', 'cx', 'cy', 'r', 'rx', 'ry', 'x', 'y',
            'x1', 'x2', 'y1', 'y2', 'points', 'transform', 'opacity', 'class', 'aria-hidden',
            'aria-label', 'role', 'focusable',
        ];

        if ($node instanceof DOMElement) {
            foreach (iterator_to_array($node->attributes) as $attribute) {
                $name = strtolower($attribute->nodeName);
                $value = trim($attribute->nodeValue ?? '');
                if (!in_array($name, $allowedAttributes, true)
                    || str_starts_with($name, 'on')
                    || preg_match('/(?:javascript:|data:|url\s*\()/i', $value)) {
                    $node->removeAttribute($attribute->nodeName);
                }
            }
        }

        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement && !in_array(strtolower($child->tagName), $allowedElements, true)) {
                $node->removeChild($child);
                continue;
            }
            if ($child instanceof DOMElement) {
                $this->sanitizeNode($child);
            }
        }
    }

    private function resolveResource(string $resource): string
    {
        $resource = trim($resource);
        if ($resource === '') {
            return '';
        }
        if (str_contains($resource, '://')) {
            $locator = $this->grav['locator'] ?? null;
            try {
                $resolved = $locator && method_exists($locator, 'findResource')
                    ? $locator->findResource($resource, true, true)
                    : false;
            } catch (\Throwable) {
                // Some streams (notably theme://) are unavailable during an
                // early CLI boot. The bundled pack remains usable and the
                // resource will be discovered during a normal site request.
                $resolved = false;
            }
            return is_string($resolved) ? $resolved : '';
        }
        return $resource;
    }

    private function enabled(): bool
    {
        return (bool) $this->config('modules.icon_bench.enabled', true);
    }

    private function defaultPack(): string
    {
        $pack = $this->slug((string) $this->config('modules.icon_bench.default_pack', 'workshop'));
        return $pack !== '' ? $pack : 'workshop';
    }

    private function config(string $key, mixed $default = null): mixed
    {
        $config = $this->grav['config'] ?? null;
        return $config && method_exists($config, 'get') ? $config->get('plugins.site-workshop.' . $key, $default) : $default;
    }

    private function slug(string $value): string
    {
        $value = strtolower(trim($value));
        return preg_match('/^[a-z0-9][a-z0-9-]*$/', $value) ? $value : '';
    }

    private function label(string $slug): string
    {
        return ucwords(str_replace('-', ' ', $slug));
    }

    private function inside(string $candidate, string $root): bool
    {
        return $candidate === $root || str_starts_with($candidate, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
    }

    private function classNames(string $value): string
    {
        $classes = array_filter(preg_split('/\s+/', trim($value)) ?: [], static fn (string $class): bool => (bool) preg_match('/^[a-zA-Z_][a-zA-Z0-9_-]*$/', $class));
        return implode(' ', array_slice(array_unique($classes), 0, 12));
    }

    private function numericDimension(string $value): float
    {
        return preg_match('/^\s*(\d+(?:\.\d+)?)(?:px)?\s*$/i', $value, $match) ? (float) $match[1] : 0.0;
    }
}
