<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Contracts;

use InvalidArgumentException;

final readonly class ChunkPolicy
{
    public const FAIL = 'fail';
    public const TRUNCATE = 'truncate';

    public function __construct(
        public int $maxChunkBytes = 12288,
        public int $maxChunks = 16,
        public int $maxTotalBytes = 196608,
        public int $maxSynthesisBytes = 49152,
        public string $overflowStrategy = self::FAIL
    ) {
        if ($maxChunkBytes < 1024 || $maxChunkBytes > 65536
            || $maxChunks < 1 || $maxChunks > 64
            || $maxTotalBytes < $maxChunkBytes || $maxTotalBytes > 2097152
            || $maxSynthesisBytes < 1024 || $maxSynthesisBytes > 262144
            || !in_array($overflowStrategy, [self::FAIL, self::TRUNCATE], true)) {
            throw new InvalidArgumentException('Jarvis chunk policy is outside its safety bounds.');
        }
    }
}
