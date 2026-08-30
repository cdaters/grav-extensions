<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Contracts;

use InvalidArgumentException;

final readonly class ProviderValidationResult
{
    public string $providerId;
    public bool $usable;
    /** @var list<ValidationIssue> */
    public array $issues;
    /** @var list<string> */
    public array $capabilities;

    /**
     * @param list<ValidationIssue> $issues
     * @param list<string> $capabilities
     */
    public function __construct(
        string $providerId,
        bool $usable,
        array $issues = [],
        array $capabilities = []
    ) {
        $providerId = trim($providerId);
        if (!CompletionRequest::validIdentifier($providerId)) {
            throw new InvalidArgumentException('Provider identifiers must be lowercase stable slugs.');
        }
        foreach ($issues as $issue) {
            if (!$issue instanceof ValidationIssue) {
                throw new InvalidArgumentException('Validation results may contain only ValidationIssue values.');
            }
            if ($usable && $issue->severity === ValidationIssue::ERROR) {
                throw new InvalidArgumentException('A usable provider cannot contain an error issue.');
            }
        }
        $capabilities = self::normalizeCapabilities($capabilities);

        $this->providerId = $providerId;
        $this->usable = $usable;
        $this->issues = array_values($issues);
        $this->capabilities = $capabilities;
    }

    /** @return array{provider_id: string, usable: bool, issues: list<array<string, mixed>>, capabilities: list<string>} */
    public function toArray(): array
    {
        return [
            'provider_id' => $this->providerId,
            'usable' => $this->usable,
            'issues' => array_map(
                static fn (ValidationIssue $issue): array => $issue->toArray(),
                $this->issues
            ),
            'capabilities' => $this->capabilities,
        ];
    }

    /** @param list<string> $capabilities @return list<string> */
    private static function normalizeCapabilities(array $capabilities): array
    {
        foreach ($capabilities as $capability) {
            if (!is_string($capability) || !CompletionRequest::validIdentifier($capability)) {
                throw new InvalidArgumentException('Provider capabilities must be lowercase stable slugs.');
            }
        }
        $capabilities = array_values(array_unique($capabilities));
        sort($capabilities, SORT_STRING);
        return $capabilities;
    }
}
