<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Reliability;

use Grav\Plugin\GravJarvis\Contracts\ChunkPolicy;
use Grav\Plugin\GravJarvis\Contracts\ChunkingResult;
use Grav\Plugin\GravJarvis\Contracts\ContentChunk;
use Grav\Plugin\GravJarvis\Contracts\Exception\ChunkingException;

final class GravMarkdownChunker
{
    public function chunk(string $content, ChunkPolicy $policy): ChunkingResult
    {
        $sourceBytes = strlen($content);
        $sourceHash = hash('sha256', $content);
        if ($sourceBytes === 0) {
            return new ChunkingResult($sourceHash, 0, 0, false, []);
        }
        if (preg_match('//u', $content) !== 1) {
            throw new ChunkingException('invalid_encoding', 'Jarvis Markdown chunking requires valid UTF-8 content.');
        }
        if ($sourceBytes > $policy->maxTotalBytes && $policy->overflowStrategy === ChunkPolicy::FAIL) {
            throw new ChunkingException('total_limit_exceeded', 'The supplied Markdown exceeds the configured Jarvis total-content limit.');
        }

        $blocks = $this->blocks($content);
        $chunks = [];
        $buffer = '';
        $start = 0;
        $included = 0;
        $truncated = false;

        $flush = function () use (&$chunks, &$buffer, &$start, &$included, $sourceHash): void {
            if ($buffer === '') return;
            $end = $start + strlen($buffer);
            $chunks[] = new ContentChunk(count($chunks), $buffer, $start, $end, $sourceHash);
            $included = $end;
            $buffer = '';
            $start = $end;
        };

        foreach ($blocks as $block) {
            $length = strlen($block);
            if ($length > $policy->maxChunkBytes) {
                if ($policy->overflowStrategy === ChunkPolicy::FAIL) {
                    throw new ChunkingException('oversized_indivisible_block', 'A Markdown block cannot fit within the configured Jarvis chunk size.');
                }
                $truncated = true;
                break;
            }
            if ($start + strlen($buffer) + $length > $policy->maxTotalBytes) {
                $flush();
                $truncated = true;
                break;
            }
            if ($buffer !== '' && strlen($buffer) + $length > $policy->maxChunkBytes) {
                $flush();
            }
            if (count($chunks) >= $policy->maxChunks) {
                $truncated = true;
                break;
            }
            $buffer .= $block;
        }
        if (!$truncated && $buffer !== '') {
            if (count($chunks) >= $policy->maxChunks) {
                $truncated = true;
            } else {
                $flush();
            }
        }
        if ($truncated && $policy->overflowStrategy === ChunkPolicy::FAIL) {
            throw new ChunkingException('chunk_count_exceeded', 'The supplied Markdown exceeds the configured Jarvis chunk-count limit.');
        }
        return new ChunkingResult($sourceHash, $sourceBytes, $included, $truncated, $chunks);
    }

    /** @return list<string> */
    private function blocks(string $content): array
    {
        preg_match_all('/.*(?:\R|\z)/u', $content, $matches);
        $lines = array_values(array_filter($matches[0], static fn (string $line): bool => $line !== ''));
        $blocks = [];
        $current = '';
        $inFence = false;
        $fence = '';
        $frontmatter = isset($lines[0]) && preg_match('/^---\h*(?:\R|$)/', $lines[0]) === 1;

        foreach ($lines as $index => $line) {
            $current .= $line;
            if ($frontmatter) {
                if ($index > 0 && preg_match('/^(?:---|\.\.\.)\h*(?:\R|$)/', $line) === 1) {
                    $blocks[] = $current;
                    $current = '';
                    $frontmatter = false;
                }
                continue;
            }
            if (!$inFence && preg_match('/^\h*(`{3,}|~{3,})/', $line, $match) === 1) {
                $inFence = true;
                $fence = $match[1][0];
                continue;
            }
            if ($inFence) {
                if (preg_match('/^\h*' . preg_quote($fence, '/') . '{3,}\h*(?:\R|$)/', $line) === 1) {
                    $inFence = false;
                    $fence = '';
                }
                continue;
            }
            if (trim($line) === '') {
                $blocks[] = $current;
                $current = '';
            }
        }
        if ($current !== '') $blocks[] = $current;
        return $blocks;
    }
}
