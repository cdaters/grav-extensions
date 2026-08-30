<?php

declare(strict_types=1);

namespace GravJarvisProviderBoundary;

use Grav\Plugin\GravJarvis\Contracts\CompletionRequest;
use Grav\Plugin\GravJarvis\Contracts\Exception\CredentialConfigurationException;
use Grav\Plugin\GravJarvis\Contracts\Exception\HttpTransportException;
use Grav\Plugin\GravJarvis\Contracts\Exception\JarvisException;
use Grav\Plugin\GravJarvis\Contracts\Exception\MalformedCredentialException;
use Grav\Plugin\GravJarvis\Contracts\Exception\MissingCredentialException;
use Grav\Plugin\GravJarvis\Contracts\Exception\ProviderAuthenticationException;
use Grav\Plugin\GravJarvis\Contracts\Exception\ProviderCapabilityException;
use Grav\Plugin\GravJarvis\Contracts\Exception\ProviderFailureException;
use Grav\Plugin\GravJarvis\Contracts\Exception\ProviderNotFoundException;
use Grav\Plugin\GravJarvis\Contracts\Exception\ProviderRateLimitException;
use Grav\Plugin\GravJarvis\Contracts\Exception\ProviderResponseException;
use Grav\Plugin\GravJarvis\Contracts\HttpRequest;
use Grav\Plugin\GravJarvis\Contracts\HttpResponse;
use Grav\Plugin\GravJarvis\Contracts\JarvisServiceInterface;
use Grav\Plugin\GravJarvis\Contracts\ModelCatalog;
use Grav\Plugin\GravJarvis\Contracts\ProviderInterface;
use Grav\Plugin\GravJarvis\Contracts\ProviderIntrospectionServiceInterface;
use Grav\Plugin\GravJarvis\Provider\ProviderRegistry;
use Grav\Plugin\GravJarvis\Security\EnvironmentCredentialResolver;
use Grav\Plugin\GravJarvis\Security\SecretRedactor;
use Grav\Plugin\GravJarvis\Service\JarvisService;
use Grav\Plugin\GravJarvis\Testing\ConformanceFakeProvider;
use Grav\Plugin\GravJarvis\Testing\DeterministicFakeProvider;
use Grav\Plugin\GravJarvis\Testing\FixtureHttpTransport;
use InvalidArgumentException;
use LogicException;
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
    if ($value === null) {
        putenv($name);
        return;
    }
    putenv($name . '=' . $value);
}

function fixtureRequest(
    string $uri,
    EnvironmentCredentialResolver $resolver,
    string $environmentVariable
): HttpRequest {
    return new HttpRequest(
        method: 'GET',
        uri: $uri,
        headers: ['Accept' => 'application/json'],
        credentialHeaders: ['X-Fixture-Credential' => $resolver->resolve($environmentVariable)]
    );
}

/** @return array{ConformanceFakeProvider, FixtureHttpTransport, JarvisService} */
function readyProvider(
    string $secret,
    string $modelsBody = '{"models":[]}',
    string $validationBody = '{"usable":true}'
): array {
    $providerId = 'conformance';
    $environmentVariable = 'GRAV_JARVIS_CONFORMANCE_API_KEY';
    $endpoint = 'https://fixture.invalid/provider';
    setEnvironment($environmentVariable, $secret);
    $resolver = new EnvironmentCredentialResolver($providerId);
    $http = new FixtureHttpTransport();
    $http->addResponse(
        fixtureRequest($endpoint . '/validate', $resolver, $environmentVariable),
        new HttpResponse(200, ['Content-Type' => 'application/json'], $validationBody)
    );
    $http->addResponse(
        fixtureRequest($endpoint . '/models', $resolver, $environmentVariable),
        new HttpResponse(200, ['Content-Type' => 'application/json'], $modelsBody)
    );
    $provider = new ConformanceFakeProvider(
        $providerId,
        $endpoint,
        $environmentVariable,
        $resolver,
        $http
    );
    $registry = new ProviderRegistry();
    $registry->register($provider);
    return [$provider, $http, new JarvisService($registry, SecretRedactor::fromEnvironment())];
}

function optionalValidation(mixed $service, string $providerId): ?array
{
    try {
        if (!$service instanceof ProviderIntrospectionServiceInterface) {
            return null;
        }
        return $service->validateProvider($providerId)->toArray();
    } catch (JarvisException) {
        return null;
    }
}

$secret = 'offline-conformance-secret-74f6c2';
$environmentVariable = 'GRAV_JARVIS_CONFORMANCE_API_KEY';
setEnvironment($environmentVariable, $secret);

/** @var array<string, callable(): void> $tests */
$tests = [];

$tests['0.1.0 public interfaces remain unchanged'] = static function (): void {
    $providerMethods = get_class_methods(ProviderInterface::class);
    $serviceMethods = get_class_methods(JarvisServiceInterface::class);
    sort($providerMethods, SORT_STRING);
    sort($serviceMethods, SORT_STRING);
    expectSame(['capabilities', 'complete', 'id'], $providerMethods, 'ProviderInterface changed incompatibly.');
    expectSame(
        ['capabilities', 'complete', 'providerIds', 'providers'],
        $serviceMethods,
        'JarvisServiceInterface changed incompatibly.'
    );
    expect(
        is_subclass_of(ProviderIntrospectionServiceInterface::class, JarvisServiceInterface::class),
        'The 0.1.1 service contract is not additive.'
    );
};

$tests['environment-only credential resolution'] = static function () use ($secret, $environmentVariable): void {
    setEnvironment($environmentVariable, $secret);
    $resolver = new EnvironmentCredentialResolver('conformance');
    $credential = $resolver->resolve($environmentVariable);
    expectSame('conformance', $resolver->providerId(), 'Credential resolver lost provider scope.');
    expectSame($secret, $credential->reveal(), 'Credential resolver changed the environment value.');
    expectSame($environmentVariable, $credential->environmentVariable(), 'Credential source name changed.');
    expectSame('Bearer ' . $secret, $credential->prefixed('Bearer ')->reveal(), 'Credential prefixing changed.');
    expectThrows(
        InvalidArgumentException::class,
        static fn () => $credential->prefixed("unsafe\n"),
        'Unsafe credential prefix was accepted.'
    );

    $diagnostic = print_r($credential, true) . json_encode($credential, JSON_THROW_ON_ERROR);
    expect(!str_contains($diagnostic, $secret), 'Credential leaked through debug or JSON output.');
    expectThrows(LogicException::class, static fn () => serialize($credential), 'Credential serialization was allowed.');
    expectThrows(
        CredentialConfigurationException::class,
        static fn () => $resolver->resolve('GRAV_JARVIS_ANOTHER_API_KEY'),
        'Cross-provider credential lookup was allowed.'
    );
    expectThrows(
        CredentialConfigurationException::class,
        static fn () => $resolver->resolve('not-an-environment-name'),
        'Malformed credential configuration was allowed.'
    );

    setEnvironment($environmentVariable, null);
    expectThrows(
        MissingCredentialException::class,
        static fn () => $resolver->resolve($environmentVariable),
        'Missing environment credential was accepted.'
    );
    setEnvironment($environmentVariable, ' malformed-credential ');
    $error = expectThrows(
        MalformedCredentialException::class,
        static fn () => $resolver->resolve($environmentVariable),
        'Malformed environment credential was accepted.'
    );
    expect(!str_contains($error->getMessage(), 'malformed-credential'), 'Malformed credential leaked in an exception.');
    setEnvironment($environmentVariable, $secret);
};

$tests['sanitized deterministic HTTP fixtures'] = static function () use ($secret, $environmentVariable): void {
    setEnvironment($environmentVariable, $secret);
    $resolver = new EnvironmentCredentialResolver('conformance');
    $request = fixtureRequest('https://fixture.invalid/check', $resolver, $environmentVariable);
    $encoded = json_encode($request->toArray(), JSON_THROW_ON_ERROR) . print_r($request, true);
    expect(!str_contains($encoded, $secret), 'HTTP request diagnostics leaked a credential.');
    expect(str_contains($encoded, SecretRedactor::REDACTED), 'HTTP request diagnostics omitted redaction.');
    expectSame($secret, $request->headersForTransport()['x-fixture-credential'], 'Transport lost credential value.');
    expectThrows(LogicException::class, static fn () => serialize($request), 'HTTP request serialization was allowed.');
    expectThrows(
        InvalidArgumentException::class,
        static fn () => new HttpRequest(
            'GET',
            'https://fixture.invalid/check',
            ['Authorization' => 'Bearer forbidden']
        ),
        'Plain-text credential header was accepted.'
    );
    expectThrows(
        InvalidArgumentException::class,
        static fn () => new HttpRequest('GET', 'https://fixture.invalid/check?api_key=forbidden'),
        'Credential query parameter was accepted.'
    );
    $bodyRequest = new HttpRequest('POST', 'https://fixture.invalid/body', body: 'private fixture body');
    expect(
        !str_contains(json_encode($bodyRequest->toArray(), JSON_THROW_ON_ERROR), 'private fixture body'),
        'HTTP request diagnostic retained raw body content.'
    );

    $http = new FixtureHttpTransport();
    $response = new HttpResponse(204, ['X-Fixture' => 'ok'], 'body-not-logged');
    $http->addResponse($request, $response);
    expectSame($response, $http->send($request), 'Deterministic HTTP response changed.');
    expectSame($response, $http->send($request), 'Reusable deterministic HTTP fixture changed.');
    $history = json_encode($http->requests(), JSON_THROW_ON_ERROR);
    expect(!str_contains($history, $secret), 'HTTP fixture history retained a credential.');
    expectThrows(LogicException::class, static fn () => serialize($response), 'Raw HTTP response serialization was allowed.');
    expect(!str_contains(print_r($response, true), 'body-not-logged'), 'HTTP response debug output retained its body.');
    expectThrows(
        HttpTransportException::class,
        static fn () => $http->send(new HttpRequest('GET', 'https://fixture.invalid/unmatched')),
        'Unmatched fixture attempted an implicit transport path.'
    );
};

$tests['provider validation does not generate content'] = static function () use ($secret): void {
    [$provider, $http, $service] = readyProvider($secret);
    expect($service instanceof ProviderIntrospectionServiceInterface, 'Jarvis service omitted introspection contract.');
    $result = $service->validateProvider('conformance');
    expect($result->usable, 'Ready offline provider failed validation.');
    expectSame([], $result->issues, 'Ready provider returned validation errors.');
    expectSame(0, $provider->completionCalls(), 'Validation performed content generation.');
    expectSame(1, count($http->requests()), 'Validation performed an unexpected number of HTTP fixture calls.');
};

$tests['provider-neutral model discovery'] = static function () use ($secret): void {
    $models = json_encode([
        'models' => [
            [
                'id' => 'model-z',
                'label' => 'Model Z',
                'description' => 'General offline fixture ' . $secret,
                'capabilities' => ['text-completion'],
                'available' => false,
            ],
            [
                'id' => 'model-a',
                'label' => 'Model A',
                'capabilities' => ['structured-output', 'text-completion'],
                'available' => true,
            ],
        ],
    ], JSON_THROW_ON_ERROR);
    [$provider, $http, $service] = readyProvider($secret, $models);
    $catalog = $service->discoverModels('conformance');
    expect($catalog instanceof ModelCatalog, 'Discovery did not return the provider-neutral catalog DTO.');
    expectSame(['model-a', 'model-z'], array_map(static fn ($model): string => $model->id, $catalog->models), 'Models were not normalized deterministically.');
    expectSame(
        ['id', 'label', 'description', 'capabilities', 'available'],
        array_keys($catalog->models[0]->toArray()),
        'Shared model descriptor exposed an unexpected provider shape.'
    );
    expectSame(0, $provider->completionCalls(), 'Model discovery performed content generation.');
    expectSame(1, count($http->requests()), 'Model discovery performed an unexpected HTTP fixture call.');
    expect(!str_contains(json_encode($catalog->toArray(), JSON_THROW_ON_ERROR), $secret), 'Model catalog leaked a credential.');
};

$tests['missing and malformed provider configuration'] = static function () use ($secret): void {
    $http = new FixtureHttpTransport();
    $badEndpoint = new ConformanceFakeProvider(
        'bad-config',
        'http://fixture.invalid/provider',
        'GRAV_JARVIS_BAD_CONFIG_API_KEY',
        new EnvironmentCredentialResolver('bad-config'),
        $http
    );
    $invalid = $badEndpoint->validateProvider();
    expect(!$invalid->usable, 'Insecure provider endpoint was accepted.');
    expectSame('configuration_invalid', $invalid->issues[0]->code, 'Malformed endpoint category changed.');
    expectSame([], $http->requests(), 'Malformed configuration reached HTTP transport.');

    setEnvironment('GRAV_JARVIS_MISSING_API_KEY', null);
    $missing = new ConformanceFakeProvider(
        'missing',
        'https://fixture.invalid/provider',
        'GRAV_JARVIS_MISSING_API_KEY',
        new EnvironmentCredentialResolver('missing'),
        $http
    );
    $missingResult = $missing->validateProvider();
    expectSame('credential_missing', $missingResult->issues[0]->code, 'Missing credential category changed.');
    $missingRegistry = new ProviderRegistry();
    $missingRegistry->register($missing);
    $missingService = new JarvisService($missingRegistry, SecretRedactor::fromEnvironment());
    expectThrows(
        ProviderFailureException::class,
        static fn () => $missingService->discoverModels('missing'),
        'Missing discovery credential did not preserve the public failure boundary.'
    );

    setEnvironment('GRAV_JARVIS_MALFORMED_API_KEY', "bad\ncredential");
    $malformed = new ConformanceFakeProvider(
        'malformed',
        'https://fixture.invalid/provider',
        'GRAV_JARVIS_MALFORMED_API_KEY',
        new EnvironmentCredentialResolver('malformed'),
        $http
    );
    $malformedResult = $malformed->validateProvider();
    expectSame('credential_invalid', $malformedResult->issues[0]->code, 'Malformed credential category changed.');
    expect(!str_contains(json_encode($malformedResult->toArray(), JSON_THROW_ON_ERROR), 'bad\\ncredential'), 'Credential value leaked through validation DTO.');
    setEnvironment('GRAV_JARVIS_MALFORMED_API_KEY', null);
    setEnvironment('GRAV_JARVIS_BAD_CONFIG_API_KEY', $secret);
};

$tests['malformed provider responses are typed and normalized'] = static function () use ($secret): void {
    [$provider, $_http, $service] = readyProvider($secret, '{not-json');
    expectThrows(
        ProviderResponseException::class,
        static fn () => $provider->discoverModels(),
        'Malformed provider JSON was not typed at the adapter boundary.'
    );
    $error = expectThrows(
        ProviderFailureException::class,
        static fn () => $service->discoverModels('conformance'),
        'Malformed provider JSON was not normalized by Jarvis.'
    );
    expect($error->getPrevious() === null, 'Unsafe malformed-response exception was chained.');

    $invalidModels = '{"models":[{"id":"model-a","capabilities":"not-a-list"}]}';
    [$invalidProvider] = readyProvider($secret, $invalidModels);
    expectThrows(
        ProviderResponseException::class,
        static fn () => $invalidProvider->discoverModels(),
        'Malformed model descriptor was accepted.'
    );
};

$tests['authentication and rate-limit failures'] = static function () use ($secret, $environmentVariable): void {
    setEnvironment($environmentVariable, $secret);
    $endpoint = 'https://fixture.invalid/provider';
    $resolver = new EnvironmentCredentialResolver('conformance');

    $authHttp = new FixtureHttpTransport();
    $authHttp->addResponse(
        fixtureRequest($endpoint . '/models', $resolver, $environmentVariable),
        new HttpResponse(401, [], 'Authorization: Bearer ' . $secret . ' api_key=fixture-auth-key')
    );
    $authHttp->addResponse(
        fixtureRequest($endpoint . '/validate', $resolver, $environmentVariable),
        new HttpResponse(401, [], 'Authorization: Bearer ' . $secret . ' api_key=fixture-auth-key')
    );
    $authProvider = new ConformanceFakeProvider(
        'conformance',
        $endpoint,
        $environmentVariable,
        $resolver,
        $authHttp
    );
    $authError = expectThrows(
        ProviderAuthenticationException::class,
        static fn () => $authProvider->discoverModels(),
        'Authentication-style response was not typed.'
    );
    expect(!str_contains($authError->getMessage(), $secret), 'Authentication failure leaked environment secret.');
    expect(!str_contains($authError->getMessage(), 'fixture-auth-key'), 'Authentication failure leaked API key text.');
    expectSame(
        'authentication_failed',
        $authProvider->validateProvider()->issues[0]->code,
        'Authentication validation category changed.'
    );
    $authRegistry = new ProviderRegistry();
    $authRegistry->register($authProvider);
    $authService = new JarvisService($authRegistry, SecretRedactor::fromEnvironment());
    expectThrows(
        ProviderFailureException::class,
        static fn () => $authService->discoverModels('conformance'),
        'Authentication failure did not retain the 0.1.0 public failure boundary.'
    );

    $rateHttp = new FixtureHttpTransport();
    $rateHttp->addResponse(
        fixtureRequest($endpoint . '/models', $resolver, $environmentVariable),
        new HttpResponse(429, ['Retry-After' => '7'], 'temporarily limited')
    );
    $rateHttp->addResponse(
        fixtureRequest($endpoint . '/validate', $resolver, $environmentVariable),
        new HttpResponse(429, ['Retry-After' => '7'], 'temporarily limited')
    );
    $rateProvider = new ConformanceFakeProvider(
        'conformance',
        $endpoint,
        $environmentVariable,
        $resolver,
        $rateHttp
    );
    $rateError = expectThrows(
        ProviderRateLimitException::class,
        static fn () => $rateProvider->discoverModels(),
        'Rate-limit-style response was not typed.'
    );
    expectSame(7, $rateError->retryAfterSeconds, 'Retry guidance was not normalized.');
    $rateValidation = $rateProvider->validateProvider();
    expectSame('rate_limited', $rateValidation->issues[0]->code, 'Rate-limit validation category changed.');
    expect($rateValidation->issues[0]->retryable, 'Rate-limit validation was not retryable.');

    $registry = new ProviderRegistry();
    $registry->register($rateProvider);
    $service = new JarvisService($registry, SecretRedactor::fromEnvironment());
    expectThrows(
        ProviderFailureException::class,
        static fn () => $service->discoverModels('conformance'),
        'Rate-limit failure did not retain the 0.1.0 public failure boundary.'
    );
};

$tests['transport failures and validation redaction'] = static function () use ($secret, $environmentVariable): void {
    setEnvironment($environmentVariable, $secret);
    $endpoint = 'https://fixture.invalid/provider';
    $resolver = new EnvironmentCredentialResolver('conformance');
    $http = new FixtureHttpTransport();
    $http->addFailure(
        fixtureRequest($endpoint . '/validate', $resolver, $environmentVariable),
        new HttpTransportException('Transport failed with token=' . $secret)
    );
    $provider = new ConformanceFakeProvider(
        'conformance',
        $endpoint,
        $environmentVariable,
        $resolver,
        $http
    );
    $result = $provider->validateProvider();
    expectSame('transport_unavailable', $result->issues[0]->code, 'Transport failure category changed.');
    expect($result->issues[0]->retryable, 'Transport failure was not marked retryable.');
    expect(!str_contains(json_encode($result->toArray(), JSON_THROW_ON_ERROR), $secret), 'Validation result leaked a credential.');

    $errorHttp = new FixtureHttpTransport();
    $errorHttp->addResponse(
        fixtureRequest($endpoint . '/validate', $resolver, $environmentVariable),
        new HttpResponse(503, [], 'temporary upstream failure')
    );
    $errorProvider = new ConformanceFakeProvider(
        'conformance',
        $endpoint,
        $environmentVariable,
        $resolver,
        $errorHttp
    );
    expectSame(
        'transport_unavailable',
        $errorProvider->validateProvider()->issues[0]->code,
        'HTTP error fixture category changed.'
    );
};

$tests['graceful optional introspection behavior'] = static function () use ($secret): void {
    [, , $service] = readyProvider($secret);
    expect(optionalValidation(null, 'conformance') === null, 'Absent Jarvis introspection did not degrade.');
    expect(optionalValidation(new \stdClass(), 'conformance') === null, 'Invalid Jarvis service did not degrade.');
    expect(optionalValidation($service, 'conformance') !== null, 'Available introspection service was missed.');
    expect(optionalValidation($service, 'missing') === null, 'Missing provider did not preserve optional fallback.');

    $registry = new ProviderRegistry();
    $registry->register(new DeterministicFakeProvider());
    $basicService = new JarvisService($registry, SecretRedactor::fromEnvironment());
    expectThrows(
        ProviderCapabilityException::class,
        static fn () => $basicService->validateProvider('fake'),
        'Provider without validation contract did not fail additively.'
    );
    expectThrows(
        ProviderCapabilityException::class,
        static fn () => $basicService->discoverModels('fake'),
        'Provider without discovery contract did not fail additively.'
    );
    expectThrows(
        ProviderNotFoundException::class,
        static fn () => $service->validateProvider('missing'),
        'Missing provider no longer uses the 0.1.0 registry failure.'
    );
};

$tests['deterministic offline behavior'] = static function () use ($secret): void {
    [$provider, $http, $service] = readyProvider($secret);
    $request = new CompletionRequest('conformance', 'Stable offline request.', options: ['limit' => 20]);
    $first = $service->complete($request);
    $second = $service->complete($request);
    expectSame($first->toArray(), $second->toArray(), 'Offline provider response changed for the same input.');
    expectSame(2, $provider->completionCalls(), 'Offline completion count changed.');
    expectSame([], $http->requests(), 'Offline completion unexpectedly used HTTP transport.');
};

$tests['shared contracts remain provider-neutral'] = static function () use ($pluginDirectory): void {
    $forbidden = ["'choices'", '"choices"', '$choices', "'messages'", '"messages"', '$messages', 'max_tokens'];
    $contractFiles = glob($pluginDirectory . '/classes/Contracts/*.php');
    expect(is_array($contractFiles), 'Could not enumerate shared contracts.');
    $source = '';
    foreach ($contractFiles as $file) {
        $source .= strtolower(file_get_contents($file) ?: '');
    }
    foreach ($forbidden as $term) {
        expect(!str_contains($source, $term), 'Shared contract introduced provider-specific term: ' . $term);
    }
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
    setEnvironment($environmentVariable, null);
    exit(1);
}

setEnvironment($environmentVariable, null);
fwrite(STDOUT, 'Jarvis provider boundary passed (' . $passed . " checks).\n");
