# Jarvis

Jarvis 0.3.3 is the provider-neutral AI service for Grav 2. A normal
administrator can open Jarvis, paste an OpenAI or Anthropic API key, choose
**Save & Validate**, discover models, and begin using the assistant. The key is
written only to Jarvis's dedicated encrypted server-side store; it is never
returned to the browser or copied into ordinary plugin YAML. Advanced operators
can continue to inject environment credentials, which always take precedence.

Page actions are review-first. Jarvis captures the current unsaved Markdown
buffer, produces a proposal, and shows a before/after comparison. **Accept**
updates only that unsaved buffer; it never saves, publishes, deletes, or
otherwise mutates a page. **Reject** changes nothing.

## Requirements

- Grav 2.0.19 or newer
- Grav API plugin 1.0.21 or newer
- Admin2 2.1.2 or newer for the native page-editor context panel
- PHP 8.3 or newer, following Grav 2's supported runtime
- PHP cURL extension for production provider requests
- Sodium (recommended) or OpenSSL with AES-256-GCM for Admin-entered encrypted
  credentials. If neither is available, environment credentials still work.

## Quick Start

1. Copy `grav-jarvis` to `user/plugins/grav-jarvis` and clear Grav's cache:

   ```bash
   bin/grav clearcache
   ```

2. Sign in to Admin2 and open **Jarvis**. The header includes an authorized
   **Settings** shortcut for non-secret plugin defaults. Provider cards and the
   concise **Environment readiness** panel explain whether encrypted key entry
   is available.
3. Obtain an API key from the provider's API console. For OpenAI, follow the
   project-key procedure below; a ChatGPT subscription is not sufficient.
4. Paste the key into that provider's write-only **API key** field and choose
   **Save & Validate**. Jarvis encrypts it outside plugin YAML before testing
   the provider and discovering models. A validation failure does not discard a
   successfully stored key; replace, retry, or remove it from the same card.
5. Advanced operators may instead set `GRAV_JARVIS_OPENAI_API_KEY` or
   `GRAV_JARVIS_ANTHROPIC_API_KEY` in the web PHP environment. Restart/reload
   the service, return to Jarvis, and choose **Validate / Test connection**.
6. Review discovered models. Keep the configured default or choose another
   model for the current request. Persist non-secret provider/default-model
   changes through the header **Settings** button.

The plugin is enabled by default and registers `$grav['gravJarvis']`. OpenAI
and Anthropic are enabled independently. No credential is resolved and no
network request occurs merely because the plugin boots.

## OpenAI setup

Use the [OpenAI API platform](https://platform.openai.com/), not the ordinary
ChatGPT conversation interface:

1. Sign in with the OpenAI account that should own Jarvis API usage.
2. Use an existing API project or create a dedicated Jarvis project from
   [OpenAI project settings](https://platform.openai.com/settings/organization/projects).
   A dedicated project is recommended, not mandatory: it isolates usage
   accounting, project spend/model controls, key rotation/revocation, and the
   blast radius of a leaked key from unrelated applications.
3. Configure API billing and appropriate usage limits for that API
   organization/project. OpenAI API billing is separate from ChatGPT billing.
4. Open the project's [API Keys page](https://platform.openai.com/api-keys) and
   choose **Create new secret key**.
5. Prefer a dedicated Jarvis key. If the project-key UI offers restricted
   endpoint permissions (see OpenAI's official
   [key-permissions guide](https://help.openai.com/en/articles/8867743)), Jarvis needs read access to Models for
   `GET /v1/models` and write access to Responses for `POST /v1/responses`.
   It does not need organization administration, project/key management, file,
   assistant, batch, fine-tuning, or other API permissions for its current
   implementation. Permission labels can evolve; map them to these two actual
   endpoints rather than granting unrelated access.
6. Copy and securely store the secret when it is created; the full value may
   not be shown again.
7. Paste it into OpenAI's write-only field in Jarvis Admin2 and choose
   **Save & Validate**. For an advanced environment deployment, configure it as
   `GRAV_JARVIS_OPENAI_API_KEY`, restart/reload the service, then select
   **Validate / Test connection**.
8. Discover and select the desired model. Jarvis will flag a configured default
   that discovery does not return, but will not silently replace it.

Never put the real key in Git, Markdown, plugin configuration YAML, screenshots,
browser storage, JavaScript, a committed DDEV file, a prompt, or chat. Jarvis
does not create keys and must not receive one through this documentation.

### OpenAI API billing is not a ChatGPT subscription

A ChatGPT login or subscription—including Plus, Pro, Business, Enterprise, or
another ChatGPT plan—is not an OpenAI API credential and does not provide
Jarvis API billing. ChatGPT and the API platform maintain separate billing and
usage systems; see OpenAI's official
[billing distinction](https://help.openai.com/en/articles/9039756).

Jarvis requires an API-platform project key. It never reads or attempts to use
ChatGPT cookies, browser sessions, OAuth state, local browser storage, or
ChatGPT subscription credentials.

## Anthropic setup

1. Sign in to the Claude Console and open
   [Settings → API keys](https://console.anthropic.com/settings/keys).
2. Create a personal or service-account key appropriate to the workspace that
   should own Jarvis usage, and choose an appropriate expiration. A dedicated
   Jarvis/workspace-scoped key limits unrelated access and makes accounting,
   rotation, and revocation clearer. Follow Anthropic's current
   [authentication guidance](https://platform.claude.com/docs/en/manage-claude/authentication)
   for personal versus service-account keys, workspace scope, expiration, and
   rotation.
3. Jarvis currently needs the key to list models with `GET /v1/models` and send
   completions with `POST /v1/messages`; it does not call Anthropic's Admin,
   Files, Batches, or key-management APIs.
4. Paste the secret into Anthropic's write-only Jarvis Admin2 field and choose
   **Save & Validate**. For an advanced environment deployment, configure
   `GRAV_JARVIS_ANTHROPIC_API_KEY`, restart/reload, and validate it in Admin2.
5. Review the discovered model list and configured default.

Anthropic Console/browser-session credentials are not provider API keys and
are never used by Jarvis.

## DDEV and local development

The repository's canonical disposable fixture is:

```text
/Users/cdaters/Documents/Spitfire/custom-plugins/file-vault-ddev
```

It uses DDEV 1.25.3 and nginx/PHP-FPM. Its generated `.ddev/.gitignore` already
ignores `.ddev/config.local.yaml`, making that local override the cleanest
development-only credential file for this fixture. Create or edit:

```yaml
# .ddev/config.local.yaml — local and ignored; never commit this file
web_environment:
  - GRAV_JARVIS_OPENAI_API_KEY=sk-REPLACE-ME
  # - GRAV_JARVIS_ANTHROPIC_API_KEY=sk-ant-REPLACE-ME
```

Then restart DDEV so the variable reaches both PHP-FPM and `ddev exec`:

```bash
cd /Users/cdaters/Documents/Spitfire/custom-plugins/file-vault-ddev
ddev restart
ddev exec bash -lc 'test -n "$GRAV_JARVIS_OPENAI_API_KEY" && echo "OpenAI credential configured" || echo "OpenAI credential missing"'
```

The check reports presence only and never prints the value. Open Jarvis Admin2
and validate OpenAI. From this repository, the explicitly opt-in bounded live
proof can use the same container environment:

```bash
cd /Users/cdaters/Code/grav-extensions
GRAV_JARVIS_LIVE_SMOKE=1 ./scripts/test-grav-jarvis-live.sh openai
```

Normal tests never make that call. To rotate the key, replace the value in
`.ddev/config.local.yaml` and run `ddev restart`; to remove it, delete the line
or local file and restart. In any DDEV project that does not carry the standard
ignore rule, add `.ddev/config.local.yaml` to the project's root `.gitignore`
before storing a secret. Jarvis packages exclude `.ddev` and reject `.env*` or
common credential files.

## Production environment configuration

The beginner-safe default is Admin2 encrypted storage. Production operators who
already have a managed secret system should prefer the hosting platform's
secret manager, container/service environment, or PHP-FPM pool configuration.
Direct provider variables override a stored local credential without deleting
it. For example, a protected PHP-FPM pool configuration can provide:

```ini
env[GRAV_JARVIS_OPENAI_API_KEY] = sk-REPLACE-ME
env[GRAV_JARVIS_ANTHROPIC_API_KEY] = sk-ant-REPLACE-ME
```

Reload PHP-FPM after changing it. Shell exports affect only that shell and do
not automatically reach an already-running PHP-FPM/web process.

Grav 2 can also load an untracked site-root `.env.local` file. This is a
filesystem fallback, not an Admin secret store: restrict its permissions,
ensure the web server denies `.env*` requests, exclude it from source control
and backups shared outside the trusted operator boundary, and prefer managed
service secrets where available.

```text
GRAV_JARVIS_OPENAI_API_KEY=sk-REPLACE-ME
```

Do not use `GRAV_CONFIG__...` to copy a provider key into plugin configuration.
Jarvis deliberately reads only its provider-specific environment variables.

### Optional external Jarvis master key

By default, the first Admin-entered credential causes Jarvis to atomically
create a 32-byte random master key beside—but separate from—the encrypted
records under `user/data/grav-jarvis/credentials`. The directory is hardened to
`0700` and files to `0600` where the filesystem supports Unix permissions.

Advanced deployments may keep the encryption key outside the Grav filesystem:

```text
GRAV_JARVIS_MASTER_KEY=base64:REPLACE-WITH-BASE64-OF-EXACTLY-32-RANDOM-BYTES
```

Generate the required representation in a trusted administrative shell:

```bash
php -r 'echo "base64:", base64_encode(random_bytes(32)), PHP_EOL;'
```

Store the output in the deployment secret manager; never commit or paste it
into Jarvis YAML. Jarvis accepts only the `base64:` representation of exactly
32 random bytes. It does not truncate, pad, or treat a human password as a key.
The external key is preferred for newly saved/replaced records. Each existing
record keeps its explicit local/external key source so merely adding the
variable cannot make an older local record unreadable.

## Encrypted local credential architecture

Credential resolution is deterministic:

1. the provider's direct environment variable;
2. that provider's encrypted local Jarvis record;
3. missing.

Jarvis does not implement a plaintext compatibility store. If no authenticated
encryption backend is available, Admin key entry is disabled and the provider
environment variables remain fully supported.

New local records use Sodium XChaCha20-Poly1305 when available. Otherwise,
Jarvis uses OpenSSL AES-256-GCM only when the runtime advertises that exact
authenticated cipher. Every record is versioned and contains only provider ID,
backend/key-source metadata, nonce/IV, authentication data, and ciphertext.
Provider credentials are never stored in `grav-jarvis.yaml` or ordinary Grav
configuration. Authentication failure, malformed records, wrong keys, missing
backends, and tampering fail closed.

The auto-managed key protects against ordinary plugin configuration export,
accidental YAML/Git disclosure, and isolated theft of the encrypted record. It
does not protect against a full compromise of the Grav runtime account or
filesystem that exposes both the key and ciphertext. Use environment provider
credentials or an external master key when stronger operational separation is
required.

## Validate / Test Connection

The Admin2 action is authenticated, permission checked, and server-side. It
uses provider validation/model discovery rather than a content-generation
request, so it does not intentionally spend generation tokens. The browser
receives only normalized status, safe issue text, capabilities, and models—no
credential, Authorization header, raw provider JSON, response body, endpoint
authority, or stack trace.

**Save & Validate** first commits the encrypted record atomically and then runs
the same non-generating provider validation/model discovery path. Therefore a
message such as “Credential saved securely, but OpenAI rejected the key” means
storage succeeded and remote validation failed; the operator can replace,
retry, or remove it without ambiguity.

States distinguish missing/malformed credentials, rejected authentication,
rate/quota limits, transport failure, invalid configuration/response, and an
otherwise unavailable provider. **Configured** means a well-formed environment
or decryptable local value exists; **Valid** means the provider accepted the
model-list request. Those are intentionally different claims.

## Model selection and defaults

Set the preferred provider and each provider's non-secret default model in
Admin2 **Plugin settings**. The Jarvis page opens with the preferred registered
provider, identifies the configured default explicitly, and lists discovered
models and available capability metadata.

Discovery never rewrites configuration. A temporary discovery failure retains
the configured default and any current selection. If discovery succeeds but
does not return the configured model, Jarvis flags it as not discovered so the
operator can investigate deprecation, account/model permissions, or a renamed
identifier; it does not guess a replacement.

## Credential rotation, migration, and removal

1. Create the replacement key in the provider project/workspace and apply only
   the permissions Jarvis needs.
2. For encrypted local storage, enter the replacement and choose **Save &
   Validate**. For an environment deployment, replace the value and
   reload/restart PHP-FPM, the hosting service, or DDEV.
3. Confirm validation and model discovery succeed.
4. Revoke/delete the old provider key.

Use **Remove stored credential** to delete only Jarvis's encrypted local record.
It never changes an environment value. If an environment credential overrides
a stored record, the card says so and still allows the inactive record to be
removed. Removing an environment value reveals an existing stored local
credential on the next request.

Backend and local-to-external migration occurs on an explicit provider
replacement: saving a new key uses the currently preferred Sodium/OpenSSL
backend and external/local master-key mode, while untouched records retain
their original backend and source. Jarvis never silently downgrades a Sodium
record. If its named backend or master key is unavailable, it fails safely and
leaves the record unchanged. Whole-store master-key rotation is deliberately
deferred; today rotate by obtaining each provider replacement key, replacing
the stored records under the new master-key mode, validating them, and only
then retiring the old key.

## Troubleshooting provider setup

- **“I have ChatGPT Plus/Pro but Jarvis says OpenAI is not configured.”** A
  ChatGPT plan is separate from the API platform. Create an API project key,
  configure API billing/limits, and set `GRAV_JARVIS_OPENAI_API_KEY` on the
  server.
- **The variable works in my shell but Admin2 says Missing.** PHP-FPM/nginx or
  Apache is a different long-running process. Inject the variable into that
  service (or use protected `.env.local`) and reload it. A shell-only `export`
  does not update the web process.
- **Invalid/authentication failed.** The key may be mistyped, revoked, expired,
  assigned to the wrong project/workspace, or missing the model-list permission.
  Rotate it; do not paste it into logs or a support message.
- **Rate limited or no available quota.** Check provider API usage, project
  limits, and API billing. A ChatGPT payment method does not configure OpenAI
  API billing.
- **Configured model not discovered.** Check the exact identifier, project
  model permissions, account access, and provider deprecation notices. Jarvis
  intentionally preserves the configured value until an administrator changes
  it.
- **DDEV still says Missing after editing local config.** Run `ddev restart`;
  container environment changes are applied at restart, not merely by editing
  the file.
- **Provider temporarily unavailable/transport failure.** Check outbound HTTPS,
  DNS, TLS trust, firewall policy, and the provider status page. Jarvis does not
  follow redirects or environment proxies and refuses private/reserved targets.
- **Encrypted key entry is unavailable.** Expand **Environment readiness**. If
  both Sodium and OpenSSL AES-256-GCM are unavailable, use a provider
  environment variable. If the data directory or master key is missing,
  corrupt, symlinked, or unwritable, correct the protected server-side path;
  Jarvis will not regenerate a key while encrypted records exist.
- **An environment credential is active.** Environment values intentionally
  win. Remove/reload that value to use the stored local credential, or remove
  the inactive local record from its card.

## OpenAI-compatible providers

Compatible providers are disabled by default. Admin2 plugin settings can store
only the instance ID, immutable public HTTPS base URI, credential
environment-variable **name**, default model, and truthful discovery switch:

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

The credential variable must match the instance's provider namespace; its
value exists only in the server environment. Admin2 shows the declared fixed
capabilities and non-secret base URI. Per-request endpoints, browser-supplied
headers, private/loopback hosts, redirects, and mutable endpoints remain
forbidden.

## Security notes

- The only browser request containing a provider secret is the administrator's
  initial authenticated Save & Validate submission. The field is cleared
  immediately; the server never returns the value in bootstrap, status,
  validation, models, errors, debug data, or later state.
- Credential add/replace/remove routes use the Admin2 API-token/CSRF boundary,
  require `grav-jarvis.manage`, plugin-configuration write, or super authority; accept fixed first-party
  provider IDs only, and reject extra request fields. The Settings shortcut is
  separately visible only to plugin-configuration-authorized users.
- The encryption implementation uses PHP's native authenticated primitives and
  `random_bytes`; it does not implement custom cryptography, unauthenticated
  CBC/ECB, weak password conversion, or plaintext fallback.
- Symbolic-link substitutions, malformed/version-confused records, wrong keys,
  unavailable named backends, and authentication-tag failures are rejected.
  Writes use a same-directory temporary file and atomic rename; first local-key
  creation uses exclusive creation so concurrent first use cannot overwrite an
  existing key.
- Authorization headers remain secret value objects rather than loggable
  strings. Provider output/errors are normalized and redacted; raw upstream
  JSON, stack traces, endpoints, and credentials do not cross Admin responses.
- Jarvis never reads ChatGPT or Claude browser cookies, session/OAuth state,
  browser storage, or subscription credentials. Fixed official and validated
  compatible endpoints preserve the existing SSRF and immutable-authority
  boundary.
- Environment variables remain the supported and preferred production source.
  Values are resolved lazily into non-serializable in-memory credential
  objects, redacted from errors/results, and never returned by setup/status APIs.
- Provider credentials, Authorization headers, request/response bodies, raw
  upstream errors, and stack traces are excluded from Admin2, JavaScript,
  caches, logs, fixtures, diagnostics, screenshots, and release packages.
- OpenAI uses only the fixed official base; Anthropic uses only its fixed
  official base. Compatible endpoints are operator-selected once at server
  configuration time and retain HTTPS/public-address/DNS-pinning controls.
- Validation, discovery, and generation remain subject to backend API-token,
  permission, provider, and page-authorization checks. Client controls are not
  authoritative.

## Admin2 assistant and page actions

Jarvis adds a sidebar page for general prompts and a native context-panel
launcher in the Admin2 page editor. Both call Jarvis through authenticated Grav
API routes; the browser never calls a provider endpoint.

The assistant shows enabled and disabled provider setup cards, local credential
state, exact environment-variable names, safe remote validation, configured
defaults, discovered models, loading/error/retry feedback, normalized output,
usage, estimated cost, request/retry count, and cache state. A discovery failure
leaves the configured provider default and current selection available. The
preferred provider and provider defaults persist through normal Admin2 plugin
configuration; one-off selector choices remain local to the current surface.

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
publish a stable selected-text contract. Version 0.3.2 operates on the whole
current buffer rather than reaching into editor internals.

## Reliability, cost, and large context

`ReliabilityServiceInterface` is an additive subinterface. The original
`complete()` path remains unchanged; `completeReliable()` composes policy around
it. Consumers must type-check the additive interface before using it.

- Retry is enabled by default for normalized failures explicitly marked
  retryable. It is capped at three attempts and five elapsed seconds by default,
  uses exponential backoff with bounded jitter, and respects a normalized
  retry-after value within the delay cap. Credential, configuration, and
  authentication failures are never retried.
- Response caching is disabled by default. When enabled, only configured named
  actions such as rewrite, proofread, shorten, expand, and summarize qualify;
  general/custom prompts do not. Keys are SHA-256 digests of canonical request
  and site/actor/page/action/provider/model scope. Private owner-only records
  contain the redacted successful result, scope hash, and bounded expiry—not
  the request or raw prompt. Default TTL is five minutes and capacity is 128.
- `UsageReport` preserves unknown input/output/total values as `null` and adds
  provider/model, neutral unit, optional cache-use fields, request count, retry
  count, and cache-hit state. Admin2 presents these fields concisely.
- Cost reporting uses only operator-supplied, versioned model metadata in
  `pricing.models`. Input/output/cache rates are decimal strings per million
  neutral units and are calculated with fixed-point nanocurrency arithmetic.
  Pricing is not fetched at runtime. Missing/stale pricing or usage remains
  visibly unknown; an estimate is never represented as a provider bill.
- Budgets are disabled by default. Optional limits cover provider request and
  retry count, input bytes, known output units, and known estimated request or
  logical-operation cost. Known excesses fail before the next provider call.
  Unknown pricing/usage is disclosed and is not falsely claimed as enforced.
- The Markdown chunker preserves source hashes and byte ranges, ordering, YAML
  frontmatter, paragraphs/lists, and fenced code as atomic blocks. Chunk size,
  total bytes, count, and synthesis input are bounded. Normal operation fails
  clearly rather than truncating. The only chunked execution in 0.3.0 is
  summarization: ordered chunk summaries feed one bounded final synthesis.
  Rewrite/proofread reconstruction is deferred because preserving untouched
  structure cannot yet be guaranteed.

Reliability accounting exists only for the current in-memory operation. Cache
entries expire and are capacity bounded. Jarvis adds no external telemetry,
request-history database, durable accounting, user behavior profile, crawler,
RAG/vector store, recursive expansion, or background queue.

Example non-secret configuration:

```yaml
reliability:
  cache:
    enabled: false
  budgets:
    enabled: true
    max_request_count: 4
    max_retry_count: 1
    max_input_bytes: 196608
    max_estimated_cost_per_operation: '0.05'
pricing:
  version: operator-2026-08
  as_of: '2026-08-30'
  currency: USD
  models:
    - provider: example
      model: example-model
      unit: tokens
      input_per_million: '1.50'
      output_per_million: '6.00'
```

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

Jarvis 0.3.0 follows the same additive rule with
`ReliabilityServiceInterface`, reliability/cache/budget/chunk policy DTOs,
usage/cost/result DTOs, provider-neutral cache/chunk contracts, and typed budget
and chunking failures. No frozen 0.1.x interface contains retry, cache, pricing,
budget, chunk, OpenAI, or Anthropic vocabulary.

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

Supply it through the encrypted Admin2 flow or the PHP/web/CLI process
environment. It is not a YAML key, request option, query parameter, diagnostic
field, fixture, or package file. After the one write-only Admin submission, the
provider receives the value only as a non-serializable credential object.

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

Its value follows the same encrypted-local-or-environment, non-serializable,
redacted path as OpenAI. No ordinary plugin setting accepts the value.

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
Environment values are held only in memory. Admin-entered values are persisted
only as authenticated ciphertext in the protected user-data store. Neither
source writes a secret to plugin configuration, request metadata, logs, caches,
fixtures, or packages.

Provider credentials are resolved inside a provider through the unchanged
credential contract. They are never a `CompletionRequest` option, ordinary
HTTP header string, plugin YAML value, response payload, log field, or
serialized DTO. An Admin-entered value appears only in the initial authenticated
write request and is never echoed.

`CompositeCredentialResolver` first delegates to the existing
`EnvironmentCredentialResolver`, which is bound to one provider identifier and only
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
the provider and environment-variable name. Only environment absence permits
the encrypted local fallback; a malformed higher-priority environment value
fails closed.

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

Run the complete frozen 0.1.x provider suite plus the 0.2.x Admin, 0.3.0
reliability/chunking, 0.3.2 provider setup, and 0.3.3 credential/readiness contracts with host PHP or the
repository's DDEV fixture:

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
- durable background jobs and request/accounting history
- rewrite/proofread chunk reconstruction
- selection-aware editing until Admin2 exposes a stable selection contract
- structured metadata/frontmatter proposal application
- prompt/response history or conversational memory
- batch work, REST/MCP workflows, and suite integrations

See the repository's `docs/planned/grav-jarvis.md` for the staged roadmap.

## License

MIT. See `LICENSE`.
