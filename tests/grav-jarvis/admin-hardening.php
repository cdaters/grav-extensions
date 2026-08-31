<?php

declare(strict_types=1);

namespace GravJarvisAdminHardeningContract {
    use Closure;
    use Grav\Plugin\GravJarvis\Admin\ActionPromptLibrary;
    use Grav\Plugin\GravJarvis\Admin\BoundedContextBuilder;
    use Grav\Plugin\GravJarvis\Admin\JarvisAdminService;
    use Grav\Plugin\GravJarvis\Admin\TransientProposalStore;
    use Grav\Plugin\GravJarvis\Contracts\CompletionRequest;
    use Grav\Plugin\GravJarvis\Contracts\CompletionResult;
    use Grav\Plugin\GravJarvis\Contracts\Exception\CredentialConfigurationException;
    use Grav\Plugin\GravJarvis\Contracts\Exception\HttpTransportException;
    use Grav\Plugin\GravJarvis\Contracts\Exception\MalformedCredentialException;
    use Grav\Plugin\GravJarvis\Contracts\Exception\MissingCredentialException;
    use Grav\Plugin\GravJarvis\Contracts\Exception\ProviderAuthenticationException;
    use Grav\Plugin\GravJarvis\Contracts\Exception\ProviderCapabilityException;
    use Grav\Plugin\GravJarvis\Contracts\Exception\ProviderConfigurationException;
    use Grav\Plugin\GravJarvis\Contracts\Exception\ProviderFailureException;
    use Grav\Plugin\GravJarvis\Contracts\Exception\ProviderRateLimitException;
    use Grav\Plugin\GravJarvis\Contracts\Exception\ProviderResponseException;
    use Grav\Plugin\GravJarvis\Contracts\ProviderInterface;
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
            if (is_file($file)) require_once $file;
        }
    });

    final class HardeningProvider implements ProviderInterface
    {
        public function __construct(private readonly string $providerId = 'hardening-fixture') {}
        public function id(): string { return $this->providerId; }
        public function capabilities(): array { return ['text-completion']; }
        public function complete(CompletionRequest $request): CompletionResult
        {
            return new CompletionResult(
                $this->providerId,
                'hardening:' . substr(hash('sha256', $request->canonicalPayload()), 0, 20),
                $request->model,
                new Usage(10, 5, 15, 'characters', false)
            );
        }
    }

    final class FailureProvider implements ProviderInterface
    {
        public function __construct(private readonly string $providerId, private readonly Throwable $failure) {}
        public function id(): string { return $this->providerId; }
        public function capabilities(): array { return ['text-completion']; }
        public function complete(CompletionRequest $request): CompletionResult { throw $this->failure; }
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

    function removeTree(string $directory): void
    {
        foreach (glob($directory . '/*') ?: [] as $file) {
            if (is_file($file)) @unlink($file);
        }
        if (is_file($directory . '/.lock')) @unlink($directory . '/.lock');
        if (is_dir($directory)) @rmdir($directory);
    }

    $now = 1000;
    $temp = sys_get_temp_dir() . '/grav-jarvis-hardening-' . bin2hex(random_bytes(6));
    $clock = static function () use (&$now): int { return $now; };
    $store = new TransientProposalStore($temp, Closure::fromCallable($clock));
    $receipt = $store->issue('user:alpha', '/one', hash('sha256', 'source'), hash('sha256', 'proposal'));
    expect(preg_match('/^[a-f0-9]{32}$/D', $receipt['id']) === 1, 'Proposal identifiers must be opaque random hex.');
    throws(fn () => $store->revoke($receipt['id'], 'user:beta', '/one'), 'Cross-user revoke should fail.');
    throws(fn () => $store->revoke($receipt['id'], 'user:alpha', '/two'), 'Cross-page revoke should fail.');
    same(1, count(glob($temp . '/*.json') ?: []), 'Cross-boundary attempts must not consume the receipt.');
    throws(
        fn () => $store->consume($receipt['id'], 'user:alpha', '/one', hash('sha256', 'changed'), hash('sha256', 'proposal')),
        'A changed editor source must be stale.'
    );
    same(1, count(glob($temp . '/*.json') ?: []), 'A stale attempt must not consume a still-matchable receipt.');
    $store->revoke($receipt['id'], 'user:alpha', '/one');
    same([], glob($temp . '/*.json') ?: [], 'Explicit Reject/revoke must close its receipt.');
    echo "PASS: opaque receipts deny cross-user, cross-page, stale, and rejected replay\n";

    $expired = $store->issue('user:alpha', '/one', hash('sha256', 'source'), hash('sha256', 'proposal'));
    $now += TransientProposalStore::LIFETIME_SECONDS + 1;
    throws(
        fn () => $store->consume($expired['id'], 'user:alpha', '/one', hash('sha256', 'source'), hash('sha256', 'proposal')),
        'An expired proposal must fail.'
    );
    same([], glob($temp . '/*.json') ?: [], 'Expired receipts must be deterministically removed.');
    expect(TransientProposalStore::MAX_ACTIVE_RECEIPTS === 128, 'Receipt storage bound changed unexpectedly.');
    for ($index = 0; $index < TransientProposalStore::MAX_ACTIVE_RECEIPTS; $index++) {
        $store->issue('user:capacity', '/capacity', hash('sha256', 'source-' . $index), hash('sha256', 'proposal-' . $index));
    }
    throws(
        fn () => $store->issue('user:capacity', '/capacity', hash('sha256', 'overflow'), hash('sha256', 'overflow')),
        'Receipt storage must fail closed at its active capacity.'
    );
    $now += TransientProposalStore::LIFETIME_SECONDS + 1;
    $afterCleanup = $store->issue('user:capacity', '/capacity', hash('sha256', 'fresh'), hash('sha256', 'fresh'));
    same(1, count(glob($temp . '/*.json') ?: []), 'Issuance must deterministically clean expired capacity records.');
    $store->revoke($afterCleanup['id'], 'user:capacity', '/capacity');
    echo "PASS: proposal expiry cleanup and active storage bounds\n";

    $registry = new ProviderRegistry();
    $registry->register(new HardeningProvider());
    $service = new JarvisService($registry, new SecretRedactor());
    $admin = new JarvisAdminService(
        $service,
        new BoundedContextBuilder(new SecretRedactor()),
        new ActionPromptLibrary(),
        $store
    );
    $page = ['route' => '/one', 'title' => 'One', 'template' => 'default', 'language' => 'en'];
    $first = $admin->propose('user:alpha', 'hardening-fixture', null, 'rewrite', null, $page, 'first source');
    $second = $admin->propose(
        'user:alpha', 'hardening-fixture', null, 'proofread', null, $page, 'changed source', $first['proposal_id']
    );
    throws(
        fn () => $admin->accept('user:alpha', $first['proposal_id'], '/one', 'first source', $first['proposed_content']),
        'A replaced proposal must not remain acceptable.'
    );
    $admin->accept('user:alpha', $second['proposal_id'], '/one', 'changed source', $second['proposed_content']);
    same([], glob($temp . '/*.json') ?: [], 'Replacement and acceptance should leave no active receipt.');
    echo "PASS: regeneration creates a fresh proposal and invalidates the replaced receipt\n";

    $failures = [
        'missing-fixture' => [new MissingCredentialException('missing-fixture', 'GRAV_JARVIS_MISSING_FIXTURE_API_KEY'), 'credential_missing', false],
        'malformed-fixture' => [new MalformedCredentialException('malformed-fixture', 'GRAV_JARVIS_MALFORMED_FIXTURE_API_KEY'), 'credential_invalid', false],
        'credential-config-fixture' => [new CredentialConfigurationException('credential-config-fixture', 'fixture namespace mismatch'), 'configuration_invalid', false],
        'provider-config-fixture' => [new ProviderConfigurationException('fixture configuration invalid'), 'configuration_invalid', false],
        'auth-fixture' => [new ProviderAuthenticationException('fixture authentication failed'), 'authentication_failed', false],
        'rate-fixture' => [new ProviderRateLimitException('fixture rate limited', 1), 'rate_limited', true],
        'capability-fixture' => [new ProviderCapabilityException('capability-fixture', 'fixture-capability'), 'unsupported_capability', false],
        'response-fixture' => [new ProviderResponseException('fixture response invalid'), 'response_invalid', false],
        'timeout-fixture' => [new HttpTransportException('fixture request timed out'), 'timeout', true],
        'unavailable-fixture' => [new HttpTransportException('fixture connection unavailable'), 'provider_unavailable', true],
    ];
    foreach ($failures as $id => [$failure, $category, $retryable]) {
        $failureRegistry = new ProviderRegistry();
        $failureRegistry->register(new FailureProvider($id, $failure));
        $failureService = new JarvisService($failureRegistry, new SecretRedactor());
        $error = throws(
            fn () => $failureService->complete(new CompletionRequest($id, 'test')),
            "{$id} should fail."
        );
        expect($error instanceof ProviderFailureException, "{$id} was not normalized.");
        same($category, $error->category, "{$id} category mismatch.");
        same($retryable, $error->retryable, "{$id} retryability mismatch.");
        if ($id === 'rate-fixture') same(1, $error->retryAfterSeconds, 'Normalized retry-after was lost.');
    }
    echo "PASS: provider failures retain safe Admin-facing categories and retryability\n";

    $controller = (string) file_get_contents($pluginDirectory . '/classes/Controller/ApiController.php');
    expect(str_contains($controller, "'jarvis_' . \$error->category"), 'Safe provider error-code mapping is missing.');
    expect(str_contains($controller, 'jarvis_proposal_conflict'), 'Proposal conflict error code is missing.');
    expect(str_contains($controller, 'jarvis_budget_exceeded'), 'Budget failure error code is missing.');
    foreach (['endpoint_url', 'environment_variable', 'authorization_header'] as $forbidden) {
        expect(!str_contains($controller, $forbidden), "Controller unexpectedly accepts {$forbidden}.");
    }
    echo "PASS: Admin errors are coded and request fields cannot widen provider authority\n";

    removeTree($temp);
    echo "Jarvis Admin2 hardening contract passed (5 checks).\n";
}
