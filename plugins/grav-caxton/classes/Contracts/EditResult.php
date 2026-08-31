<?php

declare(strict_types=1);

namespace Grav\Plugin\GravCaxton\Contracts;

final readonly class EditResult
{
    public function __construct(
        public SourceDocument $document,
        public string $replacedBlockId,
        public string $previousSourceHash
    ) {
    }
}
