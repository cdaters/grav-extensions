<?php

declare(strict_types=1);

namespace Grav\Plugin\GravCaxton\Document;

use Grav\Plugin\GravCaxton\Contracts\Exception\InvalidSourceException;
use Grav\Plugin\GravCaxton\Contracts\Exception\SourceTooLargeException;
use Grav\Plugin\GravCaxton\Contracts\ParseDiagnostic;
use Grav\Plugin\GravCaxton\Contracts\SourceBlock;
use Grav\Plugin\GravCaxton\Contracts\SourceDocument;

final class SourceDocumentParser
{
    public function __construct(private readonly int $maxSourceBytes = 2_097_152)
    {
        if ($maxSourceBytes < 1024) {
            throw new \InvalidArgumentException('The source limit must be at least 1024 bytes.');
        }
    }

    public function parse(string $source): SourceDocument
    {
        $byteLength = strlen($source);
        if ($byteLength > $this->maxSourceBytes) {
            throw new SourceTooLargeException('Caxton source exceeds the configured byte limit.');
        }
        if (str_contains($source, "\0")) {
            throw new InvalidSourceException('Caxton source cannot contain NUL bytes.');
        }

        $lines = $this->lines($source);
        $blocks = [];
        $diagnostics = [];
        $offset = 0;
        $index = 0;
        $blockNumber = 1;

        if (isset($lines[0]) && $this->lineContent($lines[0]) === '---') {
            $end = null;
            for ($cursor = 1, $count = count($lines); $cursor < $count; $cursor++) {
                if (in_array($this->lineContent($lines[$cursor]), ['---', '...'], true)) {
                    $end = $cursor;
                    break;
                }
            }
            if ($end === null) {
                $end = count($lines) - 1;
                $diagnostics[] = new ParseDiagnostic(
                    'unclosed-frontmatter',
                    'warning',
                    'The frontmatter opening delimiter has no closing delimiter; the source is preserved.',
                    0
                );
            }
            $chunk = implode('', array_slice($lines, 0, $end + 1));
            $blocks[] = $this->block($blockNumber++, 'frontmatter', $offset, $chunk, 'source-only', false);
            $offset += strlen($chunk);
            $index = $end + 1;
        }

        while ($index < count($lines)) {
            $line = $lines[$index];
            $content = $this->lineContent($line);

            if (trim($content, " \t") === '') {
                $end = $index + 1;
                while ($end < count($lines) && trim($this->lineContent($lines[$end]), " \t") === '') {
                    $end++;
                }
                $chunk = implode('', array_slice($lines, $index, $end - $index));
                $blocks[] = $this->block($blockNumber++, 'trivia', $offset, $chunk, 'byte-preserved', false);
                $offset += strlen($chunk);
                $index = $end;
                continue;
            }

            if (preg_match('/^[ \t]{0,3}(`{3,}|~{3,})/', $content, $fence) === 1) {
                $marker = $fence[1][0];
                $minimum = strlen($fence[1]);
                $end = $index + 1;
                $closed = false;
                while ($end < count($lines)) {
                    $candidate = $this->lineContent($lines[$end]);
                    if (preg_match('/^[ \t]{0,3}' . preg_quote($marker, '/') . '{' . $minimum . ',}[ \t]*$/', $candidate) === 1) {
                        $end++;
                        $closed = true;
                        break;
                    }
                    $end++;
                }
                $chunk = implode('', array_slice($lines, $index, $end - $index));
                $blocks[] = $this->block($blockNumber++, 'fenced-code', $offset, $chunk, 'opaque', false);
                if (!$closed) {
                    $diagnostics[] = new ParseDiagnostic(
                        'unclosed-fence',
                        'warning',
                        'The fenced code block has no closing fence; the source is preserved.',
                        $offset
                    );
                }
                $offset += strlen($chunk);
                $index = $end;
                continue;
            }

            if (preg_match('/^[ \t]{0,3}(#{1,6})[ \t]+(.*?)[ \t]*#*[ \t]*$/', $content, $heading) === 1) {
                $safe = $this->isSafePlainText($heading[2]);
                $blocks[] = $this->block(
                    $blockNumber++,
                    'heading',
                    $offset,
                    $line,
                    $safe ? 'localized-edit' : 'opaque',
                    $safe,
                    ['level' => strlen($heading[1]), 'text' => $heading[2]]
                );
                $offset += strlen($line);
                $index++;
                continue;
            }

            if (preg_match('/^[ \t]{0,3}(?:\*[ \t]*){3,}$/', $content) === 1
                || preg_match('/^[ \t]{0,3}(?:-[ \t]*){3,}$/', $content) === 1
                || preg_match('/^[ \t]{0,3}(?:_[ \t]*){3,}$/', $content) === 1
            ) {
                $blocks[] = $this->block(
                    $blockNumber++,
                    'thematic-break',
                    $offset,
                    $line,
                    'byte-preserved',
                    false
                );
                $offset += strlen($line);
                $index++;
                continue;
            }

            $end = $index + 1;
            while ($end < count($lines)) {
                $candidate = $this->lineContent($lines[$end]);
                if (trim($candidate, " \t") === '' || $this->startsIndependentBlock($candidate)) {
                    break;
                }
                $end++;
            }
            $chunk = implode('', array_slice($lines, $index, $end - $index));
            $type = $this->opaqueType($content);
            $plainText = $this->withoutTrailingNewline($chunk);
            $safe = $type === 'paragraph' && !str_contains($plainText, "\n")
                && !str_contains($plainText, "\r") && $this->isSafePlainText($plainText);
            $blocks[] = $this->block(
                $blockNumber++,
                $type,
                $offset,
                $chunk,
                $safe ? 'localized-edit' : 'opaque',
                $safe,
                $safe ? ['text' => $plainText] : []
            );
            $offset += strlen($chunk);
            $index = $end;
        }

        return new SourceDocument(
            source: $source,
            sourceHash: hash('sha256', $source),
            newlineStyle: $this->newlineStyle($source),
            byteLength: $byteLength,
            blocks: $blocks,
            diagnostics: $diagnostics
        );
    }

    /** @return list<string> */
    private function lines(string $source): array
    {
        if ($source === '') {
            return [];
        }
        $parts = preg_split('/(\r\n|\r|\n)/', $source, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) {
            throw new InvalidSourceException('Caxton could not segment the source.');
        }
        $lines = [];
        for ($index = 0, $count = count($parts); $index < $count; $index += 2) {
            $text = $parts[$index];
            $ending = $parts[$index + 1] ?? '';
            if ($text === '' && $ending === '' && $index === $count - 1) {
                continue;
            }
            $lines[] = $text . $ending;
        }
        return $lines;
    }

    private function lineContent(string $line): string
    {
        return preg_replace('/(?:\r\n|\r|\n)$/', '', $line) ?? $line;
    }

    private function withoutTrailingNewline(string $source): string
    {
        return preg_replace('/(?:\r\n|\r|\n)$/', '', $source) ?? $source;
    }

    private function startsIndependentBlock(string $content): bool
    {
        return preg_match('/^[ \t]{0,3}(?:#{1,6}[ \t]+|`{3,}|~{3,})/', $content) === 1
            || preg_match('/^[ \t]{0,3}(?:\*[ \t]*){3,}$/', $content) === 1
            || preg_match('/^[ \t]{0,3}(?:-[ \t]*){3,}$/', $content) === 1
            || preg_match('/^[ \t]{0,3}(?:_[ \t]*){3,}$/', $content) === 1;
    }

    private function opaqueType(string $content): string
    {
        if (preg_match('/^[ \t]{0,3}(?:[-+*]|\d+[.)])[ \t]+/', $content) === 1) {
            return 'list';
        }
        if (preg_match('/^[ \t]{0,3}>/', $content) === 1) {
            return 'blockquote';
        }
        if (preg_match('/^[ \t]*!\[/', $content) === 1) {
            return 'media';
        }
        if (preg_match('/^[ \t]*(?:\{\{|\{%|\{#)/', $content) === 1) {
            return 'twig';
        }
        if (preg_match('/^[ \t]*\[[A-Za-z][A-Za-z0-9_-]*(?:[ \t\]\/])/', $content) === 1) {
            return 'shortcode';
        }
        if (preg_match('/^[ \t]*</', $content) === 1) {
            return 'raw-html';
        }
        if (str_contains($content, '|')) {
            return 'table-or-pipe';
        }
        return 'paragraph';
    }

    private function isSafePlainText(string $text): bool
    {
        return trim($text, " \t") !== ''
            && preg_match('/[`*_{}\[\]<>|!\\\\]/', $text) !== 1;
    }

    /** @param array<string, scalar|null> $metadata */
    private function block(
        int $number,
        string $type,
        int $offset,
        string $source,
        string $preservation,
        bool $visualEditable,
        array $metadata = []
    ): SourceBlock {
        $id = sprintf('block-%04d-%s', $number, substr(hash('sha256', $source), 0, 10));
        return new SourceBlock(
            $id,
            $type,
            $offset,
            strlen($source),
            $source,
            $preservation,
            $visualEditable,
            $metadata
        );
    }

    private function newlineStyle(string $source): string
    {
        $styles = [];
        if (str_contains($source, "\r\n")) {
            $styles[] = 'crlf';
        }
        $withoutCrLf = str_replace("\r\n", '', $source);
        if (str_contains($withoutCrLf, "\n")) {
            $styles[] = 'lf';
        }
        if (str_contains($withoutCrLf, "\r")) {
            $styles[] = 'cr';
        }
        return count($styles) === 0 ? 'none' : (count($styles) === 1 ? $styles[0] : 'mixed');
    }
}
