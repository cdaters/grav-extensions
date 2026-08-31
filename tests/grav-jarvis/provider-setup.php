<?php

declare(strict_types=1);

namespace GravJarvisProviderSetupContract;

use Grav\Plugin\GravJarvis\Admin\ActionPromptLibrary;
use Grav\Plugin\GravJarvis\Admin\BoundedContextBuilder;
use Grav\Plugin\GravJarvis\Admin\JarvisAdminService;
use Grav\Plugin\GravJarvis\Admin\ProviderSetupCatalog;
use Grav\Plugin\GravJarvis\Admin\TransientProposalStore;
use Grav\Plugin\GravJarvis\Contracts\CompletionRequest;
use Grav\Plugin\GravJarvis\Contracts\CompletionResult;
use Grav\Plugin\GravJarvis\Contracts\ModelCatalog;
use Grav\Plugin\GravJarvis\Contracts\ModelDescriptor;
use Grav\Plugin\GravJarvis\Contracts\ModelDiscoveryInterface;
use Grav\Plugin\GravJarvis\Contracts\ProviderInterface;
use Grav\Plugin\GravJarvis\Contracts\ProviderValidationInterface;
use Grav\Plugin\GravJarvis\Contracts\ProviderValidationResult;
use Grav\Plugin\GravJarvis\Provider\ProviderRegistry;
use Grav\Plugin\GravJarvis\Security\SecretRedactor;
use Grav\Plugin\GravJarvis\Service\JarvisService;
use RuntimeException;
use Throwable;

$pluginDirectory = getenv('GRAV_JARVIS_PLUGIN_DIR') ?: dirname(__DIR__, 2) . '/plugins/grav-jarvis';
$pluginDirectory = rtrim((string) $pluginDirectory, '/');
spl_autoload_register(static function (string $class) use ($pluginDirectory): void {
    $prefix = 'Grav\\Plugin\\GravJarvis\\';
    if (str_starts_with($class, $prefix)) {
        $file = $pluginDirectory . '/classes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

final class SetupProvider implements ProviderInterface, ProviderValidationInterface, ModelDiscoveryInterface
{
    /** @param list<ModelDescriptor> $models */
    public function __construct(
        private readonly string $providerId,
        private readonly array $models = [],
        private readonly bool $discoveryFails = false
    ) {
    }

    public function id(): string { return $this->providerId; }
    public function capabilities(): array { return ['model-discovery', 'provider-validation', 'text-completion']; }
    public function validateProvider(): ProviderValidationResult
    {
        return new ProviderValidationResult($this->providerId, true, capabilities: $this->capabilities());
    }
    public function discoverModels(): ModelCatalog
    {
        if ($this->discoveryFails) {
            throw new RuntimeException('private upstream discovery detail');
        }
        return new ModelCatalog($this->providerId, $this->models);
    }
    public function complete(CompletionRequest $request): CompletionResult
    {
        return new CompletionResult($this->providerId, 'ok', $request->model ?? 'fixture-model');
    }
}

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' expected=' . json_encode($expected) . ' actual=' . json_encode($actual));
    }
}

function indexed(array $setups): array
{
    $result = [];
    foreach ($setups as $setup) {
        $result[(string) $setup['id']] = $setup;
    }
    return $result;
}

$secret = 'sk-jarvis-provider-setup-secret-value';
putenv('GRAV_JARVIS_OPENAI_API_KEY=' . $secret);
putenv('GRAV_JARVIS_ANTHROPIC_API_KEY');
putenv('GRAV_JARVIS_COMPATIBLE_GATEWAY_API_KEY=compatible-secret-value');

try {
    $registry = new ProviderRegistry();
    $registry->register(new SetupProvider('openai', [new ModelDescriptor('live-model', 'Live model')]));
    $registry->register(new SetupProvider('compatible-gateway', [], true));
    $registry->register(new SetupProvider('extension-provider'));
    $service = new JarvisService($registry, new SecretRedactor([$secret, 'compatible-secret-value']));
    $configuration = [
        'admin' => ['default_provider' => 'compatible-gateway'],
        'providers' => [
            'openai' => ['enabled' => true, 'default_model' => 'configured-model'],
            'anthropic' => ['enabled' => false, 'default_model' => 'claude-configured'],
            'openai_compatible' => [
                'enabled' => true,
                'instances' => [[
                    'id' => 'compatible-gateway',
                    'base_uri' => 'https://gateway.example/v1',
                    'credential_environment_variable' => 'GRAV_JARVIS_COMPATIBLE_GATEWAY_API_KEY',
                    'default_model' => 'gateway-model',
                    'model_discovery' => true,
                ]],
            ],
        ],
    ];

    $catalog = new ProviderSetupCatalog($configuration, $service);
    $setups = indexed($catalog->providers());
    same('compatible-gateway', $catalog->defaultProvider(), 'Preferred registered provider was not honored.');
    same('configured', $setups['openai']['credential_status'], 'Configured OpenAI credential was not classified.');
    same('GRAV_JARVIS_OPENAI_API_KEY', $setups['openai']['credential_environment_variable'], 'OpenAI environment name changed.');
    same('missing', $setups['anthropic']['credential_status'], 'Missing Anthropic credential was not classified.');
    same(false, $setups['anthropic']['enabled'], 'Disabled Anthropic provider disappeared or changed state.');
    same('https://gateway.example/v1', $setups['compatible-gateway']['base_uri'], 'Compatible base URI was not reported.');
    same('managed', $setups['extension-provider']['credential_status'], 'Extension provider ownership was overclaimed.');
    $encoded = json_encode($setups, JSON_THROW_ON_ERROR);
    expect(!str_contains($encoded, $secret) && !str_contains($encoded, 'compatible-secret-value'), 'Credential value entered setup metadata.');
    expect(str_contains($encoded, 'ChatGPT login or subscription is not an API credential'), 'OpenAI product distinction is missing.');
    echo "PASS: provider setup metadata reports configuration without credential values\n";

    $temporary = sys_get_temp_dir() . '/grav-jarvis-provider-setup-' . bin2hex(random_bytes(6));
    $admin = new JarvisAdminService(
        $service,
        new BoundedContextBuilder(new SecretRedactor([$secret])),
        new ActionPromptLibrary(),
        new TransientProposalStore($temporary),
        'setup-test',
        array_values($setups),
        $catalog->defaultProvider()
    );
    $bootstrap = $admin->bootstrap(true, true);
    same('compatible-gateway', $bootstrap['default_provider'], 'Bootstrap lost the preferred provider.');
    same(true, $bootstrap['can_manage'], 'Bootstrap lost provider-management authority.');
    expect(count($bootstrap['provider_setups']) >= 4, 'Bootstrap omitted provider setup records.');
    same('configured-model', indexed($bootstrap['providers'])['openai']['default_model'], 'Bootstrap omitted the configured model.');
    echo "PASS: Admin bootstrap exposes preferred provider and safe setup state\n";

    $models = $admin->models('openai');
    same('configured-model', $models['configured_default_model'], 'Model discovery lost the configured default.');
    same(false, $models['configured_default_available'], 'Unavailable configured default was not flagged.');
    expect(str_contains($models['message'], 'not returned'), 'Unavailable default guidance is missing.');

    $failed = $admin->models('compatible-gateway');
    same('gateway-model', $failed['configured_default_model'], 'Discovery failure lost the configured model.');
    same(null, $failed['configured_default_available'], 'Discovery failure falsely claimed model availability.');
    same([], $failed['models'], 'Discovery failure returned stale upstream data.');
    expect(!str_contains(json_encode($failed), 'private upstream'), 'Raw discovery failure crossed the Admin boundary.');
    echo "PASS: configured models survive discovery failure and unavailable defaults are explicit\n";

    putenv('GRAV_JARVIS_OPENAI_API_KEY= malformed');
    $invalid = indexed((new ProviderSetupCatalog($configuration, $service))->providers());
    same('invalid', $invalid['openai']['credential_status'], 'Malformed local credential was not classified invalid.');
    echo "PASS: local credential state distinguishes missing, configured, and invalid\n";

    $blueprint = file_get_contents($pluginDirectory . '/blueprints.yaml') ?: '';
    expect(str_contains($blueprint, 'ChatGPT Plus, Pro, Business'), 'Admin2 blueprint lacks the ChatGPT/API distinction.');
    expect(str_contains($blueprint, 'GRAV_JARVIS_OPENAI_API_KEY'), 'Admin2 blueprint lacks the exact OpenAI environment name.');
    expect(str_contains($blueprint, 'providers.openai_compatible.instances'), 'Compatible instances are not manageable in Admin2.');
    expect(str_contains($blueprint, 'pricing.models'), 'Pricing metadata is not manageable in Admin2.');
    expect(!preg_match('/^[[:space:]]*(?:api[_-]?key|secret|token)[[:space:]]*:/mi', $blueprint), 'A credential value field entered the Admin2 blueprint.');

    echo "PASS: Admin blueprint exposes only non-secret provider and pricing controls\n";
} finally {
    putenv('GRAV_JARVIS_OPENAI_API_KEY');
    putenv('GRAV_JARVIS_ANTHROPIC_API_KEY');
    putenv('GRAV_JARVIS_COMPATIBLE_GATEWAY_API_KEY');
}

echo "Jarvis provider setup contract passed (5 checks).\n";
