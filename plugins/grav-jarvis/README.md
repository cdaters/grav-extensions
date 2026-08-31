# Jarvis

Jarvis 0.2.1 hardens the first user-usable Admin2 release of the provider-neutral
AI service for Grav 2. It provides a permission-filtered general assistant and
native page-editor context panel on the bounded OpenAI, Anthropic, and
configured OpenAI-compatible provider foundation.

Page actions are review-first. Jarvis captures the current unsaved Markdown
buffer, produces a proposal, and shows a before/after comparison. **Accept**
updates only that unsaved buffer; it never saves, publishes, deletes, or
otherwise mutates a page. **Reject** changes nothing. Provider credentials stay
in server environment variables and never enter Admin2.

## Requirements

- Grav 2.0.19 or newer
- Grav API plugin 1.0.21 or newer
- Admin2 2.1.2 or newer for the native page-editor context panel
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
`$grav['gravJarvis']`. The official `openai` and `anthropic` providers are
registered by default.
Without `GRAV_JARVIS_OPENAI_API_KEY` in the server process environment,
validation reports a missing credential and generation fails through Jarvis's
typed, redacted failure boundary. The provider may be disabled, and its
non-secret default model changed, in plugin configuration.

Anthropic behaves the same way with
`GRAV_JARVIS_ANTHROPIC_API_KEY`. Credentials are resolved lazily only when the
corresponding provider is validated, inspected, or called. Either built-in
provider may be enabled or disabled independently.

Compatible providers are disabled by default and configured as named instances
in environment-specific YAML. An instance contains only non-secret metadata:

```yaml
providers:
  openai_compatible:
    enabled: true
    instances:
      - id: compatible-gateway
        base_uri: https://gateway.example/v1
        credential_environment_variable: GRAV_JARVIS_COMPATIBLE_GATEWAY_API_KEY
        default_model: operator-model
        model_discovery: true
```

The environment-variable name must belong to the instance identifier's Jarvis
namespace. Its value exists only in the process environment.

## Admin2 assistant and page actions

Jarvis adds a sidebar page for general prompts and a native context-panel
launcher in the Admin2 page editor. Both call Jarvis through authenticated Grav
API routes; the browser never calls a provider endpoint.

The assistant shows registered providers, discovered models when available,
safe validation state, loading/error/retry feedback, normalized output, and
provider-reported usage. A discovery failure leaves the configured provider
default available. Provider and model choices are not persisted in 0.2.1.

The page panel supports Rewrite, Proofread, Shorten, Expand, Summarize, and
Custom Prompt. The internal prompt library has stable action identifiers,
separates the authorized instruction from untrusted page context, preserves
useful Markdown, and tells providers not to invent frontmatter or claim a
save/publish action. Custom Prompt keeps the user instruction separate from
the page context and current content.

Jarvis uses Admin2's public `grav:editor:get-content`,
`grav:editor:content-response`, and `grav:editor:insert-content` events. The
last event uses `mode: replace`, which keeps the update inside Admin2's normal
dirty/undo/editor behavior. Jarvis does not dispatch save or publish events.

Selection-aware editing is intentionally deferred: Admin2 2.1.2 does not
publish a stable selected-text contract. Version 0.2.1 operates on the whole
current buffer rather than reaching into editor internals.

### Permissions

- `grav-jarvis.access` — see the Jarvis assistant and provider status;
- `grav-jarvis.use` — run prompts and create current-page proposals; and
- `grav-jarvis.approve` — accept a reviewed proposal into the unsaved buffer.

The server enforces these permissions even if a control is bypassed. Page
context additionally requires effective `api.pages.read`; acceptance requires
effective `api.pages.write` and honors page frontmatter ACLs, API-key scopes,
demo restrictions, and Grav super-user behavior. A user without the relevant
Jarvis permission does not receive the sidebar or page-panel registration.

### Bounded context and proposal receipts

The current page envelope includes only route, title, template, language,
parsed frontmatter, current unsaved Markdown, and bounded media metadata. It
never contains media bytes, filesystem paths, arbitrary site pages, provider
credentials, or unrelated site content.

- content: 49,152 bytes, using a deterministic head/tail window;
- frontmatter: 8,192 encoded bytes;
- media metadata: 4,096 encoded bytes and at most 32 items; and
- reviewable provider output: 65,536 bytes.

Secret-like frontmatter keys and every known `GRAV_JARVIS_*` environment value
are redacted before context reaches a provider. Truncation is visible. If the
current content or provider output exceeds the reviewable limit, Jarvis returns
a preview-only proposal with no acceptance receipt.

Reviewable proposals receive a random, one-time receipt that expires after 15
minutes. The private Grav cache record stores only actor, route, source, and
proposal hashes plus expiry—never the prompt, page content, or provider output.
Accept rechecks the actor, route, current unsaved-buffer hash, proposal hash,
page update permission, and one-time receipt. Changed, expired, mismatched, or
replayed proposals fail closed.

Reject explicitly revokes its receipt. A successful regeneration revokes the
receipt it replaces, while a failed regeneration leaves the reviewed proposal
available for a safe retry. Receipts are capped, cleaned deterministically, and
remain bound to the original actor and route. Cross-user, cross-page, expired,
rejected, replaced, accepted, and replayed receipts cannot be used.

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
select another model through its existing neutral `model` field. Requests set
provider-side storage off. Vendor response identifiers and other raw fields
are not exported as Jarvis result metadata. Version 0.1.4 accepts the provider-
neutral `max_output_units` request option as a bounded integer and maps it to
OpenAI's private output-limit field. Other options fail clearly rather than
being silently ignored.

The only credential name used by this adapter is:

```text
GRAV_JARVIS_OPENAI_API_KEY
```

Set it in the PHP/web/CLI process environment or the hosting platform's secret
manager. It is not a YAML key, Admin field, request option, query parameter,
ordinary header value, diagnostic field, test fixture, or package file.

## Official Anthropic provider

The `anthropic` adapter uses only Anthropic's fixed official API base and the
[versioned direct API](https://platform.claude.com/docs/en/api/versioning)
contract declared by `anthropic-version: 2023-06-01`. Provider validation and
model discovery call the official
[Models endpoint](https://platform.claude.com/docs/en/api/models); synchronous
text generation calls the official
[Messages endpoint](https://platform.claude.com/docs/en/api/http/messages/create).
API-key headers, message request fields, content blocks, stop details, model
metadata, errors, and usage fields remain inside
`Grav\Plugin\GravJarvis\Provider\Anthropic`.

The configured default model is `claude-sonnet-5`. The existing neutral model
override remains available. Jarvis sends one user text turn and maps optional
instructions to Anthropic's top-level system field. Multiple returned text
blocks are combined in order; non-text blocks are not exported. Empty,
malformed, incomplete, tool-oriented, or refusal-style output fails through a
typed provider boundary. Provider-reported input and output usage normalizes
to `Usage`; raw cache, service-tier, request-ID, and other vendor metadata does
not cross the adapter.

Anthropic requires an output limit. The adapter defaults to 1,024 provider
units and accepts the same provider-neutral `max_output_units` option as the
OpenAI adapter. Anthropic's private request-field name remains inside the
adapter.

The only credential name used by this adapter is:

```text
GRAV_JARVIS_ANTHROPIC_API_KEY
```

Its value follows the same environment-only, non-serializable, redacted path
as every Jarvis provider credential. No plugin setting accepts the value.

## Bounded production transport

The production transport is provider-neutral and replaceable through the
existing `HttpTransportInterface`. Its default policy:

- accepts HTTPS only and requires an exact configured origin and base path;
- permits only bounded, non-secret query data on that path so an adapter can
  follow an official pagination contract; credential query keys remain denied;
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

## OpenAI-compatible profile

The generic adapter is separate from the official `openai` provider. Each
instance has a stable provider ID, deliberate public HTTPS base URI, provider-
scoped credential environment-variable name, default model, and truthful
model-discovery flag. The endpoint is immutable after construction and cannot
be overridden by a completion request. Private, loopback, link-local, reserved,
and mixed public/private destinations are refused; 0.1.3 has no local-network
opt-in.

Version 0.1.3 requires a Responses-compatible subset: `POST /responses` accepts
`model`, string `input`, optional string `instructions`, and `store: false`;
text is returned through `output_text` or message/content `output_text` items.
Token usage is optional. `GET /models` is required only when the instance
declares `model_discovery: true`. A Chat Completions-only response, missing
required text shape, malformed usage, missing declared endpoint, or other
partial implementation fails through a typed provider boundary rather than
being guessed into compatibility.

The adapter currently implements only text completion, provider validation,
and optionally model discovery. It does not claim streaming, structured
output, or tool-calling capabilities because those operations are deferred.
When discovery is deliberately disabled, validation checks local configuration
and credential presence and returns a warning that no non-generating remote
validation was performed.

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

Run the complete frozen 0.1.x provider suite plus the 0.2.1 Admin backend
contract with host PHP or the repository's DDEV fixture:

```bash
./scripts/test-grav-jarvis-contract.sh
```

Run the deterministic browser-component contract with Node.js:

```bash
./scripts/test-grav-jarvis-admin-ui.sh
```

Run the authenticated Admin2 regression in the repository's disposable DDEV
fixture. The harness installs only test fixtures, creates a random temporary
super-admin account, restores the prior plugin/account/index/notification
state, and clears Grav's cache on exit:

```bash
./scripts/test-grav-jarvis-admin-browser.sh
```

The Admin contracts cover all six actions, bounded context and truncation,
frontmatter/media filtering, known-secret redaction, provider/model/status
data, one-time acceptance and replay/stale failure, absence handling, official
unsaved-buffer events, Reject non-mutation, and absence of save/publish or
browser-to-provider traffic. The authenticated regression also covers all six
actions end to end, explicit Reject revocation, one-time Accept, stale and
replacement behavior, typed retryable failures, accessible keyboard/focus
semantics, narrow layouts, and inherited light/dark Admin2 themes. No live
credential, provider network request, or paid API credit is needed.

Repository contributors may explicitly opt into a tiny live end-to-end smoke
through the public Jarvis service:

```bash
GRAV_JARVIS_LIVE_SMOKE=1 ./scripts/test-grav-jarvis-live.sh openai
GRAV_JARVIS_LIVE_SMOKE=1 ./scripts/test-grav-jarvis-live.sh anthropic
```

The live harness is never called by default. It skips successfully when the
selected provider credential is absent, caps generated output, prints no
request or response content, and records only provider/model identifiers,
catalog size, usage availability, and normalized success. Deterministic
fixtures remain the release gate; a live account request is optional.

## Deliberately deferred

- Gemini and OpenRouter adapters
- validation/model-discovery CLI commands
- streaming and CLI chat
- automatic provider retries, caching, cost reports, chunking, and background jobs
- selection-aware editing until Admin2 exposes a stable selection contract
- structured metadata/frontmatter proposal application
- prompt/response history or conversational memory
- batch work, REST/MCP workflows, and suite integrations

See the repository's `docs/planned/grav-jarvis.md` for the staged roadmap.

## License

MIT. See `LICENSE`.
