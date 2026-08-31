<?php

declare(strict_types=1);

namespace Grav\Plugin\GravCaxton\Contracts;

use InvalidArgumentException;

final readonly class SourceDocument
{
    /**
     * @param list<SourceBlock> $blocks
     * @param list<ParseDiagnostic> $diagnostics
     */
    public function __construct(
        public string $source,
        public string $sourceHash,
        public string $newlineStyle,
        public int $byteLength,
        public array $blocks,
        public array $diagnostics = []
    ) {
        if (!preg_match('/^[a-f0-9]{64}$/', $sourceHash)) {
            throw new InvalidArgumentException('Source hash must be a lowercase SHA-256 digest.');
        }
        if ($byteLength !== strlen($source)) {
            throw new InvalidArgumentException('Document byte length does not match its source.');
        }
        if (!in_array($newlineStyle, ['none', 'lf', 'crlf', 'cr', 'mixed'], true)) {
            throw new InvalidArgumentException('Unknown newline style.');
        }
        foreach ($blocks as $block) {
            if (!$block instanceof SourceBlock) {
                throw new InvalidArgumentException('Every document block must be a SourceBlock.');
            }
        }
        foreach ($diagnostics as $diagnostic) {
            if (!$diagnostic instanceof ParseDiagnostic) {
                throw new InvalidArgumentException('Every diagnostic must be a ParseDiagnostic.');
            }
        }
    }

    public function block(string $id): ?SourceBlock
    {
        foreach ($this->blocks as $block) {
            if ($block->id === $id) {
                return $block;
            }
        }
        return null;
    }
}
