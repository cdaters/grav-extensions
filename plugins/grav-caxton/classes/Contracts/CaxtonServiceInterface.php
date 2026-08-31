<?php

declare(strict_types=1);

namespace Grav\Plugin\GravCaxton\Contracts;

interface CaxtonServiceInterface
{
    public function parse(string $source): SourceDocument;

    public function serialize(SourceDocument $document): string;

    public function replaceBlockText(
        SourceDocument $document,
        string $blockId,
        string $replacementText,
        string $expectedSourceHash
    ): EditResult;

    public function extensions(): ExtensionRegistryInterface;
}
