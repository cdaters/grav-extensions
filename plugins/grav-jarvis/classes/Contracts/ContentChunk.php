<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Contracts;

use InvalidArgumentException;

final readonly class ContentChunk
{
    public function __construct(
        public int $index,
        public string $content,
        public int $startByte,
        public int $endByte,
        public string $sourceSha256,
        public string $kind = 'markdown'
    ) {
        if ($index < 0 || $startByte < 0 || $endByte < $startByte
            || preg_match('/^[a-f0-9]{64}$/D', $sourceSha256) !== 1
            || !CompletionRequest::validIdentifier($kind)) {
            throw new InvalidArgumentException('Jarvis content chunk provenance is invalid.');
        }
    }

    /** @return array<string, int|string> */
    public function provenance(): array
    {
        return [
            'index' => $this->index,
            'start_byte' => $this->startByte,
            'end_byte' => $this->endByte,
            'content_bytes' => strlen($this->content),
            'content_sha256' => hash('sha256', $this->content),
            'source_sha256' => $this->sourceSha256,
            'kind' => $this->kind,
        ];
    }
}
