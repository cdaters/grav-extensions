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

use Grav\Plugin\GravJarvis\Contracts\ChunkPolicy;
use Grav\Plugin\GravJarvis\Contracts\CompletionRequest;
use Grav\Plugin\GravJarvis\Contracts\CompletionResult;
use Grav\Plugin\GravJarvis\Contracts\Exception\ChunkingException;
use Grav\Plugin\GravJarvis\Contracts\ProviderInterface;
use Grav\Plugin\GravJarvis\Contracts\ReliabilityContext;
use Grav\Plugin\GravJarvis\Contracts\ReliabilityPolicy;
use Grav\Plugin\GravJarvis\Contracts\Usage;
use Grav\Plugin\GravJarvis\Provider\ProviderRegistry;
use Grav\Plugin\GravJarvis\Reliability\BudgetGuard;
use Grav\Plugin\GravJarvis\Reliability\CacheKeyFactory;
use Grav\Plugin\GravJarvis\Reliability\CostEstimator;
use Grav\Plugin\GravJarvis\Reliability\GravMarkdownChunker;
use Grav\Plugin\GravJarvis\Reliability\PricingCatalog;
use Grav\Plugin\GravJarvis\Reliability\RetryExecutor;
use Grav\Plugin\GravJarvis\Security\SecretRedactor;
use Grav\Plugin\GravJarvis\Service\JarvisService;
use Grav\Plugin\GravJarvis\Service\ReliableJarvisService;
use Grav\Plugin\GravJarvis\Testing\DeterministicReliabilityRuntime;
use Grav\Plugin\GravJarvis\Testing\InMemoryResponseCache;

function expect(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function same(mixed $expected, mixed $actual, string $message): void { if ($expected !== $actual) throw new RuntimeException($message); }
function throws(callable $callback, string $class, string $message): Throwable { try { $callback(); } catch (Throwable $e) { if ($e instanceof $class) return $e; throw $e; } throw new RuntimeException($message); }

$chunker = new GravMarkdownChunker();
$markdown = "---\ntitle: Example\n---\n\n# Heading\n\nParagraph one.\n\n- first\n- second\n\n```php\n\ncode();\n```\n\nFinal.\n";
$chunked = $chunker->chunk($markdown, new ChunkPolicy(1024, 8, 8192, 4096));
same($markdown, implode('', array_map(static fn ($chunk): string => $chunk->content, $chunked->chunks)), 'Chunks did not preserve deterministic source order.');
expect(str_starts_with($chunked->chunks[0]->content, "---\n") && str_contains($chunked->chunks[0]->content, "---\n\n# Heading"), 'Frontmatter was not retained atomically.');
expect(str_contains(implode('', array_map(static fn ($chunk): string => $chunk->content, $chunked->chunks)), "```php\n\ncode();\n```"), 'A fenced block was split or changed.');
same(hash('sha256', $markdown), $chunked->sourceSha256, 'Source identity was not recorded.');
echo "PASS: headings paragraphs lists fences frontmatter order and provenance\n";

throws(fn () => $chunker->chunk(str_repeat('x', 1100), new ChunkPolicy(1024, 8, 8192, 4096)), ChunkingException::class, 'Oversized atomic block did not fail.');
throws(fn () => $chunker->chunk("invalid\xFFmarkdown", new ChunkPolicy(1024, 8, 8192, 4096)), ChunkingException::class, 'Invalid UTF-8 did not fail clearly.');
$many = str_repeat(str_repeat('a', 600) . "\n\n", 4);
throws(fn () => $chunker->chunk($many, new ChunkPolicy(1024, 2, 8192, 4096)), ChunkingException::class, 'Maximum chunk count did not fail closed.');
$truncated = $chunker->chunk($many, new ChunkPolicy(1024, 2, 8192, 4096, ChunkPolicy::TRUNCATE));
expect($truncated->truncated && count($truncated->chunks) === 2, 'Explicit infrastructure truncation was not deterministic.');
echo "PASS: oversized blocks maximum count and explicit truncation policy\n";

final class SummaryProvider implements ProviderInterface
{
    public int $calls = 0;
    public function id(): string { return 'summary-fixture'; }
    public function capabilities(): array { return ['text-completion']; }
    public function complete(CompletionRequest $request): CompletionResult
    {
        $this->calls++;
        return new CompletionResult($this->id(), 'summary-' . $this->calls, 'model-a', new Usage(5, 2, 7, 'tokens', true));
    }
}
$provider = new SummaryProvider(); $registry = new ProviderRegistry(); $registry->register($provider);
$costs = new CostEstimator(new PricingCatalog());
$service = new ReliableJarvisService(
    new JarvisService($registry, SecretRedactor::fromEnvironment()),
    new RetryExecutor(new DeterministicReliabilityRuntime()),
    new InMemoryResponseCache(),
    new CacheKeyFactory(),
    $costs,
    new BudgetGuard($costs),
    $chunker,
    new ReliabilityPolicy(),
    static fn (): int => 100
);
$source = str_repeat('a', 700) . "\n\n" . str_repeat('b', 700);
$policy = new ReliabilityPolicy(chunking: new ChunkPolicy(1024, 4, 8192, 4096));
$large = $service->summarizeLarge(
    new CompletionRequest('summary-fixture', $source, 'model-a'),
    new ReliabilityContext('site', 'actor', '/page', 'operation', 'summarize'),
    $policy
);
expect($large->synthesized && count($large->chunkResults) === 2 && $provider->calls === 3, 'Bounded chunk summaries and final synthesis were not executed exactly once.');
throws(fn () => $service->summarizeLarge(new CompletionRequest('summary-fixture', $source), new ReliabilityContext('site', 'actor', '/page', 'operation', 'rewrite'), $policy), ChunkingException::class, 'Unsafe rewrite-style chunk execution was not deferred.');
echo "PASS: summarize-only bounded chunk execution and final synthesis\n";
echo "Jarvis chunking contract passed (3 checks).\n";
