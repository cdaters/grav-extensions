<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Contracts\Exception;

final class ChunkingException extends JarvisException
{
    public function __construct(public readonly string $chunkingCode, string $safeMessage)
    {
        parent::__construct($safeMessage);
    }
}
