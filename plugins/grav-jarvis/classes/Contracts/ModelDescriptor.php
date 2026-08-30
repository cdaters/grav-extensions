<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Contracts;

use InvalidArgumentException;

final readonly class ModelDescriptor
{
    public string $id;
    public string $label;
    public ?string $description;
    /** @var list<string> */
    public array $capabilities;
    public bool $available;

    /** @param list<string> $capabilities */
    public function __construct(
        string $id,
        ?string $label = null,
        ?string $description = null,
        array $capabilities = [],
        bool $available = true
    ) {
        $id = trim($id);
        $label = trim($label ?? $id);
        $description = $description === null ? null : trim($description);
        if ($id === '' || strlen($id) > 256 || self::containsUnsafeControlCharacter($id)) {
            throw new InvalidArgumentException('Model identifiers must be non-empty bounded plain text.');
        }
        if ($label === '' || strlen($label) > 256 || self::containsUnsafeControlCharacter($label)) {
            throw new InvalidArgumentException('Model labels must be non-empty bounded plain text.');
        }
        if ($description === '') {
            $description = null;
        }
        if ($description !== null && (strlen($description) > 2000 || self::containsUnsafeControlCharacter($description))) {
            throw new InvalidArgumentException('Model descriptions must be bounded plain text.');
        }
        foreach ($capabilities as $capability) {
            if (!is_string($capability) || !CompletionRequest::validIdentifier($capability)) {
                throw new InvalidArgumentException('Model capabilities must be lowercase stable slugs.');
            }
        }
        $capabilities = array_values(array_unique($capabilities));
        sort($capabilities, SORT_STRING);

        $this->id = $id;
        $this->label = $label;
        $this->description = $description;
        $this->capabilities = $capabilities;
        $this->available = $available;
    }

    /** @return array{id: string, label: string, description: ?string, capabilities: list<string>, available: bool} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'description' => $this->description,
            'capabilities' => $this->capabilities,
            'available' => $this->available,
        ];
    }

    private static function containsUnsafeControlCharacter(string $value): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/', $value) === 1;
    }
}
