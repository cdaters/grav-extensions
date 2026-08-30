<?php

declare(strict_types=1);

namespace GravJarvisAnthropicProvider;

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
use Grav\Plugin\GravJarvis\Provider\Anthropic\AnthropicProvider;
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
function anthropicRequest(string $method, string $path, string $secret, ?array $payload = null): HttpRequest
{
    setEnvironment(AnthropicProvider::CREDENTIAL_ENVIRONMENT_VARIABLE, $secret);
    $credential = (new EnvironmentCredentialResolver(AnthropicProvider::ID))
        ->resolve(AnthropicProvider::CREDENTIAL_ENVIRONMENT_VARIABLE);
    $body = $payload === null ? null : json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
    return new HttpRequest(
        method: $method,
        uri: AnthropicProvider::API_BASE_URI . $path,
        headers: [
            'Accept' => 'application/json',
            'Anthropic-Version' => AnthropicProvider::API_VERSION,
            'Content-Type' => 'application/json',
        ],
        body: $body,
        credentialHeaders: ['X-Api-Key' => $credential]
    );
}

function anthropicProvider(
    HttpTransportInterface $http,
    string $defaultModel = AnthropicProvider::DEFAULT_MODEL,
    ?EnvironmentCredentialResolver $credentials = null
): AnthropicProvider {
    return new AnthropicProvider(
        $defaultModel,
        $credentials ?? new EnvironmentCredentialResolver(AnthropicProvider::ID),
        $http
    );
}

function anthropicService(AnthropicProvider $provider): JarvisService
{
    $registry = new ProviderRegistry();
    $registry->register($provider);
    return new JarvisService($registry, SecretRedactor::fromEnvironment());
}

/** @return array<string, mixed> */
function completionPayload(
    string $input = 'Confirm the deterministic Anthropic fixture.',
    string $model = AnthropicProvider::DEFAULT_MODEL,
    ?string $instructions = 'Return plain text.',
    int $maxOutputUnits = AnthropicProvider::DEFAULT_MAX_OUTPUT_UNITS
): array {
    $payload = [
        'model' => $model,
        'max_tokens' => $maxOutputUnits,
        'messages' => [[
            'role' => 'user',
            'content' => $input,
        ]],
    ];
    if ($instructions !== null) {
        $payload['system'] = $instructions;
    }
    return $payload;
}

function modelListBody(): string
{
    return json_encode([
        'data' => [
            [
                'id' => 'claude-model-z',
                'display_name' => 'Claude Model Z',
                'type' => 'model',
                'created_at' => '2026-01-02T00:00:00Z',
                'capabilities' => ['thinking' => ['supported' => true]],
            ],
            [
                'id' => 'claude-model-a',
                'display_name' => 'Claude Model A',
                'type' => 'model',
                'created_at' => '2026-01-01T00:00:00Z',
                'capabilities' => ['image_input' => ['supported' => true]],
            ],
        ],
        'has_more' => false,
        'first_id' => 'claude-model-z',
        'last_id' => 'claude-model-a',
    ], JSON_THROW_ON_ERROR);
}

function successfulCompletionBody(bool $usage = true, string $secret = ''): string
{
    $payload = [
        'id' => 'vendor-message-id-is-not-exported',
        'type' => 'message',
        'role' => 'assistant',
        'model' => AnthropicProvider::DEFAULT_MODEL,
        'content' => [
            ['type' => 'thinking', 'thinking' => 'vendor-only block'],
            ['type' => 'text', 'text' => 'Deterministic '],
            ['type' => 'text', 'text' => 'Anthropic output.'],
        ],
        'stop_reason' => 'end_turn',
        'stop_sequence' => null,
        'ignored_vendor_field' => $secret,
    ];
    if ($usage) {
        $payload['usage'] = [
            'input_tokens' => 13,
            'output_tokens' => 5,
            'cache_read_input_tokens' => 3,
            'service_tier' => 'standard',
        ];
    }
    return json_encode($payload, JSON_THROW_ON_ERROR);
}

$secret = 'offline-anthropic-secret-8e4c73';
setEnvironment(AnthropicProvider::CREDENTIAL_ENVIRONMENT_VARIABLE, $secret);

/** @var array<string, callable(): void> $tests */
$tests = [];

$tests['bounded production transport and Anthropic headers'] = static function () use ($secret): void {
    $expected = anthropicRequest('GET', '/models?limit=1000', $secret);
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
            [AnthropicProvider::API_BASE_URI],
            new StaticDnsResolver(['api.anthropic.com' => ['93.184.216.34']])
        ),
        $executor,
        $config
    );
    expectSame(200, $transport->send($expected)->status, 'Bounded transport changed the Anthropic fixture.');
    $history = $executor->requests();
    expectSame('93.184.216.34', $history[0]['destination']['address'] ?? null, 'Anthropic DNS was not pinned.');
    expectSame(false, $history[0]['config']['redirects_allowed'] ?? null, 'Anthropic redirects were not disabled.');
    expectSame(false, $history[0]['config']['environment_proxy_allowed'] ?? null, 'Proxy inheritance was not disabled.');
    expectSame(
        AnthropicProvider::API_VERSION,
        $history[0]['request']['headers']['anthropic-version'] ?? null,
        'Anthropic API version header changed.'
    );
    expectSame(
        SecretRedactor::REDACTED,
        $history[0]['request']['headers']['x-api-key'] ?? null,
        'Anthropic credential header was not redacted.'
    );
    expect(!str_contains(json_encode($history, JSON_THROW_ON_ERROR), $secret), 'Transport history leaked an Anthropic credential.');

    $private = new BoundedHttpTransport(
        new PublicHttpsDestinationGuard(
            [AnthropicProvider::API_BASE_URI],
            new StaticDnsResolver(['api.anthropic.com' => ['127.0.0.1']])
        ),
        $executor,
        $config
    );
    expectThrows(
        HttpTransportException::class,
        static fn () => $private->send($expected),
        'Anthropic private destination was accepted.'
    );
};

$tests['validation model discovery and capability metadata'] = static function () use ($secret): void {
    $http = new FixtureHttpTransport();
    $modelsRequest = anthropicRequest('GET', '/models?limit=1000', $secret);
    $http->addResponse($modelsRequest, new HttpResponse(200, [], modelListBody()));
    $provider = anthropicProvider($http);
    $validation = $provider->validateProvider();
    expect($validation->usable, 'Valid Anthropic fixture failed validation.');
    expectSame([], $validation->issues, 'Valid Anthropic fixture returned validation issues.');
    expectSame(
        ['model-discovery', 'provider-validation', 'text-completion'],
        $validation->capabilities,
        'Anthropic provider capabilities changed.'
    );
    $catalog = $provider->discoverModels();
    expectSame(
        ['claude-model-a', 'claude-model-z'],
        array_map(static fn ($model): string => $model->id, $catalog->models),
        'Anthropic models were not normalized deterministically.'
    );
    expectSame('Claude Model A', $catalog->models[0]->label, 'Anthropic display name was not normalized.');
    expectSame(['text-completion'], $catalog->models[0]->capabilities, 'Vendor capabilities leaked or were overclaimed.');
    expectSame(2, count($http->requests()), 'Anthropic validation/discovery used an unexpected request count.');
    foreach ($http->requests() as $request) {
        expectSame('GET', $request['method'] ?? null, 'Anthropic validation performed generation.');
        expect(
            str_contains((string) ($request['uri'] ?? ''), '/models?limit=1000'),
            'Validation used a generation endpoint.'
        );
    }
};

$tests['bounded cursor model discovery'] = static function () use ($secret): void {
    $first = anthropicRequest('GET', '/models?limit=1000', $secret);
    $second = anthropicRequest(
        'GET',
        '/models?limit=1000&after_id=claude-model-b',
        $secret
    );
    $http = new FixtureHttpTransport();
    $http->addResponse($first, new HttpResponse(200, [], json_encode([
        'data' => [[
            'id' => 'claude-model-b',
            'display_name' => 'Claude Model B',
            'type' => 'model',
        ]],
        'has_more' => true,
        'last_id' => 'claude-model-b',
    ], JSON_THROW_ON_ERROR)));
    $http->addResponse($second, new HttpResponse(200, [], json_encode([
        'data' => [[
            'id' => 'claude-model-a',
            'display_name' => 'Claude Model A',
            'type' => 'model',
        ]],
        'has_more' => false,
        'last_id' => 'claude-model-a',
    ], JSON_THROW_ON_ERROR)));
    $catalog = anthropicProvider($http)->discoverModels();
    expectSame(
        ['claude-model-a', 'claude-model-b'],
        array_map(static fn ($model): string => $model->id, $catalog->models),
        'Anthropic cursor pages were not normalized into one catalog.'
    );
    expectSame(2, count($http->requests()), 'Anthropic cursor discovery exceeded the expected page count.');
};

$tests['service completion usage and multiple content blocks'] = static function () use ($secret): void {
    $input = 'Confirm the deterministic Anthropic fixture.';
    $instructions = 'Return plain text.';
    $expected = anthropicRequest(
        'POST',
        '/messages',
        $secret,
        completionPayload($input, AnthropicProvider::DEFAULT_MODEL, $instructions, 77)
    );
    $http = new FixtureHttpTransport();
    $http->addResponse($expected, new HttpResponse(200, [], successfulCompletionBody(true, $secret)));
    $service = anthropicService(anthropicProvider($http));
    $result = $service->complete(new CompletionRequest(
        providerId: AnthropicProvider::ID,
        input: $input,
        instructions: $instructions,
        options: ['max_output_units' => 77]
    ));
    expectSame('Deterministic Anthropic output.', $result->output, 'Anthropic text blocks were not normalized.');
    expectSame(AnthropicProvider::DEFAULT_MODEL, $result->model, 'Anthropic model was not normalized.');
    expectSame(13, $result->usage->inputUnits, 'Anthropic input usage changed.');
    expectSame(5, $result->usage->outputUnits, 'Anthropic output usage changed.');
    expectSame(18, $result->usage->totalUnits, 'Anthropic total usage changed.');
    expectSame('tokens', $result->usage->unit, 'Anthropic usage unit changed.');
    expect($result->usage->providerReported, 'Anthropic provider usage lost provenance.');
    expectSame([], $result->metadata, 'Anthropic vendor metadata crossed the public boundary.');
    expect(!str_contains(json_encode($result->toArray(), JSON_THROW_ON_ERROR), $secret), 'Anthropic result leaked a credential.');
};

$tests['completion without provider usage remains usable'] = static function () use ($secret): void {
    $expected = anthropicRequest('POST', '/messages', $secret, completionPayload());
    $http = new FixtureHttpTransport();
    $http->addResponse($expected, new HttpResponse(200, [], successfulCompletionBody(false)));
    $result = anthropicProvider($http)->complete(new CompletionRequest(
        AnthropicProvider::ID,
        'Confirm the deterministic Anthropic fixture.',
        instructions: 'Return plain text.'
    ));
    expectSame('Deterministic Anthropic output.', $result->output, 'Completion without usage failed.');
    expect(!$result->usage->providerReported, 'Missing Anthropic usage was reported as provider supplied.');
};

$tests['empty malformed and incomplete provider responses'] = static function () use ($secret): void {
    $request = anthropicRequest('POST', '/messages', $secret, completionPayload());
    foreach ([
        '{not-json',
        '[]',
        '{"type":"message","content":[]}',
        '{"type":"message","content":[{"type":"text","text":""}]}',
        '{"role":"assistant","model":"claude-sonnet-5","content":[{"type":"text","text":"missing type"}]}',
        '{"type":"message","model":"claude-sonnet-5","content":[{"type":"text","text":"missing role"}]}',
        '{"type":"message","role":"assistant","content":[{"type":"text","text":"missing model"}]}',
        '{"type":"message","content":[{"type":"text"}]}',
        '{"type":"message","content":["bad-block"]}',
        '{"type":"message","content":[{"type":"text","text":"partial"}],"stop_reason":"max_tokens"}',
        '{"type":"error","error":{"type":"api_error","message":"unsafe"}}',
        '{"type":"message","content":[{"type":"text","text":"ok"}],"usage":{"input_tokens":"bad"}}',
    ] as $body) {
        $http = new FixtureHttpTransport();
        $http->addResponse($request, new HttpResponse(200, [], $body));
        expectThrows(
            ProviderResponseException::class,
            static fn () => anthropicProvider($http)->complete(new CompletionRequest(
                AnthropicProvider::ID,
                'Confirm the deterministic Anthropic fixture.',
                instructions: 'Return plain text.'
            )),
            'Malformed, empty, or incomplete Anthropic response was accepted.'
        );
    }

    $modelsRequest = anthropicRequest('GET', '/models?limit=1000', $secret);
    foreach ([
        '{not-json',
        '{"data":"not-a-list"}',
        '{"data":[{"id":"model-without-name"}]}',
        '{"data":[{"id":7,"display_name":"Bad"}]}',
        '{"data":[],"has_more":"false"}',
        '{"data":[],"has_more":true}',
    ] as $body) {
        $http = new FixtureHttpTransport();
        $http->addResponse($modelsRequest, new HttpResponse(200, [], $body));
        expectThrows(
            ProviderResponseException::class,
            static fn () => anthropicProvider($http)->discoverModels(),
            'Malformed Anthropic model response was accepted.'
        );
    }
};

$tests['authentication rate limit and HTTP classification'] = static function () use ($secret): void {
    $modelsRequest = anthropicRequest('GET', '/models?limit=1000', $secret);
    $auth = new FixtureHttpTransport();
    $auth->addResponse($modelsRequest, new HttpResponse(401, [], 'x-api-key=' . $secret . ' unsafe-auth-body'));
    $authError = expectThrows(
        ProviderAuthenticationException::class,
        static fn () => anthropicProvider($auth)->discoverModels(),
        'Anthropic authentication failure was not typed.'
    );
    expect(!str_contains($authError->getMessage(), $secret), 'Authentication failure leaked a credential.');
    expect(!str_contains($authError->getMessage(), 'unsafe-auth-body'), 'Authentication failure leaked its body.');

    $rate = new FixtureHttpTransport();
    $rate->addResponse($modelsRequest, new HttpResponse(429, ['Retry-After' => '11'], 'unsafe-rate-body'));
    $rateError = expectThrows(
        ProviderRateLimitException::class,
        static fn () => anthropicProvider($rate)->discoverModels(),
        'Anthropic rate limit was not typed.'
    );
    expectSame(11, $rateError->retryAfterSeconds, 'Anthropic retry guidance was not normalized.');

    $client = new FixtureHttpTransport();
    $client->addResponse($modelsRequest, new HttpResponse(422, [], 'unsafe-client-body'));
    expectThrows(
        ProviderConfigurationException::class,
        static fn () => anthropicProvider($client)->discoverModels(),
        'Anthropic generic 4xx failure was not typed.'
    );

    foreach ([500, 529] as $status) {
        $server = new FixtureHttpTransport();
        $server->addResponse($modelsRequest, new HttpResponse($status, [], 'unsafe-server-body'));
        expectThrows(
            HttpTransportException::class,
            static fn () => anthropicProvider($server)->discoverModels(),
            'Anthropic 5xx failure was not classified as unavailable.'
        );
    }
};

$tests['missing malformed credentials and invalid configuration'] = static function () use ($secret): void {
    setEnvironment(AnthropicProvider::CREDENTIAL_ENVIRONMENT_VARIABLE, null);
    $missingHttp = new FixtureHttpTransport();
    $missing = anthropicProvider($missingHttp)->validateProvider();
    expectSame('credential_missing', $missing->issues[0]->code, 'Missing Anthropic credential category changed.');
    expectSame([], $missingHttp->requests(), 'Missing Anthropic credential reached HTTP.');

    setEnvironment(AnthropicProvider::CREDENTIAL_ENVIRONMENT_VARIABLE, "credential-must-not-leak\n");
    $malformedHttp = new FixtureHttpTransport();
    $malformed = anthropicProvider($malformedHttp)->validateProvider();
    expectSame('credential_invalid', $malformed->issues[0]->code, 'Malformed credential category changed.');
    expect(!str_contains(json_encode($malformed->toArray(), JSON_THROW_ON_ERROR), 'credential-must-not-leak'), 'Malformed credential leaked.');
    expectSame([], $malformedHttp->requests(), 'Malformed Anthropic credential reached HTTP.');

    setEnvironment(AnthropicProvider::CREDENTIAL_ENVIRONMENT_VARIABLE, $secret);
    $invalidHttp = new FixtureHttpTransport();
    $invalid = anthropicProvider($invalidHttp, "bad\nmodel")->validateProvider();
    expectSame('configuration_invalid', $invalid->issues[0]->code, 'Invalid Anthropic model category changed.');
    expectSame([], $invalidHttp->requests(), 'Invalid Anthropic model reached HTTP.');

    expectThrows(
        ProviderConfigurationException::class,
        static fn () => anthropicProvider(
            $invalidHttp,
            AnthropicProvider::DEFAULT_MODEL,
            new EnvironmentCredentialResolver('openai')
        )->complete(new CompletionRequest(AnthropicProvider::ID, 'Wrong resolver.')),
        'Cross-provider Anthropic credential resolver was accepted.'
    );
    foreach ([['unknown' => true], ['max_output_units' => 0], ['max_output_units' => '12']] as $options) {
        expectThrows(
            ProviderConfigurationException::class,
            static fn () => anthropicProvider($invalidHttp)->complete(new CompletionRequest(
                AnthropicProvider::ID,
                'Invalid output option.',
                options: $options
            )),
            'Invalid Anthropic provider-neutral option was accepted.'
        );
    }
};

$tests['transport timeout offline behavior and secret redaction'] = static function () use ($secret): void {
    $request = anthropicRequest('POST', '/messages', $secret, completionPayload());
    $http = new FixtureHttpTransport();
    $http->addFailure($request, new HttpTransportException('Timed out with x-api-key=' . $secret));
    $service = anthropicService(anthropicProvider($http));
    $error = expectThrows(
        ProviderFailureException::class,
        static fn () => $service->complete(new CompletionRequest(
            AnthropicProvider::ID,
            'Confirm the deterministic Anthropic fixture.',
            instructions: 'Return plain text.'
        )),
        'Anthropic timeout did not cross the public failure boundary.'
    );
    expect(!str_contains($error->getMessage(), $secret), 'Anthropic service failure leaked a credential.');
    expect(str_contains($error->getMessage(), SecretRedactor::REDACTED), 'Anthropic failure omitted redaction evidence.');
    expect($error->getPrevious() === null, 'Unsafe Anthropic transport failure was chained.');

    $offline = new FixtureHttpTransport();
    expectThrows(
        HttpTransportException::class,
        static fn () => anthropicProvider($offline)->discoverModels(),
        'Anthropic adapter attempted an implicit network fallback.'
    );
    expectSame(1, count($offline->requests()), 'Unmatched Anthropic fixture did not record one request.');
    expect(!str_contains(json_encode($offline->requests(), JSON_THROW_ON_ERROR), $secret), 'Offline history leaked a credential.');
};

$tests['frozen core and credential-free configuration'] = static function () use ($pluginDirectory): void {
    $hashes = [
        'JarvisServiceInterface.php' => 'f74f88c81cc7e0881f194ff1501f0d4e0eebdd687f3dfca78cd49477198c0e0a',
        'ProviderInterface.php' => '3d550beda0ef1265e614f1476dc4866615e5629378f7f74d003a2de4da2a260a',
        'ProviderRegistryInterface.php' => '250d662a5476a11ff27f79a04cb854eb28c78fd0b76061dcdc43e672bbea6ad5',
        'ProviderIntrospectionServiceInterface.php' => '6a779b9dbce01b38d1ea279c604141592a7f9f2aef54ae4b8028d014c2e55f7b',
        'ProviderValidationInterface.php' => 'de6c7726ee9bfb6b68c608498468937a9b8399ca794aeeb56a0da39df0e16bc7',
        'ModelDiscoveryInterface.php' => '1911cd3936a33c1781aee94b81fb965df440acff7966d8ed5a03cad3c6e628a1',
    ];
    foreach ($hashes as $file => $expected) {
        expectSame(
            $expected,
            hash_file('sha256', $pluginDirectory . '/classes/Contracts/' . $file),
            'A frozen Jarvis public interface changed: ' . $file
        );
    }

    $contractFiles = glob($pluginDirectory . '/classes/Contracts/*.php');
    expect(is_array($contractFiles), 'Could not enumerate shared Jarvis contracts.');
    $source = '';
    foreach ($contractFiles as $file) {
        $source .= strtolower(file_get_contents($file) ?: '');
    }
    foreach (['anthropic', "'/messages'", '"/messages"', 'max_tokens', 'stop_reason', 'display_name', 'anthropic-version', 'x-api-key'] as $term) {
        expect(!str_contains($source, $term), 'Anthropic vocabulary escaped into a shared contract: ' . $term);
    }

    $config = file_get_contents($pluginDirectory . '/grav-jarvis.yaml') ?: '';
    $blueprint = file_get_contents($pluginDirectory . '/blueprints.yaml') ?: '';
    expect(!preg_match('/^[[:space:]]*(?:api[_-]?key|secret|token|credential)[[:space:]]*:/mi', $config), 'A credential field entered plugin YAML.');
    expect(!preg_match('/^[[:space:]]*(?:api[_-]?key|secret|token|credential)[[:space:]]*:/mi', $blueprint), 'A credential field entered the Admin blueprint.');
};

$passed = 0;
try {
    foreach ($tests as $name => $test) {
        setEnvironment(AnthropicProvider::CREDENTIAL_ENVIRONMENT_VARIABLE, $secret);
        $test();
        ++$passed;
        fwrite(STDOUT, 'PASS: ' . $name . "\n");
    }
} catch (Throwable $error) {
    $safe = SecretRedactor::fromEnvironment()->redact($error->getMessage());
    fwrite(STDERR, 'FAIL: ' . $error::class . ': ' . $safe . "\n");
    setEnvironment(AnthropicProvider::CREDENTIAL_ENVIRONMENT_VARIABLE, null);
    exit(1);
}

setEnvironment(AnthropicProvider::CREDENTIAL_ENVIRONMENT_VARIABLE, null);
fwrite(STDOUT, 'Jarvis Anthropic provider passed (' . $passed . " checks).\n");
