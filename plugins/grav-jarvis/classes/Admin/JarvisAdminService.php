<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Admin;

use Grav\Plugin\GravJarvis\Contracts\CompletionRequest;
use Grav\Plugin\GravJarvis\Contracts\JarvisServiceInterface;
use Grav\Plugin\GravJarvis\Contracts\ProviderIntrospectionServiceInterface;
use Grav\Plugin\GravJarvis\Contracts\ValidationIssue;
use InvalidArgumentException;
use Throwable;

final class JarvisAdminService
{
    public const REVIEWABLE_PROPOSAL_BYTES = 65536;

    public function __construct(
        private readonly JarvisServiceInterface $jarvis,
        private readonly BoundedContextBuilder $contexts,
        private readonly ActionPromptLibrary $prompts,
        private readonly TransientProposalStore $proposals
    ) {
    }

    /** @return array<string, mixed> */
    public function bootstrap(bool $canApprove): array
    {
        $providers = [];
        foreach ($this->jarvis->providerIds() as $providerId) {
            try {
                $capabilities = $this->jarvis->capabilities($providerId);
            } catch (Throwable) {
                $capabilities = [];
            }
            $providers[] = [
                'id' => $providerId,
                'capabilities' => $capabilities,
            ];
        }
        return [
            'available' => true,
            'providers' => $providers,
            'actions' => $this->prompts->actions(),
            'prompt_version' => ActionPromptLibrary::VERSION,
            'can_approve' => $canApprove,
            'selection_aware' => false,
            'limits' => [
                'content_bytes' => BoundedContextBuilder::CONTENT_BYTES,
                'frontmatter_bytes' => BoundedContextBuilder::FRONTMATTER_BYTES,
                'media_bytes' => BoundedContextBuilder::MEDIA_BYTES,
                'media_items' => BoundedContextBuilder::MEDIA_ITEMS,
                'proposal_bytes' => self::REVIEWABLE_PROPOSAL_BYTES,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function validation(string $providerId): array
    {
        if (!$this->jarvis instanceof ProviderIntrospectionServiceInterface) {
            return $this->unavailableValidation($providerId, 'validation_unavailable', false);
        }
        try {
            $result = $this->jarvis->validateProvider($providerId);
            $issues = array_map(fn (ValidationIssue $issue): array => [
                'code' => $issue->code,
                'message' => $this->issueMessage($issue->code),
                'severity' => $issue->severity,
                'retryable' => $issue->retryable,
            ], $result->issues);
            return [
                'provider_id' => $providerId,
                'usable' => $result->usable,
                'state' => $this->validationState($result->usable, $issues),
                'issues' => $issues,
                'capabilities' => $result->capabilities,
            ];
        } catch (Throwable) {
            return $this->unavailableValidation($providerId, 'provider_unavailable', true);
        }
    }

    /** @return array<string, mixed> */
    public function models(string $providerId): array
    {
        if (!$this->jarvis instanceof ProviderIntrospectionServiceInterface) {
            return [
                'provider_id' => $providerId,
                'discovery_supported' => false,
                'models' => [],
                'message' => 'This provider uses its configured default model.',
            ];
        }
        try {
            if (!in_array('model-discovery', $this->jarvis->capabilities($providerId), true)) {
                return [
                    'provider_id' => $providerId,
                    'discovery_supported' => false,
                    'models' => [],
                    'message' => 'This provider uses its configured default model.',
                ];
            }
            return [
                ...$this->jarvis->discoverModels($providerId)->toArray(),
                'discovery_supported' => true,
                'message' => '',
            ];
        } catch (Throwable) {
            return [
                'provider_id' => $providerId,
                'discovery_supported' => true,
                'models' => [],
                'message' => 'Models could not be loaded. The configured provider default remains available.',
            ];
        }
    }

    /** @return array<string, mixed> */
    public function complete(string $providerId, ?string $model, string $prompt): array
    {
        $definition = $this->prompts->generalPrompt($prompt);
        $result = $this->jarvis->complete(new CompletionRequest(
            providerId: $providerId,
            input: $definition['input'],
            model: $this->model($model),
            instructions: $definition['instructions'],
            metadata: ['prompt_version' => ActionPromptLibrary::VERSION, 'surface' => 'admin']
        ));
        return [
            'provider_id' => $result->providerId,
            'model' => $result->model,
            'response' => $result->output,
            'usage' => $result->usage->toArray(),
        ];
    }

    /**
     * @param array<string, mixed> $page
     * @return array<string, mixed>
     */
    public function pageContext(array $page, string $content = ''): array
    {
        return $this->contexts->build($page, $content);
    }

    /**
     * @param array<string, mixed> $page
     * @return array<string, mixed>
     */
    public function propose(
        string $actor,
        string $providerId,
        ?string $model,
        string $action,
        ?string $customInstruction,
        array $page,
        string $content,
        ?string $replacesProposalId = null
    ): array {
        $context = $this->contexts->build($page, $content);
        $definition = $this->prompts->pagePrompt($action, $context, $customInstruction);
        $result = $this->jarvis->complete(new CompletionRequest(
            providerId: $providerId,
            input: $definition['input'],
            model: $this->model($model),
            instructions: $definition['instructions'],
            metadata: [
                'action' => $action,
                'prompt_version' => ActionPromptLibrary::VERSION,
                'surface' => 'page-editor',
            ]
        ));
        $proposalBytes = strlen($result->output);
        $acceptAllowed = $context['accept_allowed'] === true
            && $proposalBytes <= self::REVIEWABLE_PROPOSAL_BYTES;
        $receipt = ['id' => null, 'expires_at' => null];
        if ($replacesProposalId !== null) {
            $this->proposals->revoke($replacesProposalId, $actor, (string) $context['route']);
        }
        if ($acceptAllowed) {
            $receipt = $this->proposals->issue(
                $actor,
                (string) $context['route'],
                (string) $context['source_hash'],
                hash('sha256', $result->output)
            );
        }

        return [
            'proposal_id' => $receipt['id'],
            'expires_at' => $receipt['expires_at'],
            'action' => $action,
            'provider_id' => $result->providerId,
            'model' => $result->model,
            'proposed_content' => $result->output,
            'source_hash' => $context['source_hash'],
            'usage' => $result->usage->toArray(),
            'context' => [
                'route' => $context['route'],
                'title' => $context['title'],
                'template' => $context['template'],
                'language' => $context['language'],
                'content_bytes' => $context['content_bytes'],
                'included_content_bytes' => $context['included_content_bytes'],
                'frontmatter_fields' => array_keys($context['frontmatter']),
                'media_items' => count($context['media']),
                'proposal_bytes' => $proposalBytes,
                'proposal_limit_bytes' => self::REVIEWABLE_PROPOSAL_BYTES,
                'truncated' => $context['truncated'],
                'accept_allowed' => $acceptAllowed,
            ],
        ];
    }

    /** @return array{accepted: true, content: string, source_hash: string} */
    public function accept(
        string $actor,
        string $proposalId,
        string $route,
        string $currentContent,
        string $proposedContent
    ): array {
        if (strlen($currentContent) > 2097152 || strlen($proposedContent) > 2097152) {
            throw new InvalidArgumentException('The proposal content exceeds the Admin2 safety limit.');
        }
        $sourceHash = hash('sha256', $currentContent);
        $this->proposals->consume(
            $proposalId,
            $actor,
            $route,
            $sourceHash,
            hash('sha256', $proposedContent)
        );
        return ['accepted' => true, 'content' => $proposedContent, 'source_hash' => $sourceHash];
    }

    public function discard(string $actor, string $proposalId, string $route): void
    {
        $this->proposals->revoke($proposalId, $actor, $route);
    }

    private function model(?string $model): ?string
    {
        if ($model === null) {
            return null;
        }
        $model = $model === null ? null : trim($model);
        if ($model === '') {
            return null;
        }
        if (strlen($model) > 256 || preg_match('/[\x00-\x1F\x7F]/', $model) === 1) {
            throw new InvalidArgumentException('The selected model identifier is invalid.');
        }
        return $model;
    }

    /** @param list<array<string, mixed>> $issues */
    private function validationState(bool $usable, array $issues): string
    {
        if ($usable) {
            return 'usable';
        }
        $code = (string) ($issues[0]['code'] ?? '');
        if (in_array($code, ['credential_missing', 'credential_invalid', 'configuration_invalid'], true)) {
            return 'misconfigured';
        }
        return (bool) ($issues[0]['retryable'] ?? false) ? 'retryable' : 'unavailable';
    }

    /** @return array<string, mixed> */
    private function unavailableValidation(string $providerId, string $code, bool $retryable): array
    {
        return [
            'provider_id' => $providerId,
            'usable' => false,
            'state' => $retryable ? 'retryable' : 'unavailable',
            'issues' => [[
                'code' => $code,
                'message' => $this->issueMessage($code),
                'severity' => ValidationIssue::ERROR,
                'retryable' => $retryable,
            ]],
            'capabilities' => [],
        ];
    }

    private function issueMessage(string $code): string
    {
        return match ($code) {
            'credential_missing' => 'This provider needs a credential in the server environment.',
            'credential_invalid' => 'The provider credential in the server environment is malformed.',
            'authentication_failed' => 'The provider rejected the configured credential.',
            'configuration_invalid' => 'This provider configuration needs attention.',
            'rate_limited' => 'The provider is temporarily rate limited. Try again shortly.',
            'transport_unavailable' => 'The provider cannot be reached right now.',
            'response_invalid' => 'The provider returned an unsupported response.',
            'remote_validation_limited' => 'Remote validation is unavailable; the configured default model can still be used.',
            'validation_unavailable' => 'This provider does not support validation.',
            default => 'The provider is unavailable right now.',
        };
    }
}
