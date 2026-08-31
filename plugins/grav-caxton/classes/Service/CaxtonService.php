<?php

declare(strict_types=1);

namespace Grav\Plugin\GravCaxton\Service;

use Grav\Plugin\GravCaxton\Contracts\CaxtonServiceInterface;
use Grav\Plugin\GravCaxton\Contracts\EditResult;
use Grav\Plugin\GravCaxton\Contracts\Exception\BlockNotEditableException;
use Grav\Plugin\GravCaxton\Contracts\Exception\BlockNotFoundException;
use Grav\Plugin\GravCaxton\Contracts\Exception\InvalidSourceException;
use Grav\Plugin\GravCaxton\Contracts\Exception\StaleSourceException;
use Grav\Plugin\GravCaxton\Contracts\ExtensionRegistryInterface;
use Grav\Plugin\GravCaxton\Contracts\SourceDocument;
use Grav\Plugin\GravCaxton\Document\SourceDocumentParser;

final class CaxtonService implements CaxtonServiceInterface
{
    public function __construct(
        private readonly SourceDocumentParser $parser,
        private readonly ExtensionRegistryInterface $extensionRegistry
    ) {
    }

    public function parse(string $source): SourceDocument
    {
        return $this->parser->parse($source);
    }

    public function serialize(SourceDocument $document): string
    {
        $serialized = '';
        $expectedOffset = 0;
        foreach ($document->blocks as $block) {
            if ($block->offset !== $expectedOffset || $block->length !== strlen($block->source)) {
                throw new InvalidSourceException('Caxton source blocks do not provide contiguous coverage.');
            }
            $serialized .= $block->source;
            $expectedOffset += $block->length;
        }
        if ($expectedOffset !== $document->byteLength
            || !hash_equals($document->sourceHash, hash('sha256', $serialized))
            || $serialized !== $document->source
        ) {
            throw new InvalidSourceException('Caxton document integrity verification failed.');
        }
        return $serialized;
    }

    public function replaceBlockText(
        SourceDocument $document,
        string $blockId,
        string $replacementText,
        string $expectedSourceHash
    ): EditResult {
        if (!hash_equals($document->sourceHash, $expectedSourceHash)
            || !hash_equals($document->sourceHash, hash('sha256', $document->source))
        ) {
            throw new StaleSourceException('Caxton refused an edit against stale source.');
        }
        $block = $document->block($blockId);
        if ($block === null) {
            throw new BlockNotFoundException('Caxton source block was not found.');
        }
        if (!$block->visualEditable || !in_array($block->type, ['heading', 'paragraph'], true)) {
            throw new BlockNotEditableException('This source block is not editable through the visual contract.');
        }
        if (strlen($replacementText) > 65_536 || str_contains($replacementText, "\0")
            || str_contains($replacementText, "\r") || str_contains($replacementText, "\n")
        ) {
            throw new InvalidSourceException('Replacement text must be a bounded single line without control delimiters.');
        }
        $replacementText = trim($replacementText, " \t");
        if ($replacementText === '' || preg_match('/[`*_{}\[\]<>|!\\\\]/', $replacementText) === 1) {
            throw new InvalidSourceException('Replacement text is outside the 0.1.0 safe plain-text subset.');
        }

        $ending = '';
        if (preg_match('/(\r\n|\r|\n)$/', $block->source, $match) === 1) {
            $ending = $match[1];
        }
        if ($block->type === 'heading') {
            $level = (int) ($block->metadata['level'] ?? 0);
            if ($level < 1 || $level > 6) {
                throw new InvalidSourceException('Heading metadata is invalid.');
            }
            $replacementSource = str_repeat('#', $level) . ' ' . $replacementText . $ending;
        } else {
            $replacementSource = $replacementText . $ending;
        }

        $source = substr($document->source, 0, $block->offset)
            . $replacementSource
            . substr($document->source, $block->offset + $block->length);

        return new EditResult(
            $this->parser->parse($source),
            $blockId,
            $document->sourceHash
        );
    }

    public function extensions(): ExtensionRegistryInterface
    {
        return $this->extensionRegistry;
    }
}
