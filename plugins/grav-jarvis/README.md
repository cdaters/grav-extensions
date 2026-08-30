# Jarvis

Jarvis 0.1.0 is the provider-neutral AI service-contract foundation for Grav 2.
It gives plugins one optional PHP seam for registering and calling model
providers without exposing provider credentials or coupling consumers to a
specific vendor API.

This release contains no live AI provider, Admin2 assistant, content mutation,
background worker, MCP workflow, or Grav Commander integration. Enabling it
does not make a network request.

## Requirements

- Grav 2.0 or newer
- PHP 8.3 or newer, following Grav 2's supported runtime

## Installation

Copy the `grav-jarvis` directory to:

```text
user/plugins/grav-jarvis
```

Clear Grav's cache after installing or updating:

```bash
bin/grav clearcache
```

The plugin is enabled by default and registers its service as
`$grav['gravJarvis']`. It registers no provider by default, so a completion
request fails with a typed provider-not-found exception until a companion
plugin registers one.

## Public contracts

Public interfaces and immutable value objects live under:

```text
Grav\Plugin\GravJarvis\Contracts
```

The 0.1.0 surface includes:

- `JarvisServiceInterface`
- `ProviderInterface`
- `ProviderRegistryInterface`
- `CompletionRequest`
- `CompletionResult`
- `Usage`
- typed Jarvis, registry, and provider exceptions

`CompletionRequest` uses provider-neutral input, instructions, model,
options, and metadata fields. It rejects credentials placed in options or
metadata. `Usage` carries a provider-declared unit such as `characters`,
`tokens`, or `items`; the contract does not assume tokens or any vendor's
response schema.

## Register a provider

A companion plugin subscribes to `onJarvisProviderRegister`, reads the public
registry from the event, and registers an implementation:

```php
use Grav\Plugin\GravJarvis\Contracts\ProviderRegistryInterface;
use RocketTheme\Toolbox\Event\Event;

public function onJarvisProviderRegister(Event $event): void
{
    $registry = $event['registry'] ?? null;
    if ($registry instanceof ProviderRegistryInterface) {
        $registry->register(new MyProvider());
    }
}
```

Provider identifiers are lowercase stable slugs. Duplicate identifiers are
rejected rather than silently replacing an existing provider.

## Optional consumer pattern

Consumers must remain useful when Jarvis is absent, disabled, unavailable, or
when a provider fails. Resolve the optional service inside a guard and handle
typed Jarvis failures at the feature boundary:

```php
use Grav\Plugin\GravJarvis\Contracts\CompletionRequest;
use Grav\Plugin\GravJarvis\Contracts\JarvisServiceInterface;
use Grav\Plugin\GravJarvis\Contracts\Exception\JarvisException;

$jarvis = null;

if (interface_exists(JarvisServiceInterface::class)
    && isset($grav['gravJarvis'])) {
    try {
        $candidate = $grav['gravJarvis'];
        $jarvis = $candidate instanceof JarvisServiceInterface
            ? $candidate
            : null;
    } catch (\Throwable) {
        $jarvis = null;
    }
}

if ($jarvis) {
    try {
        $result = $jarvis->complete(new CompletionRequest(
            providerId: 'example',
            input: 'Summarize this allowed content.'
        ));
    } catch (JarvisException) {
        $result = null; // Preserve the consumer's non-AI fallback.
    }
}
```

Do not read Jarvis configuration, provider implementations, or credentials
from a consumer. Do not make Jarvis a hard dependency for a plugin's core
behavior.

## Failure and secret boundary

Provider exceptions are converted to `ProviderFailureException` before they
cross the public service boundary. Messages are redacted and the original
provider exception is not chained, preventing its message from being exposed
through normal exception inspection. Successful provider output and metadata
also pass through the same redactor before a `CompletionResult` is returned.

Jarvis discovers non-empty environment values whose names begin with
`GRAV_JARVIS_` and treats them as secrets for redaction. It also redacts common
authorization, API-key, token, credential, password, and secret assignments.
Environment values are held only in memory; no secret is written to plugin
configuration, request metadata, logs, caches, fixtures, or packages.

Provider credentials must be resolved inside a provider from server-side
environment variables or an external credential reference. They are never a
`CompletionRequest` option.

## Deterministic test provider

`Grav\Plugin\GravJarvis\Testing\DeterministicFakeProvider` exists only for
contract tests. It hashes the canonical request and returns a stable response
and character-unit usage. Jarvis never registers it during normal plugin boot.

Run the contract with host PHP or the repository's DDEV fixture:

```bash
./scripts/test-grav-jarvis-contract.sh
```

## Deliberately deferred

- OpenAI, Anthropic, OpenAI-compatible, Gemini, and OpenRouter adapters
- provider model discovery and validation commands
- streaming and CLI chat
- prompt libraries and page/frontmatter/media context
- Admin2 UI and proposal/diff/approval workflows
- retries, caching, cost reports, chunking, and background jobs
- batch work, REST/MCP workflows, and suite integrations

See the repository's `docs/planned/grav-jarvis.md` for the staged roadmap.

## License

MIT. See `LICENSE`.
