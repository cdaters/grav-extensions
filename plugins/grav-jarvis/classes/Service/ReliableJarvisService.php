<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Service;

use Grav\Plugin\GravJarvis\Contracts\ChunkPolicy;
use Grav\Plugin\GravJarvis\Contracts\ChunkingResult;
use Grav\Plugin\GravJarvis\Contracts\CompletionRequest;
use Grav\Plugin\GravJarvis\Contracts\CompletionResult;
use Grav\Plugin\GravJarvis\Contracts\CostEstimate;
use Grav\Plugin\GravJarvis\Contracts\Exception\ChunkingException;
use Grav\Plugin\GravJarvis\Contracts\LargeContextResult;
use Grav\Plugin\GravJarvis\Contracts\ModelCatalog;
use Grav\Plugin\GravJarvis\Contracts\ProviderIntrospectionServiceInterface;
use Grav\Plugin\GravJarvis\Contracts\ProviderRegistryInterface;
use Grav\Plugin\GravJarvis\Contracts\ProviderValidationResult;
use Grav\Plugin\GravJarvis\Contracts\ReliabilityContext;
use Grav\Plugin\GravJarvis\Contracts\ReliabilityPolicy;
use Grav\Plugin\GravJarvis\Contracts\ReliabilityServiceInterface;
use Grav\Plugin\GravJarvis\Contracts\ReliableCompletionResult;
use Grav\Plugin\GravJarvis\Contracts\ResponseCacheInterface;
use Grav\Plugin\GravJarvis\Contracts\UsageReport;
use Grav\Plugin\GravJarvis\Reliability\BudgetGuard;
use Grav\Plugin\GravJarvis\Reliability\BudgetState;
use Grav\Plugin\GravJarvis\Reliability\CacheKeyFactory;
use Grav\Plugin\GravJarvis\Reliability\CostEstimator;
use Grav\Plugin\GravJarvis\Reliability\GravMarkdownChunker;
use Grav\Plugin\GravJarvis\Reliability\RetryExecutor;

final class ReliableJarvisService implements ReliabilityServiceInterface
{
    /** @var \Closure(): int */
    private readonly \Closure $clock;

    public function __construct(
        private readonly ProviderIntrospectionServiceInterface $base,
        private readonly RetryExecutor $retries,
        private readonly ResponseCacheInterface $cache,
        private readonly CacheKeyFactory $keys,
        private readonly CostEstimator $costs,
        private readonly BudgetGuard $budgets,
        private readonly GravMarkdownChunker $chunker,
        private readonly ReliabilityPolicy $defaults,
        ?\Closure $clock = null
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function complete(CompletionRequest $request): CompletionResult { return $this->base->complete($request); }
    public function providers(): ProviderRegistryInterface { return $this->base->providers(); }
    public function providerIds(): array { return $this->base->providerIds(); }
    public function capabilities(string $providerId): array { return $this->base->capabilities($providerId); }
    public function validateProvider(string $providerId): ProviderValidationResult { return $this->base->validateProvider($providerId); }
    public function discoverModels(string $providerId): ModelCatalog { return $this->base->discoverModels($providerId); }

    public function completeReliable(
        CompletionRequest $request,
        ReliabilityContext $context,
        ?ReliabilityPolicy $policy = null
    ): ReliableCompletionResult {
        return $this->execute($request, $context, $policy ?? $this->defaults, new BudgetState());
    }

    public function chunkMarkdown(string $content, ?ChunkPolicy $policy = null): ChunkingResult
    {
        return $this->chunker->chunk($content, $policy ?? $this->defaults->chunking);
    }

    public function summarizeLarge(
        CompletionRequest $request,
        ReliabilityContext $context,
        ?ReliabilityPolicy $policy = null
    ): LargeContextResult {
        if ($context->actionId !== 'summarize') {
            throw new ChunkingException('action_not_supported', 'Large-context execution is currently limited to summarization.');
        }
        $policy ??= $this->defaults;
        $chunking = $this->chunker->chunk($request->input, $policy->chunking);
        if ($chunking->truncated) {
            throw new ChunkingException('truncated_source', 'Jarvis will not summarize a partially included source.');
        }
        if (count($chunking->chunks) <= 1) {
            $single = $this->completeReliable($request, $context, $policy);
            return new LargeContextResult($chunking, [$single], $single, false);
        }

        $state = new BudgetState();
        $partial = [];
        $summaries = [];
        foreach ($chunking->chunks as $chunk) {
            $chunkRequest = new CompletionRequest(
                $request->providerId,
                $chunk->content,
                $request->model,
                $this->appendInstruction($request->instructions, 'Summarize this numbered source chunk faithfully. Do not infer missing context.'),
                $request->options,
                [...$request->metadata, 'chunk_index' => $chunk->index, 'chunk_count' => count($chunking->chunks), 'source_sha256' => $chunking->sourceSha256]
            );
            $chunkContext = new ReliabilityContext(
                $context->siteScope,
                $context->actorScope,
                $context->contextScope,
                $context->operationId . ':chunk:' . $chunk->index,
                'summarize',
                $context->estimatedInputUnits,
                $context->estimatedOutputUnits,
                $context->estimatedUnit
            );
            $result = $this->execute($chunkRequest, $chunkContext, $policy, $state);
            $partial[] = $result;
            $summaries[] = 'Chunk ' . ($chunk->index + 1) . ":\n" . $result->completion->output;
        }
        $synthesisInput = implode("\n\n", $summaries);
        if (strlen($synthesisInput) > $policy->chunking->maxSynthesisBytes) {
            throw new ChunkingException('synthesis_limit_exceeded', 'The partial summaries exceed the bounded Jarvis synthesis limit.');
        }
        $finalRequest = new CompletionRequest(
            $request->providerId,
            $synthesisInput,
            $request->model,
            $this->appendInstruction($request->instructions, 'Combine the numbered partial summaries into one faithful final summary. Return only that summary.'),
            $request->options,
            [...$request->metadata, 'chunk_synthesis' => true, 'chunk_count' => count($chunking->chunks), 'source_sha256' => $chunking->sourceSha256]
        );
        $finalContext = new ReliabilityContext(
            $context->siteScope,
            $context->actorScope,
            $context->contextScope,
            $context->operationId . ':synthesis',
            'summarize',
            $context->estimatedInputUnits,
            $context->estimatedOutputUnits,
            $context->estimatedUnit
        );
        $final = $this->execute($finalRequest, $finalContext, $policy, $state);
        return new LargeContextResult($chunking, $partial, $final, true);
    }

    public function estimateCost(UsageReport $usage): CostEstimate { return $this->costs->estimate($usage); }

    private function execute(
        CompletionRequest $request,
        ReliabilityContext $context,
        ReliabilityPolicy $policy,
        BudgetState $state
    ): ReliableCompletionResult {
        $now = ($this->clock)();
        $eligible = $policy->cache->eligible($context);
        $key = $this->keys->key($request, $context);
        if ($eligible) {
            try {
                $cached = $this->cache->get($key, $context->scopeHash(), $now);
                if ($cached !== null) {
                    $usage = UsageReport::fromResult($cached, 0, 0, true);
                    return new ReliableCompletionResult($cached, $usage, $this->costs->estimate($usage), $this->diagnostics($context, 0, 0, 0, true, $state));
                }
            } catch (\Throwable) {
                // Cache failure must never make an otherwise valid provider request unavailable.
            }
        }

        $outcome = $this->retries->execute(
            fn (): CompletionResult => $this->base->complete($request),
            $policy->retry,
            fn (int $attempt): mixed => $this->budgets->beforeAttempt($request, $context, $policy->budget, $state, $attempt)
        );
        $usage = UsageReport::fromResult($outcome->result, $outcome->attempts, max(0, $outcome->attempts - 1));
        if ($eligible) {
            try {
                $this->cache->put($key, $context->scopeHash(), $outcome->result, $now, $now + $policy->cache->ttlSeconds, $policy->cache->maxEntries);
            } catch (\Throwable) {
                // Cache writes are opportunistic and cannot fail a successful completion.
            }
        }
        return new ReliableCompletionResult(
            $outcome->result,
            $usage,
            $this->costs->estimate($usage),
            $this->diagnostics($context, $outcome->attempts, max(0, $outcome->attempts - 1), $outcome->elapsedMilliseconds, false, $state)
        );
    }

    /** @return array<string, bool|int|string|null> */
    private function diagnostics(ReliabilityContext $context, int $attempts, int $retries, int $elapsed, bool $hit, BudgetState $state): array
    {
        return [
            ...$context->diagnosticArray(),
            'attempts' => $attempts,
            'retry_count' => $retries,
            'elapsed_ms' => $elapsed,
            'cache_hit' => $hit,
            'budget_request_count' => $state->requestCount,
            'budget_retry_count' => $state->retryCount,
            'budget_cost_unknown' => $state->costUnknown,
        ];
    }

    private function appendInstruction(?string $existing, string $instruction): string
    {
        return $existing === null ? $instruction : $existing . "\n\n" . $instruction;
    }
}
