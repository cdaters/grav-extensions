<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Reliability;

use Grav\Plugin\GravJarvis\Contracts\CompletionResult;
use Grav\Plugin\GravJarvis\Contracts\Exception\ProviderFailureException;
use Grav\Plugin\GravJarvis\Contracts\RetryPolicy;

final class RetryExecutor
{
    public function __construct(private readonly ReliabilityRuntimeInterface $runtime)
    {
    }

    /**
     * @param callable(int): CompletionResult $operation
     * @param callable(int): void $beforeAttempt
     */
    public function execute(callable $operation, RetryPolicy $policy, callable $beforeAttempt): RetryOutcome
    {
        $started = $this->runtime->nowMilliseconds();
        $attempt = 0;
        $delays = [];
        $maximumAttempts = $policy->enabled ? $policy->maxAttempts : 1;

        while ($attempt < $maximumAttempts) {
            $attempt++;
            $beforeAttempt($attempt);
            try {
                $result = $operation($attempt);
                return new RetryOutcome(
                    $result,
                    $attempt,
                    max(0, $this->runtime->nowMilliseconds() - $started),
                    $delays
                );
            } catch (ProviderFailureException $error) {
                if (!$policy->enabled || !$error->retryable || $attempt >= $maximumAttempts) {
                    throw $error;
                }

                $exponent = min(20, $attempt - 1);
                $backoff = min($policy->maxDelayMilliseconds, $policy->baseDelayMilliseconds * (2 ** $exponent));
                $delay = min(
                    $policy->maxDelayMilliseconds,
                    $backoff + $this->runtime->jitterMilliseconds($policy->maxJitterMilliseconds)
                );
                if ($error->retryAfterSeconds !== null) {
                    $delay = min($policy->maxDelayMilliseconds, max($delay, $error->retryAfterSeconds * 1000));
                }
                $elapsed = max(0, $this->runtime->nowMilliseconds() - $started);
                if ($elapsed + $delay > $policy->maxElapsedMilliseconds) {
                    throw $error;
                }
                $delays[] = $delay;
                $this->runtime->sleepMilliseconds($delay);
            }
        }

        throw new \LogicException('Jarvis retry execution ended without a result or failure.');
    }
}
