<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Testing;

use Grav\Plugin\GravJarvis\Contracts\CompletionRequest;
use Grav\Plugin\GravJarvis\Contracts\CompletionResult;
use Grav\Plugin\GravJarvis\Contracts\Exception\ProviderException;
use Grav\Plugin\GravJarvis\Contracts\ProviderInterface;
use Grav\Plugin\GravJarvis\Contracts\Usage;

final class DeterministicFakeProvider implements ProviderInterface
{
    public function __construct(private readonly string $providerId = 'fake')
    {
        if (!CompletionRequest::validIdentifier($providerId)) {
            throw new \InvalidArgumentException('Fake provider identifier must be a lowercase stable slug.');
        }
    }

    public function id(): string
    {
        return $this->providerId;
    }

    public function capabilities(): array
    {
        return ['deterministic-test', 'text-completion'];
    }

    public function complete(CompletionRequest $request): CompletionResult
    {
        if ($request->providerId !== $this->providerId) {
            throw new ProviderException('The deterministic provider received a request for another provider.');
        }

        $digest = hash('sha256', $request->canonicalPayload());
        $output = 'jarvis-fake:' . substr($digest, 0, 24);
        $inputUnits = strlen($request->input) + strlen($request->instructions ?? '');

        return new CompletionResult(
            providerId: $this->providerId,
            model: $request->model ?? 'deterministic-v1',
            output: $output,
            usage: new Usage(
                inputUnits: $inputUnits,
                outputUnits: strlen($output),
                unit: 'characters',
                providerReported: false
            ),
            metadata: [
                'fixture' => 'deterministic-v1',
                'request_digest' => $digest,
            ]
        );
    }
}
