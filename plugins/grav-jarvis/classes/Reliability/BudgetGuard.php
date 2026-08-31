<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Reliability;

use Grav\Plugin\GravJarvis\Contracts\BudgetPolicy;
use Grav\Plugin\GravJarvis\Contracts\CompletionRequest;
use Grav\Plugin\GravJarvis\Contracts\Exception\BudgetExceededException;
use Grav\Plugin\GravJarvis\Contracts\ReliabilityContext;

final class BudgetGuard
{
    public function __construct(private readonly CostEstimator $costs)
    {
    }

    public function beforeAttempt(
        CompletionRequest $request,
        ReliabilityContext $context,
        BudgetPolicy $policy,
        BudgetState $state,
        int $attempt
    ): void {
        if ($attempt < 1) {
            throw new \LogicException('Jarvis budget attempts begin at one.');
        }

        if (!$policy->enabled) {
            $state->requestCount++;
            if ($attempt > 1) $state->retryCount++;
            return;
        }
        if ($policy->maxInputBytes !== null && strlen($request->input) > $policy->maxInputBytes) {
            throw $this->failure('input_bytes', 'The supplied content exceeds the configured Jarvis input budget.');
        }
        $estimatedOutput = $context->estimatedOutputUnits;
        if ($estimatedOutput === null && is_int($request->options['max_output_units'] ?? null)) {
            $estimatedOutput = $request->options['max_output_units'];
        }
        if ($policy->maxEstimatedOutputUnits !== null && $estimatedOutput !== null
            && $estimatedOutput > $policy->maxEstimatedOutputUnits) {
            throw $this->failure('output_units', 'The requested output exceeds the configured Jarvis output budget.');
        }
        $nextRequests = $state->requestCount + 1;
        $nextRetries = $state->retryCount + ($attempt > 1 ? 1 : 0);
        if ($policy->maxRequestCount !== null && $nextRequests > $policy->maxRequestCount) {
            throw $this->failure('request_count', 'The Jarvis request-count budget has been reached.');
        }
        if ($policy->maxRetryCount !== null && $nextRetries > $policy->maxRetryCount) {
            throw $this->failure('retry_count', 'The Jarvis retry budget has been reached.');
        }

        $projected = $this->costs->projectedNanos(
            $request->providerId,
            $request->model,
            $context->estimatedInputUnits,
            $estimatedOutput,
            $context->estimatedUnit
        );
        if ($projected === null) {
            $state->costUnknown = true;
        } else {
            if ($policy->maxEstimatedCostPerRequest !== null
                && $projected > DecimalMoney::parseNanos($policy->maxEstimatedCostPerRequest)) {
                throw $this->failure('request_cost', 'The request exceeds the configured Jarvis estimated-cost budget.');
            }
            if ($policy->maxEstimatedCostPerOperation !== null
                && $state->projectedCostNanos + $projected
                    > DecimalMoney::parseNanos($policy->maxEstimatedCostPerOperation)) {
                throw $this->failure('operation_cost', 'The operation exceeds the configured Jarvis estimated-cost budget.');
            }
            $state->projectedCostNanos += $projected;
        }
        $state->requestCount = $nextRequests;
        $state->retryCount = $nextRetries;
    }

    private function failure(string $code, string $message): BudgetExceededException
    {
        return new BudgetExceededException($code, $message);
    }
}
