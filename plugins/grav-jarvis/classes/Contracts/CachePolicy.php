<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Contracts;

use InvalidArgumentException;

final readonly class CachePolicy
{
    /** @var list<string> */
    public array $eligibleActions;

    /** @param list<string> $eligibleActions */
    public function __construct(
        public bool $enabled = false,
        public int $ttlSeconds = 300,
        public int $maxEntries = 128,
        array $eligibleActions = ['expand', 'proofread', 'rewrite', 'shorten', 'summarize']
    ) {
        if ($ttlSeconds < 1 || $ttlSeconds > 86400 || $maxEntries < 1 || $maxEntries > 1024) {
            throw new InvalidArgumentException('Jarvis cache policy is outside its safety bounds.');
        }
        foreach ($eligibleActions as $action) {
            if (!is_string($action) || !CompletionRequest::validIdentifier($action) || $action === 'custom') {
                throw new InvalidArgumentException('Jarvis cache actions must be stable non-custom identifiers.');
            }
        }
        $eligibleActions = array_values(array_unique($eligibleActions));
        sort($eligibleActions, SORT_STRING);
        $this->eligibleActions = $eligibleActions;
    }

    public function eligible(ReliabilityContext $context): bool
    {
        return $this->enabled && in_array($context->actionId, $this->eligibleActions, true);
    }
}
