<?php

declare(strict_types=1);

namespace GravJarvisCompatibleProvider;

use Grav\Plugin\GravJarvis\Contracts\CompletionRequest;
use Grav\Plugin\GravJarvis\Contracts\Exception\HttpTransportException;
use Grav\Plugin\GravJarvis\Contracts\Exception\ProviderAuthenticationException;
use Grav\Plugin\GravJarvis\Contracts\Exception\ProviderCapabilityException;
use Grav\Plugin\GravJarvis\Contracts\Exception\ProviderConfigurationException;
use Grav\Plugin\GravJarvis\Contracts\Exception\ProviderRateLimitException;
use Grav\Plugin\GravJarvis\Contracts\Exception\ProviderResponseException;
use Grav\Plugin\GravJarvis\Contracts\HttpRequest;
use Grav\Plugin\GravJarvis\Contracts\HttpResponse;
use Grav\Plugin\GravJarvis\Provider\OpenAICompatible\CompatibleProviderConfig;
use Grav\Plugin\GravJarvis\Provider\OpenAICompatible\OpenAICompatibleProvider;
use Grav\Plugin\GravJarvis\Provider\ProviderRegistry;
use Grav\Plugin\GravJarvis\Security\EnvironmentCredentialResolver;
use Grav\Plugin\GravJarvis\Security\SecretRedactor;
use Grav\Plugin\GravJarvis\Service\JarvisService;
use Grav\Plugin\GravJarvis\Testing\FixtureHttpExecutor;
use Grav\Plugin\GravJarvis\Testing\FixtureHttpTransport;
use Grav\Plugin\GravJarvis\Testing\StaticDnsResolver;
use Grav\Plugin\GravJarvis\Transport\BoundedHttpTransport;
use Grav\Plugin\GravJarvis\Transport\PublicHttpsDestinationGuard;
use RuntimeException;
use Throwable;

$pluginDirectory = getenv('GRAV_JARVIS_PLUGIN_DIR');
if (!is_string($pluginDirectory) || $pluginDirectory === '') {
    $pluginDirectory = dirname(__DIR__, 2) . '/plugins/grav-jarvis';
}
$pluginDirectory = rtrim($pluginDirectory, '/');
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

function setEnvironment(string $name, ?string $value): void
{
    putenv($value === null ? $name : $name . '=' . $value);
}

function config(
    string $id = 'compatible-a',
    string $base = 'https://gateway.example/v1',
    string $environment = 'GRAV_JARVIS_COMPATIBLE_A_API_KEY',
    bool $discovery = true
): CompatibleProviderConfig {
    return new CompatibleProviderConfig($id, $base, $environment, 'fixture-model', $discovery);
}

/** @param array<string, mixed>|null $payload */
function request(CompatibleProviderConfig $config, string $secret, string $method, string $path, ?array $payload = null): HttpRequest
{
    setEnvironment($config->credentialEnvironmentVariable, $secret);
    $credential = (new EnvironmentCredentialResolver($config->providerId))
        ->resolve($config->credentialEnvironmentVariable)
        ->prefixed('Bearer ');
    return new HttpRequest(
        $method,
        $config->baseUri . $path,
        ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
        $payload === null ? null : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ['Authorization' => $credential]
    );
}

function provider(CompatibleProviderConfig $config, mixed $http): OpenAICompatibleProvider
{
    return new OpenAICompatibleProvider(
        $config,
        new EnvironmentCredentialResolver($config->providerId),
        $http
    );
}

$secret = 'compatible-offline-secret-6842';
$tests = [];

$tests['full compatible profile through bounded service'] = static function () use ($secret): void {
    $config = config();
    $payload = [
        'model' => 'fixture-model',
        'input' => 'Compatible request.',
        'store' => false,
        'instructions' => 'Return text.',
    ];
    $completion = request($config, $secret, 'POST', '/responses', $payload);
    $models = request($config, $secret, 'GET', '/models');
    $executor = new FixtureHttpExecutor();
    $executor->addResponse($completion, new HttpResponse(200, [], json_encode([
        'status' => 'completed',
        'model' => 'fixture-model',
        'output' => [[
            'type' => 'message',
            'content' => [['type' => 'output_text', 'text' => 'Compatible output.']],
        ]],
        'usage' => ['input_tokens' => 4, 'output_tokens' => 3, 'total_tokens' => 7],
    ], JSON_THROW_ON_ERROR)));
    $executor->addResponse($models, new HttpResponse(200, [], '{"data":[{"id":"fixture-model"}]}'));
    $transport = new BoundedHttpTransport(
        new PublicHttpsDestinationGuard(
            [$config->baseUri],
            new StaticDnsResolver(['gateway.example' => ['93.184.216.34']])
        ),
        $executor
    );
    $registry = new ProviderRegistry();
    $registry->register(provider($config, $transport));
    $service = new JarvisService($registry, SecretRedactor::fromEnvironment());
    $result = $service->complete(new CompletionRequest(
        $config->providerId,
        'Compatible request.',
        instructions: 'Return text.'
    ));
    expectSame('Compatible output.', $result->output, 'Compatible text normalization changed.');
    expectSame(7, $result->usage->totalUnits, 'Compatible usage normalization changed.');
    expect($service->validateProvider($config->providerId)->usable, 'Full compatible validation failed.');
    expectSame('fixture-model', $service->discoverModels($config->providerId)->models[0]->id, 'Compatible model discovery failed.');
    expectSame(3, count($executor->requests()), 'Full compatible profile made unexpected requests.');
    expect(!str_contains(json_encode($executor->requests(), JSON_THROW_ON_ERROR), $secret), 'Compatible history leaked a credential.');
};

$tests['multiple instances keep credentials and endpoints separate'] = static function (): void {
    $configA = config();
    $configB = config(
        'compatible-b',
        'https://second.example/api/v1',
        'GRAV_JARVIS_COMPATIBLE_B_ACCESS_TOKEN'
    );
    setEnvironment($configA->credentialEnvironmentVariable, 'instance-a-fake-secret');
    setEnvironment($configB->credentialEnvironmentVariable, 'instance-b-fake-secret');
    $httpA = new FixtureHttpTransport();
    $httpB = new FixtureHttpTransport();
    $httpA->addResponse(request($configA, 'instance-a-fake-secret', 'GET', '/models'), new HttpResponse(200, [], '{"data":[]}'));
    $httpB->addResponse(request($configB, 'instance-b-fake-secret', 'GET', '/models'), new HttpResponse(200, [], '{"data":[]}'));
    $registry = new ProviderRegistry();
    $registry->register(provider($configA, $httpA));
    $registry->register(provider($configB, $httpB));
    $service = new JarvisService($registry, SecretRedactor::fromEnvironment());
    expectSame(['compatible-a', 'compatible-b'], $service->providerIds(), 'Compatible instance identifiers collided.');
    expect($service->validateProvider('compatible-a')->usable, 'First compatible instance failed.');
    expect($service->validateProvider('compatible-b')->usable, 'Second compatible instance failed.');
    $encoded = json_encode([$configA->toArray(), $configB->toArray()], JSON_THROW_ON_ERROR);
    expect(!str_contains($encoded, 'instance-a-fake-secret'), 'First compatible credential entered config.');
    expect(!str_contains($encoded, 'instance-b-fake-secret'), 'Second compatible credential entered config.');
    setEnvironment($configA->credentialEnvironmentVariable, null);
    setEnvironment($configB->credentialEnvironmentVariable, null);
};

$tests['partial profile declares no model discovery'] = static function () use ($secret): void {
    $config = config(discovery: false);
    setEnvironment($config->credentialEnvironmentVariable, $secret);
    $http = new FixtureHttpTransport();
    $provider = provider($config, $http);
    expectSame(
        ['provider-validation', 'text-completion'],
        $provider->capabilities(),
        'Partial compatible profile overclaimed capabilities.'
    );
    $validation = $provider->validateProvider();
    expect($validation->usable, 'Configuration-only compatible validation failed.');
    expectSame('remote_validation_limited', $validation->issues[0]->code, 'Limited validation warning changed.');
    expectSame([], $http->requests(), 'Partial compatible validation generated content or called HTTP.');
    expectThrows(
        ProviderCapabilityException::class,
        static fn () => $provider->discoverModels(),
        'Undeclared model discovery was silently attempted.'
    );
};

$tests['missing discovery endpoint degrades explicitly'] = static function () use ($secret): void {
    $config = config();
    $http = new FixtureHttpTransport();
    $http->addResponse(request($config, $secret, 'GET', '/models'), new HttpResponse(404, [], 'not supported'));
    $provider = provider($config, $http);
    $validation = $provider->validateProvider();
    expect(!$validation->usable, 'Missing compatible discovery endpoint was reported usable.');
    expectSame('model_discovery_unavailable', $validation->issues[0]->code, 'Missing endpoint category changed.');
};

$tests['incompatible response matrix fails closed'] = static function () use ($secret): void {
    $config = config();
    $payload = ['model' => 'fixture-model', 'input' => 'Incompatible.', 'store' => false];
    $expected = request($config, $secret, 'POST', '/responses', $payload);
    foreach ([
        '{"choices":[{"message":{"content":"chat-only"}}]}',
        '{"output":[{"type":"message","content":"not-a-list"}]}',
        '{"status":"incomplete","output_text":"partial"}',
        '{not-json',
    ] as $body) {
        $http = new FixtureHttpTransport();
        $http->addResponse($expected, new HttpResponse(200, [], $body));
        expectThrows(
            ProviderResponseException::class,
            static fn () => provider($config, $http)->complete(
                new CompletionRequest($config->providerId, 'Incompatible.')
            ),
            'Incompatible provider response was accepted.'
        );
    }
    $rate = new FixtureHttpTransport();
    $rate->addResponse($expected, new HttpResponse(429, ['Retry-After' => '6'], 'limited'));
    $error = expectThrows(
        ProviderRateLimitException::class,
        static fn () => provider($config, $rate)->complete(
            new CompletionRequest($config->providerId, 'Incompatible.')
        ),
        'Compatible rate limit was not typed.'
    );
    expectSame(6, $error->retryAfterSeconds, 'Compatible retry guidance changed.');

    $auth = new FixtureHttpTransport();
    $auth->addResponse($expected, new HttpResponse(401, [], 'unsafe credential detail'));
    expectThrows(
        ProviderAuthenticationException::class,
        static fn () => provider($config, $auth)->complete(
            new CompletionRequest($config->providerId, 'Incompatible.')
        ),
        'Compatible authentication failure was not typed.'
    );

    $server = new FixtureHttpTransport();
    $server->addResponse($expected, new HttpResponse(503, [], 'unsafe server detail'));
    expectThrows(
        HttpTransportException::class,
        static fn () => provider($config, $server)->complete(
            new CompletionRequest($config->providerId, 'Incompatible.')
        ),
        'Compatible server failure was not classified as unavailable.'
    );
};

$tests['configuration and destination security'] = static function () use ($secret): void {
    expectThrows(
        ProviderConfigurationException::class,
        static fn () => config(base: 'http://gateway.example/v1'),
        'Compatible provider accepted plain HTTP.'
    );
    expectThrows(
        ProviderConfigurationException::class,
        static fn () => config(base: 'https://gateway.example/v1/'),
        'Compatible provider accepted an ambiguous trailing-slash base.'
    );
    $config = config();
    setEnvironment($config->credentialEnvironmentVariable, $secret);
    $expected = request($config, $secret, 'GET', '/models');
    $executor = new FixtureHttpExecutor();
    $executor->addResponse($expected, new HttpResponse(200, [], '{"data":[]}'));
    $transport = new BoundedHttpTransport(
        new PublicHttpsDestinationGuard(
            [$config->baseUri],
            new StaticDnsResolver(['gateway.example' => ['10.0.0.8']])
        ),
        $executor
    );
    expectThrows(
        HttpTransportException::class,
        static fn () => provider($config, $transport)->discoverModels(),
        'Compatible provider accepted a private destination.'
    );
    expectSame([], $executor->requests(), 'Private compatible destination reached HTTP execution.');

    $cross = new CompatibleProviderConfig(
        'compatible-a',
        'https://gateway.example/v1',
        'GRAV_JARVIS_COMPATIBLE_B_API_KEY',
        'fixture-model'
    );
    $validation = provider($cross, new FixtureHttpTransport())->validateProvider();
    expectSame('configuration_invalid', $validation->issues[0]->code, 'Cross-instance credential reference was accepted.');
};

$passed = 0;
try {
    foreach ($tests as $name => $test) {
        $test();
        ++$passed;
        fwrite(STDOUT, 'PASS: ' . $name . "\n");
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error::class . ': ' . SecretRedactor::fromEnvironment()->redact($error->getMessage()) . "\n");
    exit(1);
}
setEnvironment('GRAV_JARVIS_COMPATIBLE_A_API_KEY', null);
fwrite(STDOUT, 'Jarvis compatible provider passed (' . $passed . " checks).\n");
