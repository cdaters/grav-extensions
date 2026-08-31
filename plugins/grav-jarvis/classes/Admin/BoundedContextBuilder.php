<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Admin;

use Grav\Plugin\GravJarvis\Security\SecretRedactor;

final class BoundedContextBuilder
{
    public const CONTENT_BYTES = 49152;
    public const FRONTMATTER_BYTES = 8192;
    public const MEDIA_BYTES = 4096;
    public const MEDIA_ITEMS = 32;

    private readonly SecretRedactor $redactor;

    public function __construct(?SecretRedactor $redactor = null)
    {
        $this->redactor = $redactor ?? SecretRedactor::fromEnvironment();
    }

    /**
     * @param array<string, mixed> $page
     * @return array<string, mixed>
     */
    public function build(array $page, string $content): array
    {
        $route = $this->boundedText((string) ($page['route'] ?? ''), 1024);
        $title = $this->boundedText((string) ($page['title'] ?? ''), 512);
        $template = $this->boundedText((string) ($page['template'] ?? ''), 256);
        $language = $this->boundedText((string) ($page['language'] ?? ''), 64);

        [$boundedContent, $contentTruncated] = $this->boundedContent($content);
        [$frontmatter, $frontmatterTruncated] = $this->boundedMap(
            is_array($page['frontmatter'] ?? null) ? $page['frontmatter'] : [],
            self::FRONTMATTER_BYTES
        );
        [$media, $mediaTruncated] = $this->boundedMedia(
            is_array($page['media'] ?? null) ? $page['media'] : []
        );

        return [
            'route' => $route,
            'title' => $title,
            'template' => $template,
            'language' => $language,
            'content' => $boundedContent,
            'frontmatter' => $frontmatter,
            'media' => $media,
            'source_hash' => hash('sha256', $content),
            'content_bytes' => strlen($content),
            'included_content_bytes' => strlen($boundedContent),
            'truncated' => [
                'content' => $contentTruncated,
                'frontmatter' => $frontmatterTruncated,
                'media' => $mediaTruncated,
                'any' => $contentTruncated || $frontmatterTruncated || $mediaTruncated,
            ],
            'accept_allowed' => !$contentTruncated,
            'limits' => [
                'content_bytes' => self::CONTENT_BYTES,
                'frontmatter_bytes' => self::FRONTMATTER_BYTES,
                'media_bytes' => self::MEDIA_BYTES,
                'media_items' => self::MEDIA_ITEMS,
            ],
        ];
    }

    /** @return array{string, bool} */
    private function boundedContent(string $content): array
    {
        if (strlen($content) <= self::CONTENT_BYTES) {
            $redacted = $this->redactor->redact($content);
            return strlen($redacted) <= self::CONTENT_BYTES
                ? [$redacted, false]
                : [$this->validUtf8Slice($redacted, 0, self::CONTENT_BYTES), true];
        }

        $marker = "\n\n[Jarvis context omitted the middle of this large page.]\n\n";
        $headBytes = 32768;
        $tailBytes = self::CONTENT_BYTES - $headBytes - strlen($marker);
        $head = $this->validUtf8Slice($content, 0, $headBytes);
        $tail = $this->validUtf8Slice($content, -$tailBytes, $tailBytes);
        $redacted = $this->redactor->redact($head . $marker . $tail);
        return [
            strlen($redacted) <= self::CONTENT_BYTES
                ? $redacted
                : $this->validUtf8Slice($redacted, 0, self::CONTENT_BYTES),
            true,
        ];
    }

    /**
     * @param array<string, mixed> $values
     * @return array{array<string, mixed>, bool}
     */
    private function boundedMap(array $values, int $limit): array
    {
        $values = $this->sanitizeValue($values);
        if (!is_array($values)) {
            return [[], false];
        }
        ksort($values, SORT_STRING);
        $selected = [];
        $truncated = false;
        foreach ($values as $key => $value) {
            $candidate = $selected;
            $candidate[$key] = $value;
            if ($this->encodedBytes($candidate) > $limit) {
                $truncated = true;
                continue;
            }
            $selected = $candidate;
        }
        return [$selected, $truncated];
    }

    /**
     * @param array<mixed> $items
     * @return array{list<array<string, mixed>>, bool}
     */
    private function boundedMedia(array $items): array
    {
        $selected = [];
        $truncated = false;
        foreach (array_values($items) as $item) {
            if (count($selected) >= self::MEDIA_ITEMS) {
                $truncated = true;
                break;
            }
            if (!is_array($item)) {
                $truncated = true;
                continue;
            }
            $safe = [];
            foreach (['filename', 'type', 'mime', 'width', 'height', 'alt', 'title'] as $key) {
                $value = $item[$key] ?? null;
                if (is_string($value)) {
                    $safe[$key] = $this->boundedText($value, 512);
                } elseif (is_int($value) || is_float($value)) {
                    $safe[$key] = $value;
                }
            }
            $candidate = [...$selected, $safe];
            if ($this->encodedBytes($candidate) > self::MEDIA_BYTES) {
                $truncated = true;
                break;
            }
            $selected = $candidate;
        }
        if (count($selected) < count($items)) {
            $truncated = true;
        }
        return [$selected, $truncated];
    }

    private function sanitizeValue(mixed $value, ?string $key = null, int $depth = 0): mixed
    {
        if ($depth > 8) {
            return '[bounded]';
        }
        if ($key !== null && preg_match(
            '/(?:authorization|api[_-]?key|access[_-]?token|refresh[_-]?token|token|secret|password|credential)/i',
            $key
        ) === 1) {
            return '[REDACTED]';
        }
        if (is_string($value)) {
            return $this->boundedText($value, 2000);
        }
        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }
        if (is_object($value)) {
            if (method_exists($value, 'toArray')) {
                $value = $value->toArray();
            } elseif ($value instanceof \JsonSerializable) {
                $value = $value->jsonSerialize();
            } else {
                return '[object omitted]';
            }
        }
        if (!is_array($value)) {
            return '[value omitted]';
        }
        $safe = [];
        foreach ($value as $itemKey => $item) {
            if (!is_int($itemKey) && !is_string($itemKey)) {
                continue;
            }
            $safe[$itemKey] = $this->sanitizeValue(
                $item,
                is_string($itemKey) ? $itemKey : null,
                $depth + 1
            );
        }
        if (!array_is_list($safe)) {
            ksort($safe, SORT_STRING);
        }
        return $safe;
    }

    private function boundedText(string $value, int $bytes): string
    {
        $value = $this->redactor->redact($value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '';
        return strlen($value) <= $bytes ? $value : $this->validUtf8Slice($value, 0, $bytes);
    }

    private function validUtf8Slice(string $value, int $offset, int $length): string
    {
        $slice = substr($value, $offset, $length);
        while ($slice !== '' && preg_match('//u', $slice) !== 1) {
            $slice = $offset < 0 ? substr($slice, 1) : substr($slice, 0, -1);
        }
        return $slice;
    }

    private function encodedBytes(array $value): int
    {
        return strlen((string) json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
        ));
    }
}
