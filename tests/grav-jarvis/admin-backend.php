<?php

declare(strict_types=1);

namespace GravJarvisAdminContract {
    use Grav\Plugin\GravJarvis\Admin\ActionPromptLibrary;
    use Grav\Plugin\GravJarvis\Admin\BoundedContextBuilder;
    use Grav\Plugin\GravJarvis\Admin\JarvisAdminService;
    use Grav\Plugin\GravJarvis\Admin\TransientProposalStore;
    use Grav\Plugin\GravJarvis\Contracts\CompletionRequest;
    use Grav\Plugin\GravJarvis\Contracts\CompletionResult;
    use Grav\Plugin\GravJarvis\Contracts\ModelCatalog;
    use Grav\Plugin\GravJarvis\Contracts\ModelDescriptor;
    use Grav\Plugin\GravJarvis\Contracts\ModelDiscoveryInterface;
    use Grav\Plugin\GravJarvis\Contracts\ProviderInterface;
    use Grav\Plugin\GravJarvis\Contracts\ProviderValidationInterface;
    use Grav\Plugin\GravJarvis\Contracts\ProviderValidationResult;
    use Grav\Plugin\GravJarvis\Contracts\Usage;
    use Grav\Plugin\GravJarvis\Provider\ProviderRegistry;
    use Grav\Plugin\GravJarvis\Security\SecretRedactor;
    use Grav\Plugin\GravJarvis\Service\JarvisService;
    use RuntimeException;
    use Throwable;

    $pluginDirectory = getenv('GRAV_JARVIS_PLUGIN_DIR');
    if (!is_string($pluginDirectory) || $pluginDirectory === '') {
        $pluginDirectory = dirname(__DIR__, 2) . '/plugins/grav-jarvis';
    }
    $pluginDirectory = rtrim($pluginDirectory, '/');
    spl_autoload_register(static function (string $class) use ($pluginDirectory): void {
        $prefix = 'Grav\\Plugin\\GravJarvis\\';
        if (str_starts_with($class, $prefix)) {
            $file = $pluginDirectory . '/classes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        }
    });

    final class AdminFixtureProvider implements ProviderInterface, ProviderValidationInterface, ModelDiscoveryInterface
    {
        public ?CompletionRequest $lastRequest = null;

        public function id(): string { return 'admin-fixture'; }
        public function capabilities(): array { return ['model-discovery', 'provider-validation', 'text-completion']; }
        public function validateProvider(): ProviderValidationResult
        {
            return new ProviderValidationResult($this->id(), true, [], $this->capabilities());
        }
        public function discoverModels(): ModelCatalog
        {
            return new ModelCatalog($this->id(), [
                new ModelDescriptor('fixture-a', 'Fixture A', 'Deterministic offline model', ['text-completion']),
                new ModelDescriptor('fixture-b', 'Fixture B', null, ['text-completion'], false),
            ]);
        }
        public function complete(CompletionRequest $request): CompletionResult
        {
            $this->lastRequest = $request;
            return new CompletionResult(
                $this->id(),
                'fixture-proposal:' . substr(hash('sha256', $request->canonicalPayload()), 0, 20),
                $request->model ?? 'fixture-a',
                new Usage(100, 32, null, 'characters', false),
                ['fixture' => 'admin-0.2.0']
            );
        }
    }

    final class BrokenCapabilitiesProvider implements ProviderInterface
    {
        public function id(): string { return 'broken-capabilities'; }
        public function capabilities(): array { throw new RuntimeException('unsafe provider capability detail'); }
        public function complete(CompletionRequest $request): CompletionResult
        {
            throw new RuntimeException('not called');
        }
    }

    function expect(bool $condition, string $message): void
    {
        if (!$condition) throw new RuntimeException($message);
    }

    function same(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) throw new RuntimeException($message);
    }

    function throws(callable $callback, string $message): Throwable
    {
        try { $callback(); } catch (Throwable $error) { return $error; }
        throw new RuntimeException($message);
    }

    $frozen = [
        'JarvisServiceInterface.php' => 'f74f88c81cc7e0881f194ff1501f0d4e0eebdd687f3dfca78cd49477198c0e0a',
        'ProviderInterface.php' => '3d550beda0ef1265e614f1476dc4866615e5629378f7f74d003a2de4da2a260a',
        'ProviderRegistryInterface.php' => '250d662a5476a11ff27f79a04cb854eb28c78fd0b76061dcdc43e672bbea6ad5',
        'ProviderIntrospectionServiceInterface.php' => '6a779b9dbce01b38d1ea279c604141592a7f9f2aef54ae4b8028d014c2e55f7b',
        'ProviderValidationInterface.php' => 'de6c7726ee9bfb6b68c608498468937a9b8399ca794aeeb56a0da39df0e16bc7',
        'ModelDiscoveryInterface.php' => '1911cd3936a33c1781aee94b81fb965df440acff7966d8ed5a03cad3c6e628a1',
    ];
    foreach ($frozen as $file => $digest) {
        same($digest, hash_file('sha256', $pluginDirectory . '/classes/Contracts/' . $file), "Frozen contract changed: {$file}");
    }
    echo "PASS: 0.1 public interfaces remain byte-identical\n";

    $prompts = new ActionPromptLibrary();
    same(['rewrite', 'proofread', 'shorten', 'expand', 'summarize', 'custom'], array_column($prompts->actions(), 'id'), 'Page actions changed.');
    $custom = $prompts->pagePrompt('custom', ['content' => 'Body', 'frontmatter' => [], 'media' => []], 'Use a warmer tone.');
    expect(str_contains($custom['input'], 'Use a warmer tone.'), 'Custom instruction was not included.');
    expect(str_contains($custom['instructions'], 'Return only the proposed replacement text.'), 'Review-first output instruction missing.');
    throws(fn () => $prompts->pagePrompt('custom', ['content' => 'Body'], ''), 'Empty custom instruction should fail.');
    echo "PASS: provider-neutral prompt library and six page actions\n";

    $secret = 'frontmatter-secret-12345';
    $contexts = new BoundedContextBuilder(new SecretRedactor([$secret]));
    $page = [
        'route' => '/test', 'title' => 'Test', 'template' => 'default', 'language' => 'en',
        'frontmatter' => ['z' => 'last', 'api_key' => $secret, 'innocent_name' => $secret, 'nested' => ['password' => $secret]],
        'media' => [['filename' => 'image.jpg', 'mime' => 'image/jpeg', 'path' => '/private/path']],
    ];
    $one = $contexts->build($page, 'Current **Markdown** body.');
    $two = $contexts->build($page, 'Current **Markdown** body.');
    same($one, $two, 'Bounded context must be deterministic.');
    same('[REDACTED]', $one['frontmatter']['api_key'], 'Secret frontmatter field was not redacted.');
    same('[REDACTED]', $one['frontmatter']['innocent_name'], 'Known environment secret value was not redacted.');
    expect(!str_contains(json_encode($one), $secret), 'Secret leaked into bounded context.');
    expect(!array_key_exists('path', $one['media'][0]), 'Media path must not enter AI context.');
    same(hash('sha256', 'Current **Markdown** body.'), $one['source_hash'], 'Source hash mismatch.');
    $large = $contexts->build($page, str_repeat('a', BoundedContextBuilder::CONTENT_BYTES + 20));
    expect($large['truncated']['content'] && !$large['accept_allowed'], 'Truncated content must be preview-only.');
    echo "PASS: deterministic bounded page/frontmatter/media context and redaction\n";

    $provider = new AdminFixtureProvider();
    $registry = new ProviderRegistry();
    $registry->register($provider);
    $registry->register(new BrokenCapabilitiesProvider());
    $jarvis = new JarvisService($registry, new SecretRedactor([$secret]));
    $temp = sys_get_temp_dir() . '/grav-jarvis-admin-' . bin2hex(random_bytes(6));
    $admin = new JarvisAdminService($jarvis, $contexts, $prompts, new TransientProposalStore($temp));
    $bootstrap = $admin->bootstrap(true);
    same('admin-fixture', $bootstrap['providers'][0]['id'], 'Provider registration missing from bootstrap.');
    same([], $bootstrap['providers'][1]['capabilities'], 'A broken provider must not break Admin bootstrap.');
    same(true, $bootstrap['can_approve'], 'Approval capability was not surfaced.');
    same(false, $bootstrap['selection_aware'], '0.2.0 must not claim selection awareness.');
    same('usable', $admin->validation('admin-fixture')['state'], 'Provider validation failed.');
    same('fixture-a', $admin->models('admin-fixture')['models'][0]['id'], 'Provider-neutral model discovery failed.');
    echo "PASS: Admin bootstrap, provider validation, and model discovery\n";

    $general = $admin->complete('admin-fixture', null, 'Draft a short introduction.');
    expect(str_starts_with($general['response'], 'fixture-proposal:'), 'Deterministic general completion failed.');
    same('admin', $provider->lastRequest?->metadata['surface'] ?? null, 'Admin completion surface metadata missing.');
    $proposal = $admin->propose('user:tester', 'admin-fixture', 'fixture-a', 'rewrite', null, $page, 'Original body');
    expect(is_string($proposal['proposal_id']) && strlen($proposal['proposal_id']) === 32, 'One-time proposal receipt missing.');
    expect($proposal['context']['accept_allowed'], 'Bounded proposal should be reviewable.');
    same('page-editor', $provider->lastRequest?->metadata['surface'] ?? null, 'Page editor surface metadata missing.');
    $files = glob($temp . '/*.json') ?: [];
    same(1, count($files), 'Proposal receipt file missing.');
    $receipt = (string) file_get_contents($files[0]);
    expect(!str_contains($receipt, 'Original body') && !str_contains($receipt, $proposal['proposed_content']), 'Proposal receipt persisted content.');
    $accepted = $admin->accept('user:tester', $proposal['proposal_id'], '/test', 'Original body', $proposal['proposed_content']);
    same($proposal['proposed_content'], $accepted['content'], 'Accepted content mismatch.');
    same([], glob($temp . '/*.json') ?: [], 'One-time receipt was not consumed.');
    throws(fn () => $admin->accept('user:tester', $proposal['proposal_id'], '/test', 'Original body', $proposal['proposed_content']), 'Receipt replay should fail.');
    foreach (['proofread', 'shorten', 'expand', 'summarize', 'custom'] as $action) {
        $source = "Original {$action} body";
        $candidate = $admin->propose(
            'user:tester', 'admin-fixture', null, $action,
            $action === 'custom' ? 'Use a calm, direct voice.' : null,
            $page, $source
        );
        same($action, $candidate['action'], "{$action} proposal action mismatch.");
        expect(str_starts_with($candidate['proposed_content'], 'fixture-proposal:'), "{$action} proposal did not use the deterministic provider.");
        $admin->accept('user:tester', $candidate['proposal_id'], '/test', $source, $candidate['proposed_content']);
    }
    same([], glob($temp . '/*.json') ?: [], 'Action proposal receipts were not consumed.');
    echo "PASS: all six proposals, one-time acceptance, and no content persistence\n";

    $previewOnly = $admin->propose(
        'user:tester', 'admin-fixture', null, 'proofread', null, $page,
        str_repeat('x', BoundedContextBuilder::CONTENT_BYTES + 1)
    );
    same(false, $previewOnly['context']['accept_allowed'], 'Truncated source proposal must not be acceptable.');
    same(null, $previewOnly['proposal_id'], 'Preview-only proposal must not receive an acceptance receipt.');
    echo "PASS: truncation fails closed for acceptance\n";

    $controller = (string) file_get_contents($pluginDirectory . '/classes/Controller/ApiController.php');
    foreach (['grav-jarvis.access', 'grav-jarvis.use', 'grav-jarvis.approve', 'authorizePageAction'] as $required) {
        expect(str_contains($controller, $required), "Controller enforcement missing: {$required}");
    }
    foreach (['$page->save(', '$page->publish(', 'file_put_contents('] as $forbidden) {
        expect(!str_contains($controller, $forbidden), "Controller contains forbidden mutation primitive: {$forbidden}");
    }
    $pluginYaml = (string) file_get_contents($pluginDirectory . '/grav-jarvis.yaml');
    expect(!preg_match('/api[_-]?key|secret|password|credential/i', $pluginYaml), 'Persisted plugin YAML contains credential fields.');
    expect(str_contains($controller, "'grav-jarvis.manage'"), 'Credential-management permission gate is missing.');
    echo "PASS: permission gates, no page persistence, and write-only credential boundary\n";

    foreach (glob($temp . '/*') ?: [] as $file) {
        if (is_file($file)) @unlink($file);
    }
    if (is_file($temp . '/.lock')) @unlink($temp . '/.lock');
    if (is_dir($temp)) @rmdir($temp);
    echo "Jarvis Admin2 backend contract passed (7 checks).\n";
}
