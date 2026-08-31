<?php

declare(strict_types=1);

namespace GravCommanderJarvisContract {
    use ArrayObject;
    use Closure;
    use Grav\Plugin\GravCommander\Jarvis\JarvisContextPolicy;
    use Grav\Plugin\GravCommander\Jarvis\JarvisIntegrationException;
    use Grav\Plugin\GravCommander\Jarvis\JarvisIntegrationService;
    use Grav\Plugin\GravCommander\Jarvis\JarvisProposalStore;
    use Grav\Plugin\GravCommander\Service\FileService;
    use Grav\Plugin\GravJarvis\Contracts\ChunkingResult;
    use Grav\Plugin\GravJarvis\Contracts\ChunkPolicy;
    use Grav\Plugin\GravJarvis\Contracts\CompletionRequest;
    use Grav\Plugin\GravJarvis\Contracts\CompletionResult;
    use Grav\Plugin\GravJarvis\Contracts\ContentChunk;
    use Grav\Plugin\GravJarvis\Contracts\CostEstimate;
    use Grav\Plugin\GravJarvis\Contracts\Exception\BudgetExceededException;
    use Grav\Plugin\GravJarvis\Contracts\Exception\ProviderFailureException;
    use Grav\Plugin\GravJarvis\Contracts\LargeContextResult;
    use Grav\Plugin\GravJarvis\Contracts\ModelCatalog;
    use Grav\Plugin\GravJarvis\Contracts\ModelDescriptor;
    use Grav\Plugin\GravJarvis\Contracts\ProviderRegistryInterface;
    use Grav\Plugin\GravJarvis\Contracts\ProviderValidationResult;
    use Grav\Plugin\GravJarvis\Contracts\ReliabilityContext;
    use Grav\Plugin\GravJarvis\Contracts\ReliabilityPolicy;
    use Grav\Plugin\GravJarvis\Contracts\ReliabilityServiceInterface;
    use Grav\Plugin\GravJarvis\Contracts\ReliableCompletionResult;
    use Grav\Plugin\GravJarvis\Contracts\Usage;
    use Grav\Plugin\GravJarvis\Contracts\UsageReport;
    use Grav\Plugin\GravJarvis\Contracts\ValidationIssue;
    use Grav\Plugin\GravJarvis\Provider\ProviderRegistry;
    use RuntimeException;
    use Throwable;

    $commanderDirectory = getenv('GRAV_COMMANDER_PLUGIN_DIR');
    $jarvisDirectory = getenv('GRAV_JARVIS_PLUGIN_DIR');
    $commanderDirectory = is_string($commanderDirectory) && $commanderDirectory !== ''
        ? rtrim($commanderDirectory, '/')
        : dirname(__DIR__, 2) . '/plugins/grav-commander';
    $jarvisDirectory = is_string($jarvisDirectory) && $jarvisDirectory !== ''
        ? rtrim($jarvisDirectory, '/')
        : dirname(__DIR__, 2) . '/plugins/grav-jarvis';

    spl_autoload_register(static function (string $class) use ($commanderDirectory, $jarvisDirectory): void {
        $roots = [
            'Grav\\Plugin\\GravCommander\\' => $commanderDirectory . '/classes/',
            'Grav\\Plugin\\GravJarvis\\' => $jarvisDirectory . '/classes/',
        ];
        foreach ($roots as $prefix => $root) {
            if (!str_starts_with($class, $prefix)) continue;
            $file = $root . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) require_once $file;
        }
    });

    final class FakeFiles extends FileService
    {
        public int $modified = 100;
        public function __construct(private bool $editable = true) {}
        public function read(string $root, string $path): array
        {
            if ($root !== 'pages' || str_contains($path, '..')) throw new RuntimeException('contained read denied');
            return [
                'root' => $root,
                'path' => trim($path, '/'),
                'name' => basename($path),
                'extension' => strtolower(pathinfo($path, PATHINFO_EXTENSION)),
                'editable' => $this->editable,
                'viewable' => true,
                'content' => 'disk snapshot',
                'modified' => $this->modified,
                'size' => 13,
            ];
        }
    }

    final class FixtureJarvis implements ReliabilityServiceInterface
    {
        public string $mode = 'ok';
        public int $completeCalls = 0;
        public int $largeCalls = 0;
        public ?CompletionRequest $lastRequest = null;
        private ProviderRegistryInterface $registry;

        public function __construct()
        {
            $this->registry = new ProviderRegistry();
        }

        public function complete(CompletionRequest $request): CompletionResult
        {
            return $this->completeReliable($request, new ReliabilityContext('site', 'actor', 'context', 'operation', 'review'))->completion;
        }
        public function providers(): ProviderRegistryInterface { return $this->registry; }
        public function providerIds(): array { return $this->mode === 'no-provider' ? [] : ['fixture']; }
        public function capabilities(string $providerId): array
        {
            if ($providerId !== 'fixture') throw new RuntimeException('missing provider');
            return $this->mode === 'unsupported' ? ['provider-validation'] : ['model-discovery', 'provider-validation', 'text-completion'];
        }
        public function validateProvider(string $providerId): ProviderValidationResult
        {
            if ($this->mode === 'missing-credential') {
                return new ProviderValidationResult($providerId, false, [
                    new ValidationIssue('credential_missing', 'fixture detail must not cross consumer boundary'),
                ], $this->capabilities($providerId));
            }
            if ($this->mode === 'invalid-config') {
                return new ProviderValidationResult($providerId, false, [
                    new ValidationIssue('configuration_invalid', 'fixture detail must not cross consumer boundary'),
                ], $this->capabilities($providerId));
            }
            return new ProviderValidationResult($providerId, true, capabilities: $this->capabilities($providerId));
        }
        public function discoverModels(string $providerId): ModelCatalog
        {
            return new ModelCatalog($providerId, [new ModelDescriptor('fixture-a', 'Fixture A', capabilities: ['text-completion'])]);
        }
        public function completeReliable(
            CompletionRequest $request,
            ReliabilityContext $context,
            ?ReliabilityPolicy $policy = null
        ): ReliableCompletionResult {
            $this->completeCalls++;
            $this->lastRequest = $request;
            if ($this->mode === 'rate') throw new ProviderFailureException('fixture', 'safe', 'rate_limited', true, 2);
            if ($this->mode === 'timeout') throw new ProviderFailureException('fixture', 'safe', 'timeout', true);
            if ($this->mode === 'malformed') throw new ProviderFailureException('fixture', 'safe', 'response_invalid', false);
            if ($this->mode === 'budget') throw new BudgetExceededException('request_count', 'The configured request budget has been reached.');
            $completion = new CompletionResult(
                'fixture',
                'fixture:' . substr(hash('sha256', $request->canonicalPayload()), 0, 20),
                $request->model ?? 'fixture-a',
                new Usage(strlen($request->input), 28, null, 'characters', false)
            );
            $usage = UsageReport::fromResult($completion, 2, 1, false);
            return new ReliableCompletionResult(
                $completion,
                $usage,
                new CostEstimate('USD', '0.000100', null, 'fixture-v1', '2026-01-01', true, 'fixture'),
                ['attempts' => 2, 'retry_count' => 1, 'cache_hit' => false]
            );
        }
        public function chunkMarkdown(string $content, ?ChunkPolicy $policy = null): ChunkingResult
        {
            $hash = hash('sha256', $content);
            return new ChunkingResult($hash, strlen($content), strlen($content), false, [
                new ContentChunk(0, $content, 0, strlen($content), $hash),
            ]);
        }
        public function summarizeLarge(
            CompletionRequest $request,
            ReliabilityContext $context,
            ?ReliabilityPolicy $policy = null
        ): LargeContextResult {
            $this->largeCalls++;
            $chunking = $this->chunkMarkdown($request->input, $policy?->chunking);
            $result = $this->completeReliable($request, $context, $policy);
            return new LargeContextResult($chunking, [$result], $result, false);
        }
        public function estimateCost(UsageReport $usage): CostEstimate
        {
            return new CostEstimate('USD', null, null, null, null, false, 'fixture');
        }
    }

    function expect(bool $condition, string $message): void
    {
        if (!$condition) throw new RuntimeException($message);
    }
    function same(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
    function throws(callable $callback, string $category, string $message): JarvisIntegrationException
    {
        try { $callback(); } catch (JarvisIntegrationException $error) {
            same($category, $error->category, $message . ' category mismatch.');
            return $error;
        }
        throw new RuntimeException($message);
    }
    function throwsRuntime(callable $callback, string $message): Throwable
    {
        try { $callback(); } catch (Throwable $error) { return $error; }
        throw new RuntimeException($message);
    }
    function removeTree(string $directory): void
    {
        foreach (glob($directory . '/*') ?: [] as $file) if (is_file($file)) @unlink($file);
        if (is_file($directory . '/.lock')) @unlink($directory . '/.lock');
        if (is_dir($directory)) @rmdir($directory);
    }

    $policy = new JarvisContextPolicy(4096, 16384);
    $normal = $policy->prepare('pages', 'guide.md', '# Guide', 'review');
    same(false, $normal['truncated'], 'Normal context should remain whole.');
    $secret = 'title: Safe' . "\n" . 'api_key: top-secret-fixture-value' . "\n" . 'body: Text';
    $redacted = $policy->prepare('pages', 'config.yaml', $secret, 'review');
    expect(!str_contains((string) $redacted['content'], 'top-secret-fixture-value'), 'Credential values must be redacted.');
    same(true, $redacted['redacted'], 'Redacted context must carry provenance.');
    $oversized = str_repeat("Paragraph text.\n", 400);
    same(true, $policy->prepare('pages', 'large.txt', $oversized, 'explain')['truncated'], 'Explain should disclose explicit bounded truncation.');
    throws(fn () => $policy->prepare('pages', 'large.txt', $oversized, 'improve'), 'unsafe_partial_rewrite', 'Partial rewrite must fail closed.');
    same(true, $policy->prepare('pages', 'large.md', $oversized, 'summarize')['large_summary'], 'Large Markdown summary should delegate to public chunking.');
    throws(fn () => $policy->prepare('config', '.env', 'PASSWORD=value', 'review'), 'sensitive_file', '.env must fail closed.');
    throws(fn () => $policy->prepare('pages', 'key.txt', '-----BEGIN PRIVATE KEY-----', 'review'), 'sensitive_file', 'Private keys must fail closed.');
    throws(fn () => $policy->prepare('pages', 'image.png', "PNG\0data", 'review'), 'unsupported_file', 'Binary media must fail closed.');
    echo "PASS: eligible context is bounded, redacted, format-aware, and fails closed for unsafe files\n";

    $now = 1000;
    $temp = sys_get_temp_dir() . '/grav-commander-jarvis-' . bin2hex(random_bytes(6));
    $clock = static function () use (&$now): int { return $now; };
    $store = new JarvisProposalStore($temp, Closure::fromCallable($clock));
    $receipt = $store->issue('user:alpha', 'pages:guide.md', hash('sha256', 'source'), hash('sha256', 'proposal'));
    throwsRuntime(
        fn () => $store->consume($receipt['id'], 'user:beta', 'pages:guide.md', hash('sha256', 'source'), hash('sha256', 'proposal')),
        'Cross-user proposal use must fail.'
    );
    throwsRuntime(
        fn () => $store->consume($receipt['id'], 'user:alpha', 'pages:other.md', hash('sha256', 'source'), hash('sha256', 'proposal')),
        'Cross-file proposal use must fail.'
    );
    throwsRuntime(
        fn () => $store->consume($receipt['id'], 'user:alpha', 'pages:guide.md', hash('sha256', 'changed'), hash('sha256', 'proposal')),
        'A stale buffer must fail.'
    );
    same(1, count(glob($temp . '/*.json') ?: []), 'Denied attempts must not consume a valid receipt.');
    $store->consume($receipt['id'], 'user:alpha', 'pages:guide.md', hash('sha256', 'source'), hash('sha256', 'proposal'));
    throwsRuntime(
        fn () => $store->consume($receipt['id'], 'user:alpha', 'pages:guide.md', hash('sha256', 'source'), hash('sha256', 'proposal')),
        'A consumed receipt must reject replay.'
    );
    $expired = $store->issue('user:alpha', 'pages:guide.md', hash('sha256', 'source'), hash('sha256', 'proposal'));
    $now += JarvisProposalStore::LIFETIME_SECONDS + 1;
    throwsRuntime(
        fn () => $store->consume($expired['id'], 'user:alpha', 'pages:guide.md', hash('sha256', 'source'), hash('sha256', 'proposal')),
        'An expired receipt must fail.'
    );
    echo "PASS: hash-only receipts deny cross-user, cross-file, stale, expired, and replayed application\n";

    $absent = new JarvisIntegrationService(
        new ArrayObject(),
        new FakeFiles(),
        $policy,
        $store,
        'site:fixture'
    );
    same(false, $absent->status()['available'], 'Commander must degrade when Jarvis is absent.');
    $invalid = new JarvisIntegrationService(
        new ArrayObject(['gravJarvis' => new \stdClass()]),
        new FakeFiles(),
        $policy,
        $store,
        'site:fixture'
    );
    same(false, $invalid->status()['available'], 'Commander must degrade for an invalid Jarvis service.');
    echo "PASS: Commander remains available when Jarvis is absent, disabled, or invalid\n";

    $jarvis = new FixtureJarvis();
    $fakeFiles = new FakeFiles();
    $integration = new JarvisIntegrationService(
        new ArrayObject(['gravJarvis' => $jarvis]),
        $fakeFiles,
        $policy,
        $store,
        'site:fixture'
    );
    $status = $integration->status();
    same(true, $status['available'], 'Public service discovery failed.');
    same('fixture', $status['providers'][0]['id'], 'Provider discovery was not provider-neutral.');
    same(4096, $status['limits']['context_bytes'], 'Status must report the configured direct context bound.');
    same(16384, $status['limits']['large_context_bytes'], 'Status must report the configured large-context bound.');
    $models = $integration->models('fixture');
    same('fixture-a', $models['models'][0]['id'], 'Model discovery did not preserve the neutral DTO.');
    same(true, $integration->validate('fixture')['usable'], 'Non-generating provider validation failed.');
    same(0, $jarvis->completeCalls, 'Validation/model discovery must not generate content.');
    echo "PASS: public service, provider validation, and model discovery contracts are consumed directly\n";

    foreach (['explain', 'summarize', 'review', 'improve', 'custom'] as $action) {
        $proposal = $integration->propose(
            'user:alpha',
            'pages',
            'guide.md',
            '# Unsaved source',
            $action,
            'fixture',
            'fixture-a',
            $action === 'custom' ? 'Return a concise replacement.' : null
        );
        same($action, $proposal['action'], "{$action} action identity changed.");
        expect(str_starts_with($proposal['output'], 'fixture:'), "{$action} did not return the deterministic fixture output.");
        same(2, $proposal['usage']['request_count'], "{$action} lost request reporting.");
        same(1, $proposal['usage']['retry_count'], "{$action} lost retry reporting.");
        same('0.000100', $proposal['cost']['estimated_amount'], "{$action} lost cost reporting.");
        if ($proposal['proposal_id'] !== null) {
            $integration->discard('user:alpha', $proposal['proposal_id'], 'pages', 'guide.md');
        }
    }
    echo "PASS: all bounded actions use provider-neutral reliability, usage, and cost contracts\n";

    $first = $integration->propose('user:alpha', 'pages', 'guide.md', 'source one', 'improve', 'fixture', null, null);
    same(true, $first['context']['accept_allowed'], 'Safe editable rewrite should issue a receipt.');
    $accepted = $integration->accept('user:alpha', $first['proposal_id'], 'pages', 'guide.md', 'source one', $first['output']);
    same($first['output'], $accepted['content'], 'Acceptance must return the proposed unsaved buffer.');
    throws(
        fn () => $integration->accept('user:alpha', $first['proposal_id'], 'pages', 'guide.md', 'source one', $first['output']),
        'proposal_conflict',
        'Accepted proposal replay must fail.'
    );
    $stale = $integration->propose('user:alpha', 'pages', 'guide.md', 'source two', 'improve', 'fixture', null, null);
    throws(
        fn () => $integration->accept('user:alpha', $stale['proposal_id'], 'pages', 'guide.md', 'changed source', $stale['output']),
        'proposal_conflict',
        'Changed unsaved source must reject a stale proposal.'
    );
    $integration->discard('user:alpha', $stale['proposal_id'], 'pages', 'guide.md');
    $otherUser = $integration->propose('user:alpha', 'pages', 'guide.md', 'source three', 'improve', 'fixture', null, null);
    throws(
        fn () => $integration->accept('user:beta', $otherUser['proposal_id'], 'pages', 'guide.md', 'source three', $otherUser['output']),
        'proposal_conflict',
        'Cross-user acceptance must fail.'
    );
    $integration->discard('user:alpha', $otherUser['proposal_id'], 'pages', 'guide.md');
    $externalChange = $integration->propose('user:alpha', 'pages', 'guide.md', 'source four', 'improve', 'fixture', null, null);
    $fakeFiles->modified++;
    throws(
        fn () => $integration->accept('user:alpha', $externalChange['proposal_id'], 'pages', 'guide.md', 'source four', $externalChange['output']),
        'proposal_conflict',
        'An externally changed file version must invalidate the proposal.'
    );
    $fakeFiles->modified--;
    $integration->discard('user:alpha', $externalChange['proposal_id'], 'pages', 'guide.md');
    echo "PASS: explicit Apply is buffer-only, one-time, actor-bound, file-bound, and stale-safe\n";

    $safeSecretContext = $integration->propose(
        'user:alpha', 'pages', 'settings.yaml', $secret, 'review', 'fixture', null, null
    );
    expect(!str_contains((string) $jarvis->lastRequest?->input, 'top-secret-fixture-value'), 'Secrets crossed the Jarvis request boundary.');
    same(false, $safeSecretContext['context']['accept_allowed'], 'Redacted contexts must never be applicable.');
    $large = $integration->propose('user:alpha', 'pages', 'large.md', $oversized, 'summarize', 'fixture', null, null);
    same(true, $large['context']['chunked'], 'Large Markdown summary did not use public Jarvis chunking.');
    same(1, $jarvis->largeCalls, 'Large-context service should run exactly once.');
    echo "PASS: redacted context cannot be applied and large Markdown delegates to Jarvis chunking\n";

    foreach ([
        'missing-credential' => 'credential_missing',
        'invalid-config' => 'configuration_invalid',
        'rate' => 'rate_limited',
        'timeout' => 'timeout',
        'malformed' => 'response_invalid',
        'budget' => 'budget_exceeded',
    ] as $mode => $category) {
        $jarvis->mode = $mode;
        throws(
            fn () => $integration->propose('user:alpha', 'pages', 'guide.md', 'source', 'review', 'fixture', null, null),
            $category,
            "{$mode} must degrade through a safe typed category."
        );
    }
    $jarvis->mode = 'unsupported';
    same('no-provider', $integration->status()['state'], 'Unsupported capability should not advertise an eligible provider.');
    $jarvis->mode = 'no-provider';
    same('no-provider', $integration->status()['state'], 'Missing provider should remain a graceful status.');
    $jarvis->mode = 'ok';
    echo "PASS: provider, credential, capability, rate, timeout, malformed, and budget failures degrade safely\n";

    $a = $integration->propose('user:alpha', 'pages', 'guide.md', 'deterministic source', 'review', 'fixture', null, null);
    $b = $integration->propose('user:alpha', 'pages', 'guide.md', 'deterministic source', 'review', 'fixture', null, null);
    same($a['output'], $b['output'], 'Offline integration output must be deterministic.');
    echo "PASS: deterministic fixture behavior requires no network or provider credential\n";

    $integrationSource = (string) file_get_contents($commanderDirectory . '/classes/Jarvis/JarvisIntegrationService.php');
    foreach (['\\Admin\\', '\\Reliability\\', '\\Provider\\', '\\Transport\\', '\\Testing\\', '\\Security\\', '\\Service\\Jarvis'] as $privateNamespace) {
        expect(!str_contains($integrationSource, 'GravJarvis' . $privateNamespace), "Commander imports private Jarvis namespace {$privateNamespace}.");
    }
    foreach (['responses', 'choices', 'messages', 'max_tokens', 'max_output_tokens', 'endpoint_url', 'authorization_header', 'environment_variable', 'api_key'] as $providerTerm) {
        expect(!str_contains(strtolower($integrationSource), $providerTerm), "Provider-specific authority leaked into Commander: {$providerTerm}.");
    }
    $controller = (string) file_get_contents($commanderDirectory . '/classes/Controller/ApiController.php');
    foreach (["'grav-commander.browse'", "'grav-commander.write'", "'grav-jarvis.use'"] as $permission) {
        expect(str_contains($controller, $permission), "Backend permission enforcement is missing {$permission}.");
    }
    expect(!str_contains($integrationSource, 'file_put_contents'), 'The integration service must not persist file content.');
    echo "PASS: integration has no private/provider coupling, authority fields, credentials, or content writes\n";

    removeTree($temp);
    echo "Grav Commander / Jarvis optional-consumer contract passed (10 checks).\n";
}
