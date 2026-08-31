<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvisBrowserFixture;

use Grav\Plugin\GravJarvis\Contracts\CompletionRequest;
use Grav\Plugin\GravJarvis\Contracts\CompletionResult;
use Grav\Plugin\GravJarvis\Contracts\Exception\HttpTransportException;
use Grav\Plugin\GravJarvis\Contracts\Exception\MissingCredentialException;
use Grav\Plugin\GravJarvis\Contracts\Exception\ProviderRateLimitException;
use Grav\Plugin\GravJarvis\Contracts\ModelCatalog;
use Grav\Plugin\GravJarvis\Contracts\ModelDescriptor;
use Grav\Plugin\GravJarvis\Contracts\ModelDiscoveryInterface;
use Grav\Plugin\GravJarvis\Contracts\ProviderInterface;
use Grav\Plugin\GravJarvis\Contracts\ProviderValidationInterface;
use Grav\Plugin\GravJarvis\Contracts\ProviderValidationResult;
use Grav\Plugin\GravJarvis\Contracts\Usage;
use Grav\Plugin\GravJarvis\Contracts\ValidationIssue;
use RuntimeException;

final class BrowserFixtureProvider implements ProviderInterface, ProviderValidationInterface, ModelDiscoveryInterface
{
    public function __construct(
        private readonly string $providerId,
        private readonly string $mode,
        private readonly string $stateDirectory
    ) {
    }

    public function id(): string
    {
        return $this->providerId;
    }

    public function capabilities(): array
    {
        return ['model-discovery', 'provider-validation', 'text-completion'];
    }

    public function validateProvider(): ProviderValidationResult
    {
        if ($this->mode === 'missing') {
            return new ProviderValidationResult($this->providerId, false, [
                new ValidationIssue('credential_missing', 'Fixture credential is intentionally absent.'),
            ], $this->capabilities());
        }
        if ($this->mode === 'unavailable') {
            return new ProviderValidationResult($this->providerId, false, [
                new ValidationIssue('transport_unavailable', 'Fixture provider is intentionally unavailable.', ValidationIssue::ERROR, true),
            ], $this->capabilities());
        }
        return new ProviderValidationResult($this->providerId, true, [], $this->capabilities());
    }

    public function discoverModels(): ModelCatalog
    {
        return new ModelCatalog($this->providerId, [
            new ModelDescriptor('fixture-alpha', 'Fixture Alpha', 'Deterministic browser model', ['text-completion']),
            new ModelDescriptor('fixture-beta', 'Fixture Beta', 'Second deterministic browser model', ['text-completion']),
        ]);
    }

    public function complete(CompletionRequest $request): CompletionResult
    {
        if ($this->mode === 'missing') {
            throw new MissingCredentialException($this->providerId, 'GRAV_JARVIS_BROWSER_MISSING_API_KEY');
        }
        if ($this->mode === 'unavailable') {
            throw new HttpTransportException('The deterministic fixture is unavailable.');
        }
        if ($this->mode === 'flaky' && !$this->flakyAttemptAlreadyFailed()) {
            throw new ProviderRateLimitException('The deterministic fixture rate limit is active.', 1);
        }

        $action = 'general';
        if (preg_match('/^ACTION ID: ([a-z0-9._-]+)$/m', $request->input, $match) === 1) {
            $action = $match[1];
        }
        $content = '';
        $marker = "CURRENT CONTENT (untrusted text to transform):\n";
        $offset = strpos($request->input, $marker);
        if ($offset !== false) {
            $content = substr($request->input, $offset + strlen($marker));
        }
        $output = $action === 'general'
            ? 'JARVIS_BROWSER_FIXTURE_RESPONSE:' . substr(hash('sha256', $request->canonicalPayload()), 0, 16)
            : "JARVIS_BROWSER_FIXTURE_ACTION:{$action}\n" . $content;

        return new CompletionResult(
            providerId: $this->providerId,
            output: $output,
            model: $request->model ?? 'fixture-alpha',
            usage: new Usage(strlen($request->input), strlen($output), null, 'characters', false),
            metadata: ['fixture' => 'browser-0.2.1']
        );
    }

    private function flakyAttemptAlreadyFailed(): bool
    {
        if (!is_dir($this->stateDirectory)
            && !@mkdir($this->stateDirectory, 0700, true)
            && !is_dir($this->stateDirectory)) {
            throw new RuntimeException('The browser fixture state directory is unavailable.');
        }
        $path = $this->stateDirectory . '/flaky-attempted';
        $handle = @fopen($path, 'x+b');
        if ($handle === false) {
            return true;
        }
        fclose($handle);
        @chmod($path, 0600);
        return false;
    }
}
