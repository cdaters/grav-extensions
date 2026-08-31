<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Contracts;

use InvalidArgumentException;

final readonly class LargeContextResult
{
    /** @param list<ReliableCompletionResult> $chunkResults */
    public function __construct(
        public ChunkingResult $chunking,
        public array $chunkResults,
        public ReliableCompletionResult $finalResult,
        public bool $synthesized
    ) {
        foreach ($chunkResults as $result) {
            if (!$result instanceof ReliableCompletionResult) {
                throw new InvalidArgumentException('Large-context results require reliable chunk results.');
            }
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'result' => $this->finalResult->toArray(),
            'chunking' => $this->chunking->provenance(),
            'chunk_request_count' => count($this->chunkResults),
            'synthesized' => $this->synthesized,
        ];
    }
}
