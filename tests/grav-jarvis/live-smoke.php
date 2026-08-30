<?php

declare(strict_types=1);

namespace GravJarvisLiveSmoke;

use Grav\Plugin\GravJarvis\Contracts\CompletionRequest;
use Grav\Plugin\GravJarvis\Contracts\CompletionResult;
use Grav\Plugin\GravJarvis\Contracts\ProviderIntrospectionServiceInterface;
use Grav\Plugin\GravJarvis\Provider\Anthropic\AnthropicProvider;
use Grav\Plugin\GravJarvis\Provider\OpenAI\OpenAIProvider;
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
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = $pluginDirectory . '/classes/'
        . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

if (getenv('GRAV_JARVIS_LIVE_SMOKE') !== '1') {
    fwrite(STDOUT, "SKIP: Jarvis live smoke is not explicitly enabled.\n");
    exit(0);
}

$providerId = getenv('JARVIS_LIVE_PROVIDER');
$providerId = is_string($providerId) && $providerId !== '' ? $providerId : OpenAIProvider::ID;
$definitions = [
    OpenAIProvider::ID => [
        'credential' => OpenAIProvider::CREDENTIAL_ENVIRONMENT_VARIABLE,
        'factory' => static fn () => OpenAIProvider::createProduction(),
    ],
    AnthropicProvider::ID => [
        'credential' => AnthropicProvider::CREDENTIAL_ENVIRONMENT_VARIABLE,
        'factory' => static fn () => AnthropicProvider::createProduction(),
    ],
];

if (!isset($definitions[$providerId])) {
    fwrite(STDERR, "FAIL: unsupported live provider selection.\n");
    exit(1);
}

$credentialEnvironmentVariable = $definitions[$providerId]['credential'];
$credential = getenv($credentialEnvironmentVariable);
if (!is_string($credential) || $credential === '') {
    fwrite(STDOUT, 'SKIP: Jarvis live smoke has no ' . $credentialEnvironmentVariable . ".\n");
    exit(0);
}

$redactor = SecretRedactor::fromEnvironment();
try {
    $provider = ($definitions[$providerId]['factory'])();
    $registry = new ProviderRegistry();
    $registry->register($provider);
    $service = new JarvisService($registry, $redactor);
    if (!$service instanceof ProviderIntrospectionServiceInterface) {
        throw new RuntimeException('The Jarvis service lost provider introspection.');
    }

    $validation = $service->validateProvider($providerId);
    if (!$validation->usable) {
        $code = $validation->issues[0]->code ?? 'unknown';
        throw new RuntimeException('Provider validation failed with safe issue code ' . $code . '.');
    }
    $catalog = $service->discoverModels($providerId);
    $result = $service->complete(new CompletionRequest(
        providerId: $providerId,
        input: 'Reply with exactly JARVIS ONLINE.',
        instructions: 'Return only the requested short readiness acknowledgement.',
        options: ['max_output_units' => 256]
    ));
    if (!$result instanceof CompletionResult || trim($result->output) === '') {
        throw new RuntimeException('The live provider returned no normalized text output.');
    }

    $model = $result->model ?? 'unknown';
    $exact = trim($result->output) === 'JARVIS ONLINE' ? 'yes' : 'no';
    $usage = $result->usage->totalUnits === null ? 'unknown' : (string) $result->usage->totalUnits;
    fwrite(
        STDOUT,
        'PASS: Jarvis live smoke provider=' . $providerId
        . ' model=' . $model
        . ' catalog_models=' . count($catalog->models)
        . ' usage_units=' . $usage
        . ' nonempty_output=yes exact_ack=' . $exact . "\n"
    );
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error::class . ': ' . $redactor->redact($error->getMessage()) . "\n");
    exit(1);
}
