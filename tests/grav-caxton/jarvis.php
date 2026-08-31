<?php

declare(strict_types=1);

use Grav\Plugin\GravCaxton\Jarvis\CaxtonJarvisException;
use Grav\Plugin\GravCaxton\Jarvis\CaxtonJarvisService;
use Grav\Plugin\GravCaxton\Jarvis\CaxtonProposalStore;
use Grav\Plugin\GravJarvis\Contracts\ChunkingResult;
use Grav\Plugin\GravJarvis\Contracts\ChunkPolicy;
use Grav\Plugin\GravJarvis\Contracts\CompletionRequest;
use Grav\Plugin\GravJarvis\Contracts\CompletionResult;
use Grav\Plugin\GravJarvis\Contracts\CostEstimate;
use Grav\Plugin\GravJarvis\Contracts\JarvisServiceInterface;
use Grav\Plugin\GravJarvis\Contracts\LargeContextResult;
use Grav\Plugin\GravJarvis\Contracts\ModelCatalog;
use Grav\Plugin\GravJarvis\Contracts\ModelDescriptor;
use Grav\Plugin\GravJarvis\Contracts\ProviderIntrospectionServiceInterface;
use Grav\Plugin\GravJarvis\Contracts\ProviderRegistryInterface;
use Grav\Plugin\GravJarvis\Contracts\ProviderValidationResult;
use Grav\Plugin\GravJarvis\Contracts\ReliabilityContext;
use Grav\Plugin\GravJarvis\Contracts\ReliabilityPolicy;
use Grav\Plugin\GravJarvis\Contracts\ReliabilityServiceInterface;
use Grav\Plugin\GravJarvis\Contracts\ReliableCompletionResult;
use Grav\Plugin\GravJarvis\Contracts\Usage;
use Grav\Plugin\GravJarvis\Contracts\UsageReport;

$root = getenv('GRAV_EXTENSIONS_ROOT');
if (!is_string($root) || $root === '') $root = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($root): void {
    foreach ([
        'Grav\\Plugin\\GravCaxton\\' => $root . '/plugins/grav-caxton/classes/',
        'Grav\\Plugin\\GravJarvis\\' => $root . '/plugins/grav-jarvis/classes/',
    ] as $prefix => $directory) {
        if (!str_starts_with($class, $prefix)) continue;
        $file = $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) require_once $file;
    }
});

final class CaxtonFakeJarvis implements ReliabilityServiceInterface
{
    /** @var list<string> */
    public array $ids = ['fixture'];
    public ?string $failureCategory = null;
    public string $output = 'Polished target.';
    public ?CompletionRequest $lastRequest = null;

    public function complete(CompletionRequest $request): CompletionResult
    {
        return $this->completeReliable($request, new ReliabilityContext('site', 'actor', 'context', 'operation', 'rewrite'))->completion;
    }

    public function providers(): ProviderRegistryInterface
    {
        return new \Grav\Plugin\GravJarvis\Provider\ProviderRegistry();
    }

    public function providerIds(): array { return $this->ids; }
    public function capabilities(string $providerId): array { return ['model-discovery', 'provider-validation', 'text-completion']; }
    public function validateProvider(string $providerId): ProviderValidationResult { return new ProviderValidationResult('fixture', true, [], $this->capabilities($providerId)); }
    public function discoverModels(string $providerId): ModelCatalog { return new ModelCatalog('fixture', [new ModelDescriptor('fixture-model', 'Fixture Model')]); }

    public function completeReliable(CompletionRequest $request, ReliabilityContext $context, ?ReliabilityPolicy $policy = null): ReliableCompletionResult
    {
        $this->lastRequest = $request;
        if ($this->failureCategory === 'budget_exceeded') throw new \Grav\Plugin\GravJarvis\Contracts\Exception\BudgetExceededException('fixture-budget', 'The deterministic fixture budget is exhausted.');
        if ($this->failureCategory !== null) throw new \Grav\Plugin\GravJarvis\Contracts\Exception\ProviderFailureException('fixture', 'offline fixture failure', $this->failureCategory, true, $this->failureCategory === 'rate_limited' ? 7 : null);
        $completion = new CompletionResult('fixture', $this->output, $request->model ?? 'fixture-model', new Usage(10, 3, 13, 'tokens', true));
        return new ReliableCompletionResult(
            $completion,
            UsageReport::fromResult($completion),
            new CostEstimate('USD', '0.001300', null, 'fixture-v1', '2026-08-31', true, 'fixture'),
            ['attempts' => 1, 'cache_hit' => false]
        );
    }

    public function chunkMarkdown(string $content, ?ChunkPolicy $policy = null): ChunkingResult { throw new LogicException('unused'); }
    public function summarizeLarge(CompletionRequest $request, ReliabilityContext $context, ?ReliabilityPolicy $policy = null): LargeContextResult { throw new LogicException('unused'); }
    public function estimateCost(UsageReport $usage): CostEstimate { return new CostEstimate('USD', null, null, null, null, false, 'fixture'); }
}

function cxExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

/** @param class-string<Throwable> $class */
function cxThrows(string $class, callable $callback, string $message): Throwable
{
    try { $callback(); }
    catch (Throwable $error) {
        if ($error instanceof $class) return $error;
        throw new RuntimeException($message . ' Wrong exception: ' . $error::class);
    }
    throw new RuntimeException($message . ' No exception was thrown.');
}

$directory = sys_get_temp_dir() . '/caxton-jarvis-' . bin2hex(random_bytes(8));
$fake = new CaxtonFakeJarvis();
$container = new ArrayObject(['gravJarvis' => $fake]);
$service = new CaxtonJarvisService($container, new CaxtonProposalStore($directory), 'site:test');
$status = $service->status();
cxExpect($status['state'] === 'ready' && count($status['actions']) === 7, 'Jarvis status must expose the bounded action library.');
cxExpect($service->models('fixture')['models'][0]['id'] === 'fixture-model', 'Model discovery must remain provider neutral.');
cxExpect($service->validate('fixture')['usable'] === true, 'Provider validation must be used before generation.');

$source = "Intro 😀.\n\nTarget text.\n\nAfter.\n";
$sourceHash = hash('sha256', $source);
$from = strpos($source, 'Target text.');
$to = $from + strlen('Target text.');
$proposal = $service->propose(
    'user:alice', '/article', $source, $sourceHash, $from, $to, 0, strlen($source),
    'paragraph', 'rewrite', 'fixture', null, null
);
cxExpect($proposal['output'] === 'Polished target.' && $proposal['accept_allowed'] === true, 'Rewrite must create a previewable mutable proposal.');
cxExpect($proposal['usage']['total'] === 13 && $proposal['cost']['estimated_amount'] === '0.001300', 'Usage and cost must be returned.');
cxExpect($fake->lastRequest?->metadata['surface'] === 'grav-caxton', 'Caxton must call only the public provider-neutral completion contract.');
$receipt = (string) file_get_contents($directory . '/' . $proposal['proposal_id'] . '.json');
foreach ([$source, 'Target text.', 'Polished target.', 'alice', '/article'] as $secret) {
    cxExpect(!str_contains($receipt, $secret), 'Proposal receipts must remain hash-only and disclose no content or identity.');
}
$accepted = $service->accept('user:alice', $proposal['proposal_id'], '/article', $source, $sourceHash, $from, $to, $proposal['output']);
cxExpect($accepted['from'] === $from && $accepted['replacement'] === 'Polished target.', 'Accept must return only the bound unsaved-buffer patch.');
cxThrows(CaxtonJarvisException::class, static fn () => $service->accept('user:alice', $proposal['proposal_id'], '/article', $source, $sourceHash, $from, $to, $proposal['output']), 'Receipts must be one-time.');

$stale = $service->propose('user:alice', '/article', $source, $sourceHash, $from, $to, 0, strlen($source), 'paragraph', 'proofread', 'fixture', null, null);
cxThrows(CaxtonJarvisException::class, static fn () => $service->accept('user:bob', $stale['proposal_id'], '/article', $source, $sourceHash, $from, $to, $stale['output']), 'Cross-user accept must fail.');
cxThrows(CaxtonJarvisException::class, static fn () => $service->accept('user:alice', $stale['proposal_id'], '/other', $source, $sourceHash, $from, $to, $stale['output']), 'Cross-page accept must fail.');
cxThrows(CaxtonJarvisException::class, static fn () => $service->accept('user:alice', $stale['proposal_id'], '/article', $source . 'changed', hash('sha256', $source . 'changed'), $from, $to, $stale['output']), 'Stale source must fail.');

$explain = $service->propose('user:alice', '/article', $source, $sourceHash, $from, $to, 0, strlen($source), 'paragraph', 'explain', 'fixture', null, null);
cxExpect($explain['mode'] === 'read-only' && $explain['accept_allowed'] === false, 'Explain must remain read-only.');
cxThrows(CaxtonJarvisException::class, static fn () => $service->accept('user:alice', $explain['proposal_id'], '/article', $source, $sourceHash, $from, $to, $explain['output']), 'Read-only output must not be accepted.');
foreach (['proofread', 'shorten', 'expand', 'summarize', 'custom'] as $action) {
    $customInstruction = $action === 'custom' ? 'Make the selected prose friendlier.' : null;
    $actionProposal = $service->propose(
        'user:alice', '/article', $source, $sourceHash, $from, $to, 0, strlen($source),
        'paragraph', $action, 'fixture', 'fixture-model', $customInstruction
    );
    cxExpect($actionProposal['action'] === $action, "{$action} must use the bounded action contract.");
    cxExpect(
        $actionProposal['accept_allowed'] === ($action !== 'summarize'),
        "{$action} must expose the correct proposal/read-only mode."
    );
    if ($action === 'custom') {
        cxExpect(str_contains((string) $fake->lastRequest?->instructions, $customInstruction), 'Custom instruction must remain separate from page input.');
        cxExpect(!str_contains($fake->lastRequest?->input ?? '', $customInstruction), 'Custom instruction must not be merged ambiguously into page content.');
    }
    $service->discard('user:alice', $actionProposal['proposal_id'], '/article');
}
cxThrows(CaxtonJarvisException::class, static fn () => $service->propose('user:alice', '/article', $source, $sourceHash, $from, $to, 0, strlen($source), 'paragraph', 'custom', 'fixture', null, ''), 'Custom Prompt must require an explicit bounded instruction.');
cxThrows(CaxtonJarvisException::class, static fn () => $service->propose('user:alice', '/article', $source, $sourceHash, 0, 5, 0, strlen($source), 'code_block', 'rewrite', 'fixture', null, null), 'Protected block kinds must fail closed.');
$fake->output = '<script>alert(1)</script>';
cxExpect(cxThrows(CaxtonJarvisException::class, static fn () => $service->propose('user:alice', '/article', $source, $sourceHash, $from, $to, 0, strlen($source), 'paragraph', 'rewrite', 'fixture', null, null), 'Executable provider output must fail closed.')->category === 'response_invalid', 'Raw HTML must never enter the proposal receipt.');
$fake->output = '[unsafe](javascript:alert(1))';
cxExpect(cxThrows(CaxtonJarvisException::class, static fn () => $service->propose('user:alice', '/article', $source, $sourceHash, $from, $to, 0, strlen($source), 'paragraph', 'rewrite', 'fixture', null, null), 'Unsafe provider links must fail closed.')->category === 'response_invalid', 'Unsafe links must never enter the proposal receipt.');
$fake->output = '{% include "remote" %}';
cxExpect(cxThrows(CaxtonJarvisException::class, static fn () => $service->propose('user:alice', '/article', $source, $sourceHash, $from, $to, 0, strlen($source), 'paragraph', 'rewrite', 'fixture', null, null), 'Provider Twig must fail closed.')->category === 'response_invalid', 'Twig must never enter the proposal receipt.');
$fake->output = 'Polished target.';
$code = "```php\necho 'safe';\n```";
$codeExplain = $service->propose('user:alice', '/article', $code, hash('sha256', $code), 0, strlen($code), 0, strlen($code), 'code_block', 'explain', 'fixture', null, null);
cxExpect($codeExplain['mode'] === 'read-only', 'Code blocks must permit bounded read-only explanation without rewriting.');

$fake->failureCategory = 'rate_limited';
$failure = cxThrows(CaxtonJarvisException::class, static fn () => $service->propose('user:alice', '/article', $source, $sourceHash, $from, $to, 0, strlen($source), 'paragraph', 'rewrite', 'fixture', null, null), 'Provider failures must remain typed.');
cxExpect($failure->category === 'rate_limited' && $failure->retryAfterSeconds === 7, 'Rate-limit metadata must survive the adapter.');
$fake->failureCategory = 'authentication_failed';
cxExpect(cxThrows(CaxtonJarvisException::class, static fn () => $service->propose('user:alice', '/article', $source, $sourceHash, $from, $to, 0, strlen($source), 'paragraph', 'rewrite', 'fixture', null, null), 'Authentication failure must remain typed.')->category === 'authentication_failed', 'Authentication category must survive.');
$fake->failureCategory = 'timeout';
cxExpect(cxThrows(CaxtonJarvisException::class, static fn () => $service->propose('user:alice', '/article', $source, $sourceHash, $from, $to, 0, strlen($source), 'paragraph', 'rewrite', 'fixture', null, null), 'Timeout must remain typed.')->category === 'timeout', 'Timeout category must survive.');
$fake->failureCategory = 'budget_exceeded';
cxExpect(cxThrows(CaxtonJarvisException::class, static fn () => $service->propose('user:alice', '/article', $source, $sourceHash, $from, $to, 0, strlen($source), 'paragraph', 'rewrite', 'fixture', null, null), 'Budget denial must remain typed.')->category === 'budget_exceeded', 'Budget category must survive.');
$noProvider = new CaxtonFakeJarvis();
$noProvider->ids = [];
$noProviderService = new CaxtonJarvisService(new ArrayObject(['gravJarvis' => $noProvider]), new CaxtonProposalStore($directory . '-none'), 'site:test');
cxExpect($noProviderService->status()['state'] === 'no-provider', 'No-provider state must be explicit and non-fatal.');
$absent = new CaxtonJarvisService(new ArrayObject(), new CaxtonProposalStore($directory . '-absent'), 'site:test');
cxExpect($absent->status()['available'] === false, 'Caxton must remain complete when Jarvis is absent.');

foreach (glob($directory . '/*') ?: [] as $file) @unlink($file);
@rmdir($directory);
fwrite(STDOUT, "Caxton 0.3.0 Jarvis integration checks passed.\n");
