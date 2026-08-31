<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Contracts;

interface ReliabilityServiceInterface extends ProviderIntrospectionServiceInterface
{
    public function completeReliable(
        CompletionRequest $request,
        ReliabilityContext $context,
        ?ReliabilityPolicy $policy = null
    ): ReliableCompletionResult;

    public function chunkMarkdown(string $content, ?ChunkPolicy $policy = null): ChunkingResult;

    public function summarizeLarge(
        CompletionRequest $request,
        ReliabilityContext $context,
        ?ReliabilityPolicy $policy = null
    ): LargeContextResult;

    public function estimateCost(UsageReport $usage): CostEstimate;
}
