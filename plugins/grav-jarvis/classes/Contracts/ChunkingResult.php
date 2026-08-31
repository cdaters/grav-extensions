<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Contracts;

use InvalidArgumentException;

final readonly class ChunkingResult
{
    /** @param list<ContentChunk> $chunks */
    public function __construct(
        public string $sourceSha256,
        public int $sourceBytes,
        public int $includedBytes,
        public bool $truncated,
        public array $chunks
    ) {
        if (preg_match('/^[a-f0-9]{64}$/D', $sourceSha256) !== 1
            || $sourceBytes < 0 || $includedBytes < 0 || $includedBytes > $sourceBytes) {
            throw new InvalidArgumentException('Jarvis chunking result is invalid.');
        }
        foreach ($chunks as $index => $chunk) {
            if (!$chunk instanceof ContentChunk || $chunk->index !== $index || $chunk->sourceSha256 !== $sourceSha256) {
                throw new InvalidArgumentException('Jarvis chunks must have deterministic ordered provenance.');
            }
        }
    }

    /** @return array<string, mixed> */
    public function provenance(): array
    {
        return [
            'source_sha256' => $this->sourceSha256,
            'source_bytes' => $this->sourceBytes,
            'included_bytes' => $this->includedBytes,
            'truncated' => $this->truncated,
            'chunk_count' => count($this->chunks),
            'chunks' => array_map(static fn (ContentChunk $chunk): array => $chunk->provenance(), $this->chunks),
        ];
    }
}
