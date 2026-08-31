<?php

declare(strict_types=1);

$pluginDirectory = getenv('GRAV_JARVIS_PLUGIN_DIR') ?: dirname(__DIR__, 2) . '/plugins/grav-jarvis';
spl_autoload_register(static function (string $class) use ($pluginDirectory): void {
    $prefix = 'Grav\\Plugin\\GravJarvis\\';
    if (str_starts_with($class, $prefix)) {
        $file = $pluginDirectory . '/classes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) require_once $file;
    }
});

use Grav\Plugin\GravJarvis\Contracts\BudgetPolicy;
use Grav\Plugin\GravJarvis\Contracts\CachePolicy;
use Grav\Plugin\GravJarvis\Contracts\CompletionRequest;
use Grav\Plugin\GravJarvis\Contracts\CompletionResult;
use Grav\Plugin\GravJarvis\Contracts\Exception\BudgetExceededException;
use Grav\Plugin\GravJarvis\Contracts\Exception\ProviderAuthenticationException;
use Grav\Plugin\GravJarvis\Contracts\Exception\ProviderConfigurationException;
use Grav\Plugin\GravJarvis\Contracts\Exception\ProviderRateLimitException;
use Grav\Plugin\GravJarvis\Contracts\Exception\HttpTransportException;
use Grav\Plugin\GravJarvis\Contracts\ProviderInterface;
use Grav\Plugin\GravJarvis\Contracts\ReliabilityContext;
use Grav\Plugin\GravJarvis\Contracts\ReliabilityPolicy;
use Grav\Plugin\GravJarvis\Contracts\RetryPolicy;
use Grav\Plugin\GravJarvis\Contracts\Usage;
use Grav\Plugin\GravJarvis\Contracts\UsageReport;
use Grav\Plugin\GravJarvis\Provider\ProviderRegistry;
use Grav\Plugin\GravJarvis\Reliability\BudgetGuard;
use Grav\Plugin\GravJarvis\Reliability\CacheKeyFactory;
use Grav\Plugin\GravJarvis\Reliability\CostEstimator;
use Grav\Plugin\GravJarvis\Reliability\GravMarkdownChunker;
use Grav\Plugin\GravJarvis\Reliability\ModelPricing;
use Grav\Plugin\GravJarvis\Reliability\PricingCatalog;
use Grav\Plugin\GravJarvis\Reliability\RetryExecutor;
use Grav\Plugin\GravJarvis\Reliability\ReliabilityPolicyFactory;
use Grav\Plugin\GravJarvis\Reliability\TransientResponseCache;
use Grav\Plugin\GravJarvis\Security\SecretRedactor;
use Grav\Plugin\GravJarvis\Service\JarvisService;
use Grav\Plugin\GravJarvis\Service\ReliableJarvisService;
use Grav\Plugin\GravJarvis\Testing\DeterministicReliabilityRuntime;
use Grav\Plugin\GravJarvis\Testing\InMemoryResponseCache;

function expect(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function same(mixed $expected, mixed $actual, string $message): void { if ($expected !== $actual) throw new RuntimeException($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true)); }
function throws(callable $callback, string $class, string $message): Throwable { try { $callback(); } catch (Throwable $e) { if ($e instanceof $class) return $e; throw $e; } throw new RuntimeException($message); }

final class ReliabilityScenarioProvider implements ProviderInterface
{
    public int $calls = 0;
    /** @var Closure(int, CompletionRequest): CompletionResult */
    private Closure $scenario;
    public function __construct(private readonly string $providerId, Closure $scenario) { $this->scenario = $scenario; }
    public function id(): string { return $this->providerId; }
    public function capabilities(): array { return ['text-completion']; }
    public function complete(CompletionRequest $request): CompletionResult { $this->calls++; return ($this->scenario)($this->calls, $request); }
}

/** @return array{ReliableJarvisService, DeterministicReliabilityRuntime} */
function reliableService(ProviderInterface $provider, ?InMemoryResponseCache $cache = null, ?PricingCatalog $catalog = null, ?int &$epoch = null): array
{
    $registry = new ProviderRegistry(); $registry->register($provider);
    $base = new JarvisService($registry, SecretRedactor::fromEnvironment());
    $runtime = new DeterministicReliabilityRuntime(0, [7, 3, 0]);
    $costs = new CostEstimator($catalog ?? new PricingCatalog());
    $epoch ??= 1000;
    $clock = static function () use (&$epoch): int { return $epoch; };
    return [new ReliableJarvisService($base, new RetryExecutor($runtime), $cache ?? new InMemoryResponseCache(), new CacheKeyFactory(), $costs, new BudgetGuard($costs), new GravMarkdownChunker(), new ReliabilityPolicy(), $clock), $runtime];
}
function context(string $actor = 'user:a', string $page = '/one', string $action = 'rewrite', ?int $input = null, ?int $output = null, string $site = 'site:a'): ReliabilityContext
{
    return new ReliabilityContext($site, $actor, $page, 'operation:one', $action, $input, $output, $input === null ? 'unknown' : 'tokens');
}
function result(string $provider, string $model = 'model-a', string $output = 'deterministic'): CompletionResult
{
    return new CompletionResult($provider, $output, $model, new Usage(10, 4, 14, 'tokens', true));
}

$tests = [];
$tests['bounded retry behavior'] = static function (): void {
    $provider = new ReliabilityScenarioProvider('retry-fixture', static fn (int $call): CompletionResult => $call < 3
        ? throw new ProviderRateLimitException('bounded rate limit', $call === 1 ? 2 : null)
        : result('retry-fixture'));
    [$service, $runtime] = reliableService($provider);
    $policy = new ReliabilityPolicy(new RetryPolicy(true, 3, 6000, 100, 3000, 10));
    $answer = $service->completeReliable(new CompletionRequest('retry-fixture', 'prompt', 'model-a'), context(), $policy);
    same(3, $answer->usage->requestCount, 'Retry attempts were not reported.');
    same(2, $answer->usage->retryCount, 'Retry count was not reported.');
    same([2000, 203], $runtime->sleeps(), 'Retry-after and deterministic jitter were not normalized.');

    $timeout = new ReliabilityScenarioProvider('timeout-fixture', static fn (int $call): CompletionResult => $call === 1
        ? throw new HttpTransportException('fixture timed out')
        : result('timeout-fixture'));
    [$timeoutService] = reliableService($timeout);
    $timeoutAnswer = $timeoutService->completeReliable(new CompletionRequest('timeout-fixture', 'prompt'), context());
    same(1, $timeoutAnswer->usage->retryCount, 'A transient timeout did not succeed on a bounded later attempt.');

    $exhausted = new ReliabilityScenarioProvider('exhausted', static fn (): CompletionResult => throw new ProviderRateLimitException('still limited'));
    [$service] = reliableService($exhausted);
    throws(fn () => $service->completeReliable(new CompletionRequest('exhausted', 'prompt'), context(), new ReliabilityPolicy(new RetryPolicy(true, 2, 1000, 10, 100, 0))), \Grav\Plugin\GravJarvis\Contracts\Exception\ProviderFailureException::class, 'Retry exhaustion did not fail.');
    same(2, $exhausted->calls, 'Retry exhaustion exceeded its attempt bound.');

    $auth = new ReliabilityScenarioProvider('auth-failure', static fn (): CompletionResult => throw new ProviderAuthenticationException('denied'));
    [$service] = reliableService($auth);
    throws(fn () => $service->completeReliable(new CompletionRequest('auth-failure', 'prompt'), context()), \Grav\Plugin\GravJarvis\Contracts\Exception\ProviderFailureException::class, 'Authentication failure did not fail.');
    same(1, $auth->calls, 'A non-retryable authentication failure was retried.');
    $configuration = new ReliabilityScenarioProvider('config-failure', static fn (): CompletionResult => throw new ProviderConfigurationException('invalid configuration'));
    [$service] = reliableService($configuration);
    throws(fn () => $service->completeReliable(new CompletionRequest('config-failure', 'prompt'), context()), \Grav\Plugin\GravJarvis\Contracts\Exception\ProviderFailureException::class, 'Configuration failure did not fail.');
    same(1, $configuration->calls, 'A non-retryable configuration failure was retried.');
};

$tests['scoped optional response cache'] = static function (): void {
    $provider = new ReliabilityScenarioProvider('cache-fixture', static fn (int $call): CompletionResult => result('cache-fixture', 'model-a', 'answer-' . $call));
    $cache = new InMemoryResponseCache(); $epoch = 100;
    [$service] = reliableService($provider, $cache, null, $epoch);
    $policy = new ReliabilityPolicy(cache: new CachePolicy(true, 5, 3));
    $request = new CompletionRequest('cache-fixture', 'private prompt', 'model-a');
    $cacheKey = (new CacheKeyFactory())->key($request, context());
    expect(!str_contains($cacheKey, 'private prompt') && preg_match('/^[a-f0-9]{64}$/D', $cacheKey) === 1, 'Cache key exposed raw prompt material.');
    $first = $service->completeReliable($request, context(), $policy);
    $second = $service->completeReliable($request, context(), $policy);
    expect(!$first->usage->cacheHit && $second->usage->cacheHit, 'Cache miss/hit behavior failed.');
    same(1, $provider->calls, 'Cache hit made a provider request.');
    $service->completeReliable($request, context('user:b'), $policy);
    $service->completeReliable($request, context('user:a', '/two'), $policy);
    $service->completeReliable(new CompletionRequest('cache-fixture', 'private prompt', 'model-b'), context(), $policy);
    $service->completeReliable($request, context(site: 'site:b'), $policy);
    same(5, $provider->calls, 'Site, actor, page, or model cache scope leaked.');
    $otherProvider = new ReliabilityScenarioProvider('cache-other', static fn (): CompletionResult => result('cache-other', 'model-a', 'other-answer'));
    [$otherService] = reliableService($otherProvider, $cache, null, $epoch);
    $other = $otherService->completeReliable(new CompletionRequest('cache-other', 'private prompt', 'model-a'), context(), $policy);
    expect(!$other->usage->cacheHit && $otherProvider->calls === 1, 'Provider cache scope leaked.');
    $customPolicy = new ReliabilityPolicy(cache: new CachePolicy(true, 5, 3));
    $service->completeReliable($request, context(action: 'custom'), $customPolicy);
    $service->completeReliable($request, context(action: 'custom'), $customPolicy);
    same(7, $provider->calls, 'Custom prompts became cache eligible.');
    $epoch = 106;
    $service->completeReliable($request, context(), $policy);
    same(8, $provider->calls, 'Expired cache entry was reused.');
    expect($cache->count() <= 3, 'Cache capacity was not bounded.');

    $failureProvider = new ReliabilityScenarioProvider('cache-auth', static fn (): CompletionResult => throw new ProviderAuthenticationException('denied'));
    [$failureService] = reliableService($failureProvider, $cache, null, $epoch);
    $beforeFailure = $cache->count();
    throws(fn () => $failureService->completeReliable(new CompletionRequest('cache-auth', 'private prompt'), context(), $policy), \Grav\Plugin\GravJarvis\Contracts\Exception\ProviderFailureException::class, 'Authentication failure did not fail.');
    same($beforeFailure, $cache->count(), 'Authentication/configuration failure was cached.');
};

$tests['private file cache contains no request material'] = static function (): void {
    $directory = sys_get_temp_dir() . '/grav-jarvis-cache-' . bin2hex(random_bytes(6));
    try {
        $cache = new TransientResponseCache($directory);
        $cache->put(str_repeat('a', 64), str_repeat('b', 64), result('cache-file'), 10, 20, 2);
        $raw = implode('', array_map(static fn (string $file): string => (string) file_get_contents($file), glob($directory . '/*.json') ?: []));
        expect(!str_contains($raw, 'private prompt') && !str_contains($raw, 'api-key-secret'), 'Cache persisted raw request or credential material.');
        same('deterministic', $cache->get(str_repeat('a', 64), str_repeat('b', 64), 11)?->output, 'File cache fixture failed.');
    } finally {
        foreach (glob($directory . '/*') ?: [] as $file) @unlink($file);
        @rmdir($directory);
    }
};

$tests['usage and decimal-safe cost estimation'] = static function (): void {
    $catalog = new PricingCatalog('fixture-2026-01', '2026-01-01', 'USD', [
        new ModelPricing('openai', 'model-a', 'tokens', '1.5', '2.25'),
        new ModelPricing('anthropic', 'model-b', 'tokens', '3', '9'),
    ]);
    $costs = new CostEstimator($catalog);
    $known = $costs->estimate(new UsageReport('openai', 'model-a', 2, 3, 5, null, null, 'tokens', true, 1, 0, false));
    same('0.00000975', $known->estimatedAmount, 'Input/output pricing lost decimal precision.');
    $amplified = $costs->estimate(new UsageReport('anthropic', 'model-b', 100, 10, 110, null, null, 'tokens', true, 2, 1, false));
    same('0.00078', $amplified->estimatedAmount, 'Retry amplification was not represented.');
    expect(!$amplified->complete && $amplified->reason === 'retry_amplified_upper_estimate', 'Retry estimate was presented as authoritative.');
    same(null, $costs->estimate(new UsageReport('openai', 'unknown', null, null, null, null, null, 'unknown', false, 1, 0, false))->estimatedAmount, 'Unknown pricing or usage was falsely estimated.');
};

$tests['opt-in budgets block only known excesses'] = static function (): void {
    $catalog = new PricingCatalog('budget', null, 'USD', [new ModelPricing('budget-fixture', 'model-a', 'tokens', '100', '100')]);
    $provider = new ReliabilityScenarioProvider('budget-fixture', static fn (): CompletionResult => result('budget-fixture'));
    [$service] = reliableService($provider, null, $catalog);
    $request = new CompletionRequest('budget-fixture', str_repeat('x', 20), 'model-a');
    $inputPolicy = new ReliabilityPolicy(budget: new BudgetPolicy(true, maxInputBytes: 10));
    throws(fn () => $service->completeReliable($request, context(), $inputPolicy), BudgetExceededException::class, 'Input budget did not block before the provider call.');
    same(0, $provider->calls, 'Input budget called the provider before blocking.');
    $requestPolicy = new ReliabilityPolicy(budget: new BudgetPolicy(true, maxRequestCount: 0));
    throws(fn () => $service->completeReliable($request, context(), $requestPolicy), BudgetExceededException::class, 'Request budget did not block.');
    $costPolicy = new ReliabilityPolicy(budget: new BudgetPolicy(true, maxEstimatedCostPerRequest: '0.0001'));
    throws(fn () => $service->completeReliable($request, context(output: 1000, input: 1000), $costPolicy), BudgetExceededException::class, 'Known cost budget did not block.');
    $outputRequest = new CompletionRequest('budget-fixture', 'prompt', 'model-a', options: ['max_output_units' => 100]);
    throws(fn () => $service->completeReliable($outputRequest, context(), new ReliabilityPolicy(budget: new BudgetPolicy(true, maxEstimatedOutputUnits: 10))), BudgetExceededException::class, 'Known output budget did not block.');
    $unknown = $service->completeReliable($request, context(), new ReliabilityPolicy(budget: new BudgetPolicy(true, maxEstimatedCostPerRequest: '0')));
    expect($unknown->diagnostics['budget_cost_unknown'] === true, 'Unknown cost was not disclosed.');
    same(1, $provider->calls, 'An allowed unknown-cost request did not execute exactly once.');

    $retrying = new ReliabilityScenarioProvider('budget-retry', static fn (): CompletionResult => throw new ProviderRateLimitException('retry'));
    [$retryService] = reliableService($retrying);
    throws(fn () => $retryService->completeReliable(new CompletionRequest('budget-retry', 'prompt'), context(), new ReliabilityPolicy(new RetryPolicy(true, 3, 1000, 1, 2, 0), budget: new BudgetPolicy(true, maxRetryCount: 0))), BudgetExceededException::class, 'Retry budget did not stop amplification.');
    same(1, $retrying->calls, 'Retry budget allowed an extra provider call.');
    throws(fn () => ReliabilityPolicyFactory::fromArray(['retry' => ['max_attempts' => 'many']]), InvalidArgumentException::class, 'Malformed reliability configuration was silently coerced.');
};

foreach ($tests as $name => $test) { $test(); echo 'PASS: ' . $name . "\n"; }
echo 'Jarvis reliability contract passed (' . count($tests) . " checks).\n";
