<?php

declare(strict_types=1);

namespace Grav\Plugin\GravCaxton\Contracts;

use InvalidArgumentException;

final readonly class SourceBlock
{
    /** @param array<string, scalar|null> $metadata */
    public function __construct(
        public string $id,
        public string $type,
        public int $offset,
        public int $length,
        public string $source,
        public string $preservation,
        public bool $visualEditable,
        public array $metadata = []
    ) {
        if ($id === '' || !preg_match('/^[a-z][a-z0-9-]*$/', $id)) {
            throw new InvalidArgumentException('Block IDs must be stable lowercase identifiers.');
        }
        if ($type === '' || $offset < 0 || $length !== strlen($source)) {
            throw new InvalidArgumentException('Invalid source block coordinates.');
        }
        if (!in_array($preservation, ['byte-preserved', 'localized-edit', 'opaque', 'source-only'], true)) {
            throw new InvalidArgumentException('Unknown preservation class.');
        }
        if ($visualEditable && $preservation !== 'localized-edit') {
            throw new InvalidArgumentException('Only localized-edit blocks may be visually editable.');
        }
    }
}
