<?php

declare(strict_types=1);

namespace GravJarvisOpenAIProvider;

use Grav\Plugin\GravJarvis\Contracts\CompletionRequest;
use Grav\Plugin\GravJarvis\Contracts\Exception\HttpTransportException;
use Grav\Plugin\GravJarvis\Contracts\Exception\ProviderAuthenticationException;
use Grav\Plugin\GravJarvis\Contracts\Exception\ProviderConfigurationException;
use Grav\Plugin\GravJarvis\Contracts\Exception\ProviderFailureException;
use Grav\Plugin\GravJarvis\Contracts\Exception\ProviderRateLimitException;
use Grav\Plugin\GravJarvis\Contracts\Exception\ProviderResponseException;
use Grav\Plugin\GravJarvis\Contracts\HttpRequest;
use Grav\Plugin\GravJarvis\Contracts\HttpResponse;
use Grav\Plugin\GravJarvis\Contracts\HttpTransportInterface;
use Grav\Plugin\GravJarvis\Contracts\ProviderIntrospectionServiceInterface;
use Grav\Plugin\GravJarvis\Provider\OpenAI\OpenAIProvider;
use Grav\Plugin\GravJarvis\Provider\ProviderRegistry;
use Grav\Plugin\GravJarvis\Security\EnvironmentCredentialResolver;
use Grav\Plugin\GravJarvis\Security\SecretRedactor;
use Grav\Plugin\GravJarvis\Service\JarvisService;
use Grav\Plugin\GravJarvis\Testing\FixtureHttpExecutor;
use Grav\Plugin\GravJarvis\Testing\FixtureHttpTransport;
use Grav\Plugin\GravJarvis\Testing\StaticDnsResolver;
use Grav\Plugin\GravJarvis\Transport\BoundedHttpTransport;
use Grav\Plugin\GravJarvis\Transport\HttpTransportConfig;
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
    if ($value === null) {
        putenv($name);
        return;
    }
    putenv($name . '=' . $value);
}

/** @param array<string, mixed>|null $payload */
function openAiRequest(string $method, string $path, string $secret, ?array $payload = null): HttpRequest
{
    setEnvironment(OpenAIProvider::CREDENTIAL_ENVIRONMENT_VARIABLE, $secret);
    $credential = (new EnvironmentCredentialResolver(OpenAIProvider::ID))
        ->resolve(OpenAIProvider::CREDENTIAL_ENVIRONMENT_VARIABLE)
        ->prefixed('Bearer ');
    $body = $payload === null ? null : json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
    return new HttpRequest(
        method: $method,
        uri: OpenAIProvider::API_BASE_URI . $path,
        headers: [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ],
        body: $body,
        credentialHeaders: ['Authorization' => $credential]
    );
}

function openAiProvider(
    HttpTransportInterface $http,
    string $defaultModel = OpenAIProvider::DEFAULT_MODEL
): OpenAIProvider {
    return new OpenAIProvider(
        $defaultModel,
        new EnvironmentCredentialResolver(OpenAIProvider::ID),
        $http
    );
}

function openAiService(OpenAIProvider $provider): JarvisService
{
    $registry = new ProviderRegistry();
    $registry->register($provider);
    return new JarvisService($registry, SecretRedactor::fromEnvironment());
}

/** @return array<string, mixed> */
function completionPayload(
    string $input = 'Explain the deterministic fixture.',
    string $model = OpenAIProvider::DEFAULT_MODEL,
    ?string $instructions = 'Return plain text.',
    ?int $maxOutputUnits = null
): array {
    $payload = [
        'model' => $model,
        'input' => $input,
        'store' => false,
    ];
    if ($instructions !== null) {
        $payload['instructions'] = $instructions;
    }
    if ($maxOutputUnits !== null) {
        $payload['max_output_tokens'] = $maxOutputUnits;
    }
    return $payload;
}

function successfulCompletionBody(string $secret = ''): string
{
    return json_encode([
        'id' => 'vendor-response-id-is-not-exported',
        'object' => 'response',
        'status' => 'completed',
        'model' => OpenAIProvider::DEFAULT_MODEL,
        'output' => [
            ['type' => 'reasoning', 'summary' => []],
            [
                'type' => 'message',
                'role' => 'assistant',
                'content' => [
                    ['type' => 'output_text', 'text' => 'Deterministic OpenAI output.'],
                ],
            ],
        ],
        'usage' => [
            'input_tokens' => 11,
            'output_tokens' => 7,
            'total_tokens' => 18,
        ],
        'ignored_vendor_field' => $secret,
    ], JSON_THROW_ON_ERROR);
}

function modelListBody(): string
{
    return json_encode([
        'object' => 'list',
        'data' => [
            ['id' => 'model-z', 'object' => 'model', 'owned_by' => 'vendor-owner'],
            ['id' => 'model-a', 'object' => 'model', 'owned_by' => 'vendor-owner'],
        ],
    ], JSON_THROW_ON_ERROR);
}

$secret = 'offline-openai-secret-1d7f65';
setEnvironment(OpenAIProvider::CREDENTIAL_ENVIRONMENT_VARIABLE, $secret);

/** @var array<string, callable(): void> $tests */
$tests = [];

$tests['bounded production transport policy'] = static function () use ($secret): void {
    $expected = openAiRequest('GET', '/models', $secret);
    $executor = new FixtureHttpExecutor();
    $executor->addResponse($expected, new HttpResponse(200, [], modelListBody()));
    $config = new HttpTransportConfig(
        connectTimeoutMilliseconds: 1200,
        requestTimeoutMilliseconds: 5000,
        maxRequestBytes: 1024,
        maxResponseBytes: 4096,
        maxResponseHeaderBytes: 4096
    );
    $transport = new BoundedHttpTransport(
        new PublicHttpsDestinationGuard(
            [OpenAIProvider::API_BASE_URI],
            new StaticDnsResolver(['api.openai.com' => ['93.184.216.34']])
        ),
        $executor,
        $config
    );
    expectSame(200, $transport->send($expected)->status, 'Bounded transport changed a fixture response.');
    $history = $executor->requests();
    expectSame('93.184.216.34', $history[0]['destination']['address'] ?? null, 'DNS destination was not pinned.');
    expectSame(false, $history[0]['config']['redirects_allowed'] ?? null, 'Redirects were not disabled.');
    expectSame(false, $history[0]['config']['environment_proxy_allowed'] ?? null, 'Environment proxy use was not disabled.');
    expectSame(1200, $history[0]['config']['connect_timeout_ms'] ?? null, 'Connect timeout was not explicit.');
    expectSame(5000, $history[0]['config']['request_timeout_ms'] ?? null, 'Request timeout was not explicit.');
    expect(!str_contains(json_encode($history, JSON_THROW_ON_ERROR), $secret), 'Transport history leaked a credential.');

    $privateTransport = new BoundedHttpTransport(
        new PublicHttpsDestinationGuard(
            [OpenAIProvider::API_BASE_URI],
            new StaticDnsResolver(['api.openai.com' => ['127.0.0.1']])
        ),
        $executor,
        $config
    );
    expectThrows(
        HttpTransportException::class,
        static fn () => $privateTransport->send($expected),
        'Private provider destination was accepted.'
    );
    expectThrows(
        HttpTransportException::class,
        static fn () => $transport->send(new HttpRequest('GET', 'https://example.com/v1/models')),
        'Non-allowlisted provider origin was accepted.'
    );
    expectThrows(
        HttpTransportException::class,
        static fn () => $transport->send(new HttpRequest('GET', 'http://api.openai.com/v1/models')),
        'Plain HTTP provider destination was accepted.'
    );
    expectThrows(
        HttpTransportException::class,
        static fn () => $transport->send(new HttpRequest('GET', 'https://api.openai.com/internal')),
        'Provider path outside the allowed base was accepted.'
    );
    $queryRequest = openAiRequest('GET', '/models?cursor=opaque', $secret);
    $executor->addResponse($queryRequest, new HttpResponse(200, [], modelListBody()));
    expectSame(
        200,
        $transport->send($queryRequest)->status,
        'Bounded non-secret query on an allowlisted provider path was rejected.'
    );
    expectThrows(
        \InvalidArgumentException::class,
        static fn () => new HttpRequest(
            'GET',
            OpenAIProvider::API_BASE_URI . '/models?api_key=forbidden'
        ),
        'Provider credential query parameter was accepted.'
    );
    expectThrows(
        HttpTransportException::class,
        static fn () => $transport->send(new HttpRequest(
            'GET',
            OpenAIProvider::API_BASE_URI . '/models',
            ['Host' => 'example.com']
        )),
        'Transport-controlled Host header was accepted.'
    );

    $smallTransport = new BoundedHttpTransport(
        new PublicHttpsDestinationGuard(
            [OpenAIProvider::API_BASE_URI],
            new StaticDnsResolver(['api.openai.com' => ['93.184.216.34']])
        ),
        $executor,
        new HttpTransportConfig(maxRequestBytes: 8)
    );
    expectThrows(
        HttpTransportException::class,
        static fn () => $smallTransport->send(
            new HttpRequest('POST', OpenAIProvider::API_BASE_URI . '/responses', body: 'bounded-body')
        ),
        'Oversized provider request reached the executor.'
    );
};

$tests['service completion through bounded OpenAI transport'] = static function () use ($secret): void {
    $input = 'Explain the deterministic fixture.';
    $instructions = 'Return plain text.';
    $expected = openAiRequest(
        'POST',
        '/responses',
        $secret,
        completionPayload($input, OpenAIProvider::DEFAULT_MODEL, $instructions)
    );
    $executor = new FixtureHttpExecutor();
    $executor->addResponse($expected, new HttpResponse(200, [], successfulCompletionBody($secret)));
    $transport = new BoundedHttpTransport(
        new PublicHttpsDestinationGuard(
            [OpenAIProvider::API_BASE_URI],
            new StaticDnsResolver(['api.openai.com' => ['93.184.216.34']])
        ),
        $executor
    );
    $service = openAiService(openAiProvider($transport));
    expect($service instanceof ProviderIntrospectionServiceInterface, 'OpenAI service lost introspection support.');
    $result = $service->complete(new CompletionRequest(
        providerId: OpenAIProvider::ID,
        input: $input,
        instructions: $instructions
    ));
    expectSame('Deterministic OpenAI output.', $result->output, 'OpenAI text was not normalized.');
    expectSame(OpenAIProvider::DEFAULT_MODEL, $result->model, 'OpenAI model was not normalized.');
    expectSame('tokens', $result->usage->unit, 'OpenAI usage unit was not normalized.');
    expectSame(11, $result->usage->inputUnits, 'OpenAI input usage changed.');
    expectSame(7, $result->usage->outputUnits, 'OpenAI output usage changed.');
    expectSame(18, $result->usage->totalUnits, 'OpenAI total usage changed.');
    expect($result->usage->providerReported, 'Provider-reported usage lost its provenance.');
    expectSame([], $result->metadata, 'OpenAI vendor fields crossed into shared metadata.');
    expectSame(1, count($executor->requests()), 'OpenAI completion issued an unexpected HTTP request.');
    expect(!str_contains(json_encode($result->toArray(), JSON_THROW_ON_ERROR), $secret), 'OpenAI result leaked a credential.');
};

$tests['model discovery and validation without generation'] = static function () use ($secret): void {
    $http = new FixtureHttpTransport();
    $modelsRequest = openAiRequest('GET', '/models', $secret);
    $http->addResponse($modelsRequest, new HttpResponse(200, [], modelListBody()));
    $provider = openAiProvider($http);
    $validation = $provider->validateProvider();
    expect($validation->usable, 'Valid OpenAI fixture failed provider validation.');
    expectSame([], $validation->issues, 'Valid OpenAI fixture returned validation issues.');
    $catalog = $provider->discoverModels();
    expectSame(
        ['model-a', 'model-z'],
        array_map(static fn ($model): string => $model->id, $catalog->models),
        'OpenAI models were not normalized deterministically.'
    );
    expectSame([], $catalog->models[0]->capabilities, 'OpenAI vendor metadata became shared capability claims.');
    expectSame(2, count($http->requests()), 'Validation/discovery used an unexpected endpoint count.');
    foreach ($http->requests() as $request) {
        expectSame('GET', $request['method'] ?? null, 'OpenAI validation performed content generation.');
        expect(str_ends_with((string) ($request['uri'] ?? ''), '/models'), 'OpenAI validation used the generation endpoint.');
    }
};

$tests['default and explicit model selection'] = static function () use ($secret): void {
    $http = new FixtureHttpTransport();
    $defaultRequest = openAiRequest(
        'POST',
        '/responses',
        $secret,
        completionPayload('Default model input.', OpenAIProvider::DEFAULT_MODEL, null)
    );
    $http->addResponse(
        $defaultRequest,
        new HttpResponse(200, [], json_encode([
            'model' => OpenAIProvider::DEFAULT_MODEL,
            'output_text' => 'Default model output.',
        ], JSON_THROW_ON_ERROR))
    );
    $explicitRequest = openAiRequest(
        'POST',
        '/responses',
        $secret,
        completionPayload('Explicit model input.', 'operator-selected-model', null)
    );
    $http->addResponse(
        $explicitRequest,
        new HttpResponse(200, [], json_encode([
            'model' => 'operator-selected-model',
            'output_text' => 'Explicit model output.',
        ], JSON_THROW_ON_ERROR))
    );
    $boundedRequest = openAiRequest(
        'POST',
        '/responses',
        $secret,
        completionPayload('Bounded output input.', OpenAIProvider::DEFAULT_MODEL, null, 64)
    );
    $http->addResponse(
        $boundedRequest,
        new HttpResponse(200, [], json_encode([
            'model' => OpenAIProvider::DEFAULT_MODEL,
            'output_text' => 'Bounded output.',
        ], JSON_THROW_ON_ERROR))
    );
    $provider = openAiProvider($http);
    expectSame(
        'Default model output.',
        $provider->complete(new CompletionRequest(OpenAIProvider::ID, 'Default model input.'))->output,
        'OpenAI default model path changed.'
    );
    expectSame(
        'Explicit model output.',
        $provider->complete(new CompletionRequest(
            OpenAIProvider::ID,
            'Explicit model input.',
            model: 'operator-selected-model'
        ))->output,
        'Provider-neutral explicit model selection was ignored.'
    );
    expectSame(
        'Bounded output.',
        $provider->complete(new CompletionRequest(
            OpenAIProvider::ID,
            'Bounded output input.',
            options: ['max_output_units' => 64]
        ))->output,
        'Provider-neutral output limit was not mapped by OpenAI.'
    );
};

$tests['missing malformed credentials and configuration'] = static function () use ($secret): void {
    setEnvironment(OpenAIProvider::CREDENTIAL_ENVIRONMENT_VARIABLE, null);
    $missingHttp = new FixtureHttpTransport();
    $missing = openAiProvider($missingHttp)->validateProvider();
    expectSame('credential_missing', $missing->issues[0]->code, 'Missing OpenAI credential category changed.');
    expectSame([], $missingHttp->requests(), 'Missing credential reached HTTP transport.');

    setEnvironment(OpenAIProvider::CREDENTIAL_ENVIRONMENT_VARIABLE, "credential-value-should-not-leak\n");
    $malformedHttp = new FixtureHttpTransport();
    $malformed = openAiProvider($malformedHttp)->validateProvider();
    expectSame('credential_invalid', $malformed->issues[0]->code, 'Malformed OpenAI credential category changed.');
    expect(
        !str_contains(
            json_encode($malformed->toArray(), JSON_THROW_ON_ERROR),
            'credential-value-should-not-leak'
        ),
        'Malformed OpenAI credential value crossed into validation output.'
    );
    expectSame([], $malformedHttp->requests(), 'Malformed credential reached HTTP transport.');

    setEnvironment(OpenAIProvider::CREDENTIAL_ENVIRONMENT_VARIABLE, $secret);
    $invalidHttp = new FixtureHttpTransport();
    $invalid = openAiProvider($invalidHttp, "bad\nmodel")->validateProvider();
    expectSame('configuration_invalid', $invalid->issues[0]->code, 'Invalid OpenAI configuration category changed.');
    expectSame([], $invalidHttp->requests(), 'Invalid OpenAI configuration reached HTTP transport.');
    expectThrows(
        ProviderConfigurationException::class,
        static fn () => openAiProvider($invalidHttp, "bad\nmodel")->complete(
            new CompletionRequest(OpenAIProvider::ID, 'Invalid configuration.')
        ),
        'Invalid OpenAI configuration reached completion transport.'
    );

    expectThrows(
        ProviderConfigurationException::class,
        static fn () => openAiProvider($invalidHttp)->complete(new CompletionRequest(
            OpenAIProvider::ID,
            'Unsupported option.',
            options: ['output-limit' => 8]
        )),
        'Unsupported OpenAI request option was silently ignored.'
    );
};

$tests['malformed and empty provider responses'] = static function () use ($secret): void {
    $request = openAiRequest(
        'POST',
        '/responses',
        $secret,
        completionPayload()
    );
    foreach ([
        '{not-json',
        '{"model":"gpt-5.6-luna","output":[]}',
        '{"model":"gpt-5.6-luna","output":[{"type":"message","content":[]}]}',
        '{"model":"gpt-5.6-luna","status":"incomplete","output_text":"partial"}',
        '{"model":"gpt-5.6-luna","output_text":"ok","usage":{"input_tokens":"bad"}}',
        '{"model":"gpt-5.6-luna","error":{"message":"unsafe vendor detail"}}',
    ] as $body) {
        $http = new FixtureHttpTransport();
        $http->addResponse($request, new HttpResponse(200, [], $body));
        expectThrows(
            ProviderResponseException::class,
            static fn () => openAiProvider($http)->complete(new CompletionRequest(
                OpenAIProvider::ID,
                'Explain the deterministic fixture.',
                instructions: 'Return plain text.'
            )),
            'Malformed or empty OpenAI completion response was accepted.'
        );
    }

    $modelsRequest = openAiRequest('GET', '/models', $secret);
    foreach (['{not-json', '{"data":"not-a-list"}', '{"data":[{"id":7}]}'] as $body) {
        $http = new FixtureHttpTransport();
        $http->addResponse($modelsRequest, new HttpResponse(200, [], $body));
        expectThrows(
            ProviderResponseException::class,
            static fn () => openAiProvider($http)->discoverModels(),
            'Malformed OpenAI model response was accepted.'
        );
    }
};

$tests['authentication rate limit and HTTP classification'] = static function () use ($secret): void {
    $modelsRequest = openAiRequest('GET', '/models', $secret);
    $authHttp = new FixtureHttpTransport();
    $authHttp->addResponse(
        $modelsRequest,
        new HttpResponse(401, [], 'Authorization: Bearer ' . $secret . ' api_key=unsafe')
    );
    $authError = expectThrows(
        ProviderAuthenticationException::class,
        static fn () => openAiProvider($authHttp)->discoverModels(),
        'OpenAI authentication failure was not typed.'
    );
    expect(!str_contains($authError->getMessage(), $secret), 'OpenAI authentication failure leaked a credential.');
    expect(!str_contains($authError->getMessage(), 'unsafe'), 'OpenAI authentication failure leaked its body.');

    $rateHttp = new FixtureHttpTransport();
    $rateHttp->addResponse($modelsRequest, new HttpResponse(429, ['Retry-After' => '9'], 'limited'));
    $rateError = expectThrows(
        ProviderRateLimitException::class,
        static fn () => openAiProvider($rateHttp)->discoverModels(),
        'OpenAI rate limit was not typed.'
    );
    expectSame(9, $rateError->retryAfterSeconds, 'OpenAI retry guidance was not normalized.');

    $clientHttp = new FixtureHttpTransport();
    $clientHttp->addResponse($modelsRequest, new HttpResponse(422, [], 'unsafe client detail'));
    expectThrows(
        ProviderConfigurationException::class,
        static fn () => openAiProvider($clientHttp)->discoverModels(),
        'OpenAI client error was not typed.'
    );

    $serverHttp = new FixtureHttpTransport();
    $serverHttp->addResponse($modelsRequest, new HttpResponse(503, [], 'unsafe server detail'));
    expectThrows(
        HttpTransportException::class,
        static fn () => openAiProvider($serverHttp)->discoverModels(),
        'OpenAI server error was not classified as unavailable transport.'
    );
};

$tests['timeout transport failure and service redaction'] = static function () use ($secret): void {
    $request = openAiRequest(
        'POST',
        '/responses',
        $secret,
        completionPayload()
    );
    $http = new FixtureHttpTransport();
    $http->addFailure(
        $request,
        new HttpTransportException('Timed out with token=' . $secret)
    );
    $service = openAiService(openAiProvider($http));
    $error = expectThrows(
        ProviderFailureException::class,
        static fn () => $service->complete(new CompletionRequest(
            OpenAIProvider::ID,
            'Explain the deterministic fixture.',
            instructions: 'Return plain text.'
        )),
        'OpenAI timeout did not preserve the public failure boundary.'
    );
    expect(!str_contains($error->getMessage(), $secret), 'OpenAI transport failure leaked a credential.');
    expect(str_contains($error->getMessage(), SecretRedactor::REDACTED), 'OpenAI failure omitted redaction evidence.');
    expect($error->getPrevious() === null, 'Unsafe OpenAI transport failure was chained.');
};

$tests['deterministic offline and provider-neutral core'] = static function () use ($secret, $pluginDirectory): void {
    $http = new FixtureHttpTransport();
    expectThrows(
        HttpTransportException::class,
        static fn () => openAiProvider($http)->discoverModels(),
        'OpenAI adapter attempted an implicit network fallback.'
    );
    expectSame(1, count($http->requests()), 'Unmatched OpenAI fixture did not record one sanitized request.');
    expect(!str_contains(json_encode($http->requests(), JSON_THROW_ON_ERROR), $secret), 'Offline history leaked a credential.');

    $contractFiles = glob($pluginDirectory . '/classes/Contracts/*.php');
    expect(is_array($contractFiles), 'Could not enumerate shared Jarvis contracts.');
    $source = '';
    foreach ($contractFiles as $file) {
        $source .= strtolower(file_get_contents($file) ?: '');
    }
    foreach (['openai', "'/responses'", '"/responses"', 'output_text', 'input_tokens', 'output_tokens'] as $term) {
        expect(!str_contains($source, $term), 'OpenAI term escaped into a shared contract: ' . $term);
    }

    $config = file_get_contents($pluginDirectory . '/grav-jarvis.yaml') ?: '';
    $blueprint = file_get_contents($pluginDirectory . '/blueprints.yaml') ?: '';
    expect(!str_contains($config, 'api_key'), 'OpenAI API credential field entered plugin YAML.');
    expect(!preg_match('/^[[:space:]]*(?:api[_-]?key|secret|token|credential)[[:space:]]*:/mi', $blueprint), 'OpenAI API credential field entered the Admin blueprint.');
};

$passed = 0;
try {
    foreach ($tests as $name => $test) {
        setEnvironment(OpenAIProvider::CREDENTIAL_ENVIRONMENT_VARIABLE, $secret);
        $test();
        ++$passed;
        fwrite(STDOUT, 'PASS: ' . $name . "\n");
    }
} catch (Throwable $error) {
    $safe = SecretRedactor::fromEnvironment()->redact($error->getMessage());
    fwrite(STDERR, 'FAIL: ' . $error::class . ': ' . $safe . "\n");
    setEnvironment(OpenAIProvider::CREDENTIAL_ENVIRONMENT_VARIABLE, null);
    exit(1);
}

setEnvironment(OpenAIProvider::CREDENTIAL_ENVIRONMENT_VARIABLE, null);
fwrite(STDOUT, 'Jarvis OpenAI provider passed (' . $passed . " checks).\n");
