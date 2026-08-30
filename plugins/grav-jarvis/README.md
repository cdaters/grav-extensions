# Jarvis

Jarvis 0.1.2 is the provider-neutral AI service foundation for Grav 2. It gives
plugins one optional PHP seam for registering, validating, inspecting, and
calling model providers without exposing provider credentials or coupling
consumers to a vendor response shape. This release adds a bounded production
HTTP transport and the first live adapter, for OpenAI's official API.

This release contains no Admin2 assistant, content mutation, background worker,
MCP workflow, or Grav Commander integration. Enabling it registers the OpenAI
adapter but does not resolve a credential or make a network request.

## Requirements

- Grav 2.0 or newer
- PHP 8.3 or newer, following Grav 2's supported runtime
- PHP cURL extension for production provider requests

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
`$grav['gravJarvis']`. The official `openai` provider is registered by default.
Without `GRAV_JARVIS_OPENAI_API_KEY` in the server process environment,
validation reports a missing credential and generation fails through Jarvis's
typed, redacted failure boundary. The provider may be disabled, and its
non-secret default model changed, in plugin configuration.

## Public contracts

Public interfaces and immutable value objects live under:

```text
Grav\Plugin\GravJarvis\Contracts
```

The unchanged 0.1.0 surface includes:

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

Jarvis 0.1.1 adds optional contracts rather than changing those interfaces:

- `ProviderValidationInterface`
- `ModelDiscoveryInterface`
- `ProviderIntrospectionServiceInterface`
- `ProviderValidationResult` and `ValidationIssue`
- `ModelCatalog` and `ModelDescriptor`
- `CredentialResolverInterface` and `CredentialValueInterface`
- `HttpTransportInterface`, `HttpRequest`, and `HttpResponse`

Providers opt into validation and discovery independently. Jarvis's concrete
service implements `ProviderIntrospectionServiceInterface`, while consumers
compiled against `JarvisServiceInterface` retain the exact 0.1.0 methods.

## Validation and model discovery

Validation answers whether configured provider access is currently usable
without issuing a content-generation request. Expected configuration,
credential, authentication, rate-limit, transport, and response problems are
represented by provider-neutral issue codes, severity, and retryability.

Model discovery returns a sorted `ModelCatalog` of `ModelDescriptor` values.
Shared model fields are limited to an opaque identifier, label, optional
description, availability, and capability slugs. Raw provider response objects
and provider-specific fields never cross the shared contract.

## Official OpenAI provider

The `openai` adapter uses only OpenAI's fixed official base URI. Provider
validation and model discovery call `GET /v1/models`; synchronous text
generation calls `POST /v1/responses`. OpenAI request fields, response items,
error statuses, and token-usage fields remain inside
`Grav\Plugin\GravJarvis\Provider\OpenAI` and normalize into the existing
provider-neutral DTOs.

The configured default model is `gpt-5.6-luna`. A `CompletionRequest` may
select another model through its existing neutral `model` field. Version 0.1.2
supports no provider-specific request options and fails clearly if options are
supplied rather than silently ignoring them. Requests set provider-side storage
off. Vendor response identifiers and other raw fields are not exported as
Jarvis result metadata.

The only credential name used by this adapter is:

```text
GRAV_JARVIS_OPENAI_API_KEY
```

Set it in the PHP/web/CLI process environment or the hosting platform's secret
manager. It is not a YAML key, Admin field, request option, query parameter,
ordinary header value, diagnostic field, test fixture, or package file.

## Bounded production transport

The production transport is provider-neutral and replaceable through the
existing `HttpTransportInterface`. Its default policy:

- accepts HTTPS only and requires an exact configured origin and base path;
- resolves every destination address, rejects any private, loopback, link-
  local, reserved, or literal-IP target, then pins the validated address for
  the request to prevent DNS rebinding;
- verifies TLS hostnames and certificates;
- disables redirects and environment-configured HTTP proxies;
- applies explicit connect and overall request timeouts;
- bounds request bodies, response headers, and decompressed response bodies;
- rejects caller-controlled host, proxy, framing, and hop-by-hop headers; and
- returns safe typed diagnostics without response bodies, request bodies,
  authorization values, or provider credential text.

The offline fixture transport remains available for deterministic provider
tests and has no network fallback.

Use the additive service only after a type check:

```php
use Grav\Plugin\GravJarvis\Contracts\ProviderIntrospectionServiceInterface;

if ($jarvis instanceof ProviderIntrospectionServiceInterface) {
    $validation = $jarvis->validateProvider('example');
    $models = $validation->usable
        ? $jarvis->discoverModels('example')
        : null;
}
```

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
environment variables. They are never a `CompletionRequest` option, ordinary
HTTP header string, plugin YAML value, Admin field, API payload, log field, or
serialized DTO.

`EnvironmentCredentialResolver` is bound to one provider identifier. It only
accepts uppercase environment names within that provider's namespace. For
provider `example-provider`, the namespace is:

```text
GRAV_JARVIS_EXAMPLE_PROVIDER_*
```

Credential values exist only as in-memory `CredentialValueInterface` objects.
They cannot be serialized, display as redacted during debugging, and can be
prefixed safely for an HTTP authentication scheme without converting the
secret into an ordinary configuration value. Missing, malformed, and cross-
provider environment references fail with typed exceptions that mention only
the provider and environment-variable name.

## Deterministic HTTP conformance

`FixtureHttpTransport` matches a sanitized request fingerprint to a fixed
response or failure. It has no network fallback and stores only redacted
request history: raw bodies become a byte count and digest, while credential
headers become redaction markers. Raw HTTP responses cannot be serialized.
`ConformanceFakeProvider` exercises validation, model
discovery, response normalization, authentication-style failures, rate-limit
guidance, and malformed payloads against that transport. Both classes live in
the `Testing` namespace and are never registered during normal plugin boot.

## Deterministic test provider

`Grav\Plugin\GravJarvis\Testing\DeterministicFakeProvider` exists only for
contract tests. It hashes the canonical request and returns a stable response
and character-unit usage. Jarvis never registers it during normal plugin boot.

Run the complete 0.1.0 compatibility, 0.1.1 provider-boundary, and 0.1.2
transport/OpenAI suite with host PHP or the repository's DDEV fixture:

```bash
./scripts/test-grav-jarvis-contract.sh
```

## Deliberately deferred

- Anthropic, OpenAI-compatible, Gemini, and OpenRouter adapters
- validation/model-discovery CLI commands
- streaming and CLI chat
- prompt libraries and page/frontmatter/media context
- Admin2 UI and proposal/diff/approval workflows
- retries, caching, cost reports, chunking, and background jobs
- batch work, REST/MCP workflows, and suite integrations

See the repository's `docs/planned/grav-jarvis.md` for the staged roadmap.

## License

MIT. See `LICENSE`.
