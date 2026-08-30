<?php

declare(strict_types=1);

namespace Grav\Common {
    abstract class Plugin
    {
        protected mixed $grav = null;
        protected mixed $config = null;

        public function setContractContext(mixed $grav, mixed $config): void
        {
            $this->grav = $grav;
            $this->config = $config;
        }
    }
}

namespace RocketTheme\Toolbox\Event {
    use ArrayAccess;

    final class Event implements ArrayAccess
    {
        /** @param array<string, mixed> $data */
        public function __construct(private array $data = [])
        {
        }

        public function offsetExists(mixed $offset): bool
        {
            return isset($this->data[(string) $offset]);
        }

        public function offsetGet(mixed $offset): mixed
        {
            return $this->data[(string) $offset] ?? null;
        }

        public function offsetSet(mixed $offset, mixed $value): void
        {
            $this->data[(string) $offset] = $value;
        }

        public function offsetUnset(mixed $offset): void
        {
            unset($this->data[(string) $offset]);
        }
    }
}

namespace GravJarvisContract {
    use ArrayAccess;
    use Closure;
    use Grav\Plugin\GravJarvis\Contracts\CompletionRequest;
    use Grav\Plugin\GravJarvis\Contracts\CompletionResult;
    use Grav\Plugin\GravJarvis\Contracts\Exception\DuplicateProviderException;
    use Grav\Plugin\GravJarvis\Contracts\Exception\JarvisException;
    use Grav\Plugin\GravJarvis\Contracts\Exception\ProviderException;
    use Grav\Plugin\GravJarvis\Contracts\Exception\ProviderFailureException;
    use Grav\Plugin\GravJarvis\Contracts\Exception\ProviderNotFoundException;
    use Grav\Plugin\GravJarvis\Contracts\JarvisServiceInterface;
    use Grav\Plugin\GravJarvis\Contracts\ProviderInterface;
    use Grav\Plugin\GravJarvis\Contracts\ProviderRegistryInterface;
    use Grav\Plugin\GravJarvis\Security\SecretRedactor;
    use Grav\Plugin\GravJarvis\Testing\DeterministicFakeProvider;
    use InvalidArgumentException;
    use RuntimeException;
    use Throwable;

    $pluginDirectory = getenv('GRAV_JARVIS_PLUGIN_DIR');
    if (!is_string($pluginDirectory) || $pluginDirectory === '') {
        $pluginDirectory = dirname(__DIR__, 2) . '/plugins/grav-jarvis';
    }
    $pluginDirectory = rtrim($pluginDirectory, '/');
    $pluginFile = $pluginDirectory . '/grav-jarvis.php';
    if (!is_file($pluginFile)) {
        fwrite(STDERR, "Jarvis plugin entry point not found.\n");
        exit(1);
    }
    spl_autoload_register(static function (string $class) use ($pluginDirectory): void {
        $prefix = 'Grav\\Plugin\\GravJarvis\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $file = $pluginDirectory . '/classes/'
            . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });
    require_once $pluginFile;

    final class ContractConfig
    {
        /** @param array<string, mixed> $values */
        public function __construct(private readonly array $values = [])
        {
        }

        public function get(string $key, mixed $default = null): mixed
        {
            return $this->values[$key] ?? $default;
        }
    }

    final class ContractLogger
    {
        /** @var list<string> */
        public array $errors = [];

        public function error(string $message): void
        {
            $this->errors[] = $message;
        }
    }

    final class ContractContainer implements ArrayAccess
    {
        /** @var array<string, mixed> */
        private array $values = [];
        public ?Closure $eventListener = null;
        /** @var list<string> */
        public array $events = [];

        public function offsetExists(mixed $offset): bool
        {
            return array_key_exists((string) $offset, $this->values);
        }

        public function offsetGet(mixed $offset): mixed
        {
            $key = (string) $offset;
            $value = $this->values[$key] ?? null;
            if ($value instanceof Closure) {
                $value = $value();
                $this->values[$key] = $value;
            }
            return $value;
        }

        public function offsetSet(mixed $offset, mixed $value): void
        {
            $this->values[(string) $offset] = $value;
        }

        public function offsetUnset(mixed $offset): void
        {
            unset($this->values[(string) $offset]);
        }

        public function fireEvent(string $name, object $event): void
        {
            $this->events[] = $name;
            if ($this->eventListener) {
                ($this->eventListener)($name, $event);
            }
        }
    }

    final class FailingProvider implements ProviderInterface
    {
        public function __construct(private readonly string $secret)
        {
        }

        public function id(): string
        {
            return 'failure';
        }

        public function capabilities(): array
        {
            return ['text-completion'];
        }

        public function complete(CompletionRequest $request): CompletionResult
        {
            throw new ProviderException(
                'Upstream rejected Authorization: Bearer bearer-test-value '
                . 'api_key=visible-key password=visible-password exact=' . $this->secret
            );
        }
    }

    final class LeakyResultProvider implements ProviderInterface
    {
        public function __construct(private readonly string $secret)
        {
        }

        public function id(): string
        {
            return 'leaky';
        }

        public function capabilities(): array
        {
            return ['text-completion'];
        }

        public function complete(CompletionRequest $request): CompletionResult
        {
            return new CompletionResult(
                providerId: 'leaky',
                output: 'Provider echoed ' . $this->secret . ' token=output-token',
                metadata: [
                    'api_key' => 'metadata-key',
                    'message' => 'password=metadata-password',
                ]
            );
        }
    }

    function expect(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    function expectSame(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException($message);
        }
    }

    /** @param class-string<Throwable> $class */
    function expectThrows(string $class, callable $callback, string $message): Throwable
    {
        try {
            $callback();
        } catch (Throwable $error) {
            if ($error instanceof $class) {
                return $error;
            }
            throw new RuntimeException($message . ' Wrong exception type: ' . $error::class);
        }
        throw new RuntimeException($message . ' No exception was thrown.');
    }

    function optionalCompletion(
        array|ArrayAccess $container,
        CompletionRequest $request
    ): ?CompletionResult {
        try {
            if (!isset($container['gravJarvis'])) {
                return null;
            }
            $candidate = $container['gravJarvis'];
            if (!$candidate instanceof JarvisServiceInterface) {
                return null;
            }
            return $candidate->complete($request);
        } catch (Throwable) {
            return null;
        }
    }

    $contractSecret = 'jarvis-contract-secret-9f6c2e';
    putenv('GRAV_JARVIS_CONTRACT_SECRET=' . $contractSecret);

    $container = new ContractContainer();
    $logger = new ContractLogger();
    $container['log'] = $logger;
    $container->eventListener = static function (string $name, object $event) use ($contractSecret): void {
        expectSame('onJarvisProviderRegister', $name, 'Jarvis fired an unexpected provider event.');
        $registry = $event['registry'] ?? null;
        expect($registry instanceof ProviderRegistryInterface, 'Provider event did not expose the public registry.');
        $registry->register(new DeterministicFakeProvider());
        $registry->register(new FailingProvider($contractSecret));
        $registry->register(new LeakyResultProvider($contractSecret));
    };

    $plugin = new \Grav\Plugin\GravJarvisPlugin();
    $plugin->setContractContext($container, new ContractConfig([
        'plugins.grav-jarvis.providers.openai_compatible.enabled' => true,
        'plugins.grav-jarvis.providers.openai_compatible.instances' => [[
            'id' => 'compatible-fixture',
            'base_uri' => 'https://compatible.example/v1',
            'credential_environment_variable' => 'GRAV_JARVIS_COMPATIBLE_FIXTURE_API_KEY',
            'default_model' => 'fixture-model',
            'model_discovery' => false,
        ]],
    ]));
    $plugin->autoload();

    /** @var array<string, callable(): void> $tests */
    $tests = [];

    $tests['plugin registration and provider event'] = static function () use ($plugin, $container): void {
        $plugin->onPluginsInitialized();
        expectSame(['onJarvisProviderRegister'], $container->events, 'Provider registration event count changed.');
        expect(isset($container['gravJarvis']), 'Jarvis service was not registered in the Grav container.');
        $service = $container['gravJarvis'];
        expect($service instanceof JarvisServiceInterface, 'Container service does not implement the public interface.');
        expectSame(
            ['compatible-fixture', 'failure', 'fake', 'leaky', 'openai'],
            $service->providerIds(),
            'Provider identifiers are not deterministic.'
        );
        expectSame(
            ['deterministic-test', 'text-completion'],
            $service->capabilities('fake'),
            'Provider capabilities are not normalized.'
        );
        expectSame(
            ['provider-validation', 'text-completion'],
            $service->capabilities('compatible-fixture'),
            'Configured compatible provider capabilities are not truthful.'
        );
    };

    $tests['deterministic fake responses'] = static function () use ($container): void {
        /** @var JarvisServiceInterface $service */
        $service = $container['gravJarvis'];
        $first = new CompletionRequest(
            providerId: 'fake',
            input: 'Same provider-neutral input',
            instructions: 'Return a deterministic fixture.',
            options: ['format' => 'text', 'max_tokens' => 40],
            metadata: ['scope' => 'contract']
        );
        $second = new CompletionRequest(
            providerId: 'fake',
            input: 'Same provider-neutral input',
            instructions: 'Return a deterministic fixture.',
            options: ['max_tokens' => 40, 'format' => 'text'],
            metadata: ['scope' => 'contract']
        );
        $resultA = $service->complete($first);
        $resultB = $service->complete($second);
        expectSame($resultA->toArray(), $resultB->toArray(), 'Canonical-equivalent requests changed fake output.');
        expectSame('characters', $resultA->usage->unit, 'Fake usage assumed a token-only unit.');
        expect(
            $resultA->output !== $service->complete(new CompletionRequest('fake', 'Different input'))->output,
            'Different fake requests produced the same response.'
        );
    };

    $tests['registry denial behavior'] = static function () use ($container): void {
        /** @var JarvisServiceInterface $service */
        $service = $container['gravJarvis'];
        expectThrows(
            DuplicateProviderException::class,
            static fn () => $service->providers()->register(new DeterministicFakeProvider()),
            'Duplicate provider registration was not denied.'
        );
        expectThrows(
            ProviderNotFoundException::class,
            static fn () => $service->complete(new CompletionRequest('missing', 'No provider should exist.')),
            'Missing provider lookup was not typed.'
        );
    };

    $tests['provider failure normalization and redaction'] = static function () use (
        $container,
        $contractSecret
    ): void {
        /** @var JarvisServiceInterface $service */
        $service = $container['gravJarvis'];
        $error = expectThrows(
            ProviderFailureException::class,
            static fn () => $service->complete(new CompletionRequest('failure', 'Trigger the failure fixture.')),
            'Provider failure did not cross the service as a typed safe exception.'
        );
        expect(!str_contains($error->getMessage(), $contractSecret), 'Environment secret crossed the service boundary.');
        expect(!str_contains($error->getMessage(), 'bearer-test-value'), 'Bearer credential was not redacted.');
        expect(!str_contains($error->getMessage(), 'visible-key'), 'API key was not redacted.');
        expect(!str_contains($error->getMessage(), 'visible-password'), 'Password was not redacted.');
        expect(str_contains($error->getMessage(), SecretRedactor::REDACTED), 'Safe failure omitted redaction markers.');
        expect($error->getPrevious() === null, 'Unsafe provider exception was chained to the public failure.');
    };

    $tests['consumer absence and failure fallback'] = static function () use ($container): void {
        $request = new CompletionRequest('fake', 'Optional feature request.');
        expect(optionalCompletion([], $request) === null, 'Absent Jarvis service did not degrade to null.');
        expect(optionalCompletion(['gravJarvis' => new \stdClass()], $request) === null, 'Invalid service did not degrade.');
        expect(optionalCompletion($container, $request) instanceof CompletionResult, 'Available Jarvis service was missed.');
        expect(
            optionalCompletion($container, new CompletionRequest('failure', 'Optional provider failure.')) === null,
            'Provider failure did not preserve the consumer fallback.'
        );

        $disabledContainer = new ContractContainer();
        $disabled = new \Grav\Plugin\GravJarvisPlugin();
        $disabled->setContractContext(
            $disabledContainer,
            new ContractConfig(['plugins.grav-jarvis.enabled' => false])
        );
        $disabled->onPluginsInitialized();
        expect(!isset($disabledContainer['gravJarvis']), 'Disabled Jarvis registered a service.');
        expectSame([], $disabledContainer->events, 'Disabled Jarvis fired the provider registration event.');
    };

    $tests['secret redactor and credential rejection'] = static function () use ($contractSecret): void {
        $redactor = SecretRedactor::fromEnvironment();
        $text = $redactor->redact(
            'exact=' . $contractSecret
            . ' Authorization: Bearer bearer-value api_key=key-value password=password-value'
        );
        foreach ([$contractSecret, 'bearer-value', 'key-value', 'password-value'] as $secret) {
            expect(!str_contains($text, $secret), 'Secret redactor retained protected text.');
        }
        $structured = $redactor->redactValue([
            'nested' => ['client_secret' => 'structured-secret'],
            'message' => 'token=inline-token',
        ]);
        expectSame(
            SecretRedactor::REDACTED,
            $structured['nested']['client_secret'] ?? null,
            'Structured secret key was not redacted.'
        );
        expect(!str_contains((string) ($structured['message'] ?? ''), 'inline-token'), 'Inline token was not redacted.');
        expectThrows(
            InvalidArgumentException::class,
            static fn () => new CompletionRequest(
                providerId: 'fake',
                input: 'Credentials do not belong in request options.',
                options: ['api_key' => 'forbidden']
            ),
            'CompletionRequest accepted a provider credential.'
        );
        new CompletionRequest(
            providerId: 'fake',
            input: 'Token budgets are not credentials.',
            options: ['max_tokens' => 20]
        );
    };

    $tests['successful result redaction'] = static function () use ($container, $contractSecret): void {
        /** @var JarvisServiceInterface $service */
        $service = $container['gravJarvis'];
        $result = $service->complete(new CompletionRequest('leaky', 'Return the leaky result fixture.'));
        $encoded = json_encode($result->toArray(), JSON_THROW_ON_ERROR);
        foreach ([$contractSecret, 'output-token', 'metadata-key', 'metadata-password'] as $secret) {
            expect(!str_contains($encoded, $secret), 'Successful provider result retained a credential.');
        }
        expect(
            substr_count($encoded, SecretRedactor::REDACTED) >= 3,
            'Successful provider result omitted expected redaction markers.'
        );
    };

    $passed = 0;
    try {
        foreach ($tests as $name => $test) {
            $test();
            ++$passed;
            fwrite(STDOUT, 'PASS: ' . $name . "\n");
        }
    } catch (Throwable $error) {
        $safe = SecretRedactor::fromEnvironment()->redact($error->getMessage());
        fwrite(STDERR, 'FAIL: ' . $error::class . ': ' . $safe . "\n");
        putenv('GRAV_JARVIS_CONTRACT_SECRET');
        exit(1);
    }

    putenv('GRAV_JARVIS_CONTRACT_SECRET');
    fwrite(STDOUT, 'Jarvis contract passed (' . $passed . " checks).\n");
}
