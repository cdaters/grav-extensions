# Jarvis

**Status:** 0.3.0 reliability, cost control, and large-context infrastructure
implemented; optional Grav Commander consumer integration is the recommended
next milestone

**Product name:** Jarvis

**Plugin slug and directory:** `grav-jarvis`

**Target:** Grav 2, Grav API, and Admin2

Jarvis is a site-agnostic AI service and agent-integration framework for
Grav 2. It gives Admin2, CLI workflows, and other Grav plugins one guarded way
to use configured model providers while leaving page and site operations to
Grav's native REST API and MCP server.

The project is not a feature-for-feature clone of Grav AI Pro. AI Pro validates
the value of provider abstraction, an Admin assistant, reusable prompts,
streaming, background work, caching, retries, usage reporting, and a public
service. Jarvis adopts those public product lessons without copying premium
source or private APIs, then makes Grav 2's REST/MCP permission and concurrency
model, safe proposals, and cross-plugin interoperability the center of the
design.

## Product boundary

Jarvis owns model-provider access and safe AI workflow orchestration. Grav
continues to own pages, media, configuration, accounts, packages, permissions,
and API writes.

```text
Admin2 assistant      CLI       Other plugins (for example Grav Commander)
       |                |                         |
       +----------------+-------------------------+
                                |
                    public Jarvis PHP service
                                |
          prompts / policy / proposals / jobs / usage
                                |
          OpenAI | Anthropic | OpenAI-compatible providers

External AI agent -------- Grav MCP -------- Grav REST API
                                |
                 permissions + ETags + API events
```

The two paths are complementary:

- the internal service calls models for generation, analysis, classification,
  and structured proposals;
- Grav REST/MCP exposes authorized site operations to external agents; and
- a combined workflow may ask a model to propose a change, then use a normal
  Grav API operation to apply an approved proposal.

Jarvis must not create a second page-writing or permission system and must not
quietly translate a model response into a filesystem mutation.

## Core architecture

### Provider abstraction

The internal PHP root namespace follows Grav Commander's established slug-to-
class convention: `Grav\Plugin\GravJarvis`. Public contracts live beneath
`Grav\Plugin\GravJarvis\Contracts`; consumers import those contracts rather
than concrete provider or service classes.

The public contract normalizes provider differences behind small PHP
interfaces and immutable request/result objects. The 0.1.0 vocabulary is:

- `JarvisServiceInterface` for synchronous `complete()`, registry discovery,
  provider identifiers, and capability discovery;
- `ProviderInterface` for a stable identifier, declared capabilities, and
  synchronous generation;
- `ProviderRegistryInterface` plus `onJarvisProviderRegister` so companion
  plugins can add providers without editing Jarvis;
- `CompletionRequest`, `CompletionResult`, `Usage`, and provider-neutral error
  categories; and
- provider-declared capability strings and usage units without a vendor-
  specific or token-only vocabulary.

Streaming events, prompts, proposals, and cost reporting remain additive future
contracts. Optional provider capabilities use separate interfaces or
capability checks so the 0.1.0 provider contract does not accumulate methods
every adapter must fake.

Version 0.1.1 applies that rule through `ProviderValidationInterface` and
`ModelDiscoveryInterface`. `ProviderIntrospectionServiceInterface` extends the
unchanged 0.1.0 service contract and returns provider-neutral
`ProviderValidationResult`, `ValidationIssue`, `ModelCatalog`, and
`ModelDescriptor` values. Validation and discovery can be added independently;
a provider that implements neither still satisfies `ProviderInterface`.

The Grav container key is `$grav['gravJarvis']`. Consumers type-check the
public interface and degrade cleanly when the plugin or a requested capability
is absent. They never read Jarvis configuration, provider classes, caches,
jobs, or secrets directly.

The live-provider sequence begins with:

1. OpenAI (implemented in 0.1.2);
2. OpenAI-compatible HTTP endpoints (implemented in 0.1.3); and
3. Anthropic (implemented in 0.1.4).

OpenAI is the first implementation checkpoint. The official
[generation API](https://developers.openai.com/api/reference/cli/resources/responses/methods/create)
and [models endpoint](https://developers.openai.com/api/reference/typescript/resources/models/methods/list)
provide one authoritative target, making it possible to prove that vendor
fields are isolated inside one adapter. Starting with a generic "compatible"
endpoint would instead risk treating a family of different servers and partial
compatibility claims as one stable contract. The generic adapter follows only
after the first-party mapping is tested, and it does not inherit OpenAI
behavior that its configured endpoint cannot prove. Implementing the official
adapter first has now established the comparison baseline; 0.1.3 uses a
separate compatible-provider type and an explicit compatibility matrix rather
than adding a base-URL mode to the official adapter.

Gemini and OpenRouter remain later candidates now that two first-party wire
families have proved the contract. OpenRouter may be an
explicit adapter even where its API resembles OpenAI because routing, model
metadata, accounting, and errors are different product semantics. The generic
compatible adapter requires an operator-approved HTTPS origin; loopback or
private-network endpoints are a separate explicit opt-in for local gateways.

Provider-specific request options live in a namespaced escape hatch. Common
features do not collapse to the lowest common denominator, and unsupported
capabilities fail clearly rather than being silently ignored.

### Context and prompt library

The Admin2 assistant can receive a bounded, inspectable context envelope:

- page route, template, language, title, Markdown, and parsed frontmatter;
- selected field or selected text when the editor exposes it;
- media names, MIME types, dimensions, alternative text, and other metadata;
- effective user and operation scope without provider credentials; and
- an explicit list of context items that will leave the server.

Binary media is metadata-only in the first development release. Provider image
input comes later behind capability checks, size limits, and an explicit user
choice.

Prompts are versioned definitions with an identifier, purpose, input schema,
system instructions, user template, output schema, compatible capabilities,
and safety/apply policy. Bundled prompts provide conservative defaults. Site-
owned additions and overrides live outside the plugin directory so upgrades do
not erase them. A resolved prompt and its version are recorded with every
proposal or job.

Page Markdown, frontmatter, media metadata, remote content, and retrieved MCP
resources are untrusted context, never higher-priority instructions. Prompt
construction keeps instructions and content separate and does not grant tools
merely because page content asks for them.

### Admin2 assistant

Admin2 integration follows Grav 2's documented `onApi*` extension points and
web-component conventions. The intended surfaces are:

- an editor context panel for page-aware work;
- an optional permission-filtered floating assistant for general prompts; and
- a plugin page for providers, prompt library, usage, jobs, and diagnostics.

The assistant supports rewrite, proofread, shorten, expand, summarize,
metadata/frontmatter suggestions, and custom prompts. Streaming improves
feedback but does not alter approval rules. Cancel stops presentation and asks
the provider/job to stop where supported; partial output is never silently
applied.

The first Admin2 slice is deliberately smaller and was implemented in 0.2.0:

- add a permission-filtered **Jarvis** item in Admin2's sidebar utility/tools
  area and a page-editor action that opens the same Jarvis surface with current-
  page context;
- populate a provider selector from `providerIds()` and a model selector from
  `discoverModels()`, with an explicit manual model fallback only when a
  provider truthfully lacks discovery;
- show validation as usable, unavailable, misconfigured, or retryable without
  exposing raw provider errors or credential state beyond the safe issue code;
- provide a prompt field, provider-neutral response panel, and initial
  **Rewrite**, **Proofread**, **Shorten**, **Expand**, **Summarize**, and
  **Custom Prompt** actions;
- make the context envelope inspectable before generation and begin with the
  current page's route, title, template, language, Markdown, parsed
  frontmatter, and bounded media metadata—never binary media or hidden secrets;
- render original/proposed text and a readable diff, then require an explicit
  **Accept** or **Reject**. Accept may update only the unsaved editor buffer in
  0.2.0; it must never save the page, overwrite the source automatically, or
  bypass the normal Admin page-save permission and workflow;
- require `grav-jarvis.use` for generation and a separate
  `grav-jarvis.approve` boundary for accepting a proposal into an editable
  field. Recheck the actor's page permission and source hash before accept;
- present typed, redacted errors with retry guidance and preserve entered
  prompt/context locally when a safe retry is possible; and
- progressively disable only Jarvis controls when the plugin, selected
  provider, validation, discovery, or generation path is absent or fails.
  Normal Admin2 navigation, page editing, and saving must remain usable.

The implementation uses a dedicated Admin2 plugin page plus the native
`onApiContextPanels` page-editor panel. It reads the current unsaved buffer only
through `grav:editor:get-content`/`grav:editor:content-response` and accepts
through `grav:editor:insert-content` with `mode: replace`. It does not dispatch
save or publish. Admin2 2.1.2 exposes no stable selected-text event, so
selection-aware editing is explicitly deferred instead of coupling Jarvis to
Editor Pro internals.

Version 0.2.0 does not add durable prompt/proposal history, automatic apply,
streaming, jobs, site-wide targeting, structured frontmatter apply, or MCP
operations. Those remain separate reviewable increments after the synchronous
UI boundary.

Version 0.2.1 keeps that product boundary and hardens it. The assistant and
page panel expose safe provider/model/capability/usage state, categorized
redacted errors, meaningful retry only for retryable failures, semantic status
announcements, visible focus, native keyboard operation, responsive wrapping,
bounded preview overflow, and inherited Admin2 light/dark variables. Failed
generation or acceptance leaves the current buffer untouched and preserves an
unaccepted proposal until successful regeneration or explicit Reject.

A test-only provider plugin and random disposable Admin2 account drive the
signed-in browser regression; neither is shipped in the release ZIP. All six
actions exercise the actual Admin2 page editor. Reject revokes the receipt,
successful regeneration revokes the receipt it replaces, and accepted,
rejected, replaced, expired, wrong-user, wrong-page, stale, or replayed receipts
fail closed. Optional provider/model preference storage was deliberately not
added because it was not required for hardening and would introduce a new user-
data lifecycle. Selection remains whole-buffer because no stable public Admin2
selection event exists.

### Proposal, diff, and approval model

Every content or configuration mutation begins as a proposal. A proposal
records the original revision/ETag or content hash, structured target, original
value, proposed value, prompt/provider/model identifiers, usage, actor, and
expiry.

- Preview is non-mutating.
- Text and structured frontmatter changes have human-readable diffs.
- Apply requires a distinct permission and explicit approval.
- A changed source produces a conflict and requires regeneration or manual
  reconciliation; it is never overwritten with a stale proposal.
- Revision Ledger is asked for a pre-apply checkpoint when available. Jarvis
  still works without it and reports the reduced recovery guarantee.
- Batch workflows approve selected proposals, report each result, and stop or
  continue according to a declared policy. One failure cannot be reported as a
  complete site-wide success.
- Model output cannot select its own approval policy or expand its target set.

### Reliability and operations

- Streaming uses a provider-neutral event sequence and a real Admin2/API
  boundary such as SSE when Grav's current API conventions support it.
- Retries apply only to classified transient failures, use bounded exponential
  backoff with jitter, honor provider retry guidance, and never duplicate an
  unsafe apply.
- Cache keys include provider, model, normalized request/options, prompt
  version, relevant access/context scope, and plugin schema version. Caches do
  not mix users or protected contexts and never contain credentials.
- Token and cost reports retain provider-reported usage separately from
  estimates. Prices are versioned operator data, not timeless constants; an
  unknown price is reported as unknown.
- Long input is split on Grav-aware boundaries. Frontmatter, fenced code,
  Markdown structure, and page identity are preserved, and synthesis records
  every contributing chunk.
- Background jobs are durable, idempotent, cancellable, bounded by time/token/
  cost budgets, and stored outside the public web root. Grav Scheduler or an
  explicit CLI worker processes jobs; a saved schedule alone is not presented
  as a running worker.
- CLI parity begins with `validate`, `models`, and `chat`, then adds job and
  proposal commands. Machine-readable output, stable exit codes, and
  `--no-apply`/preview defaults make automation auditable.

## Plugin-facing service

Other plugins use only the public service and events. A representative
consumer shape is:

```php
use Grav\Plugin\GravJarvis\Contracts\JarvisServiceInterface;

$ai = $grav['gravJarvis'] ?? null;
if ($ai instanceof JarvisServiceInterface) {
    $result = $ai->complete($request);
}
```

The eventual public namespace, DTO fields, exceptions, events, and deprecation
policy are frozen by contract tests before consumers are encouraged to ship.

Grav Commander is the first named integration candidate. Initial Commander
features should be read-only—summarize or explain a selected allowed text file,
for example. Any proposed file edit remains inside Commander's existing root,
extension, size, permission, backup, and containment checks and uses Jarvis's
preview/approval object. Jarvis does not gain Commander write authority, and
Commander does not gain provider credentials. Either plugin remains useful
when the other is absent.

Other likely consumers include Meta Pilot for metadata proposals, Site Workshop
for bounded workflows, and future Page Studio authoring tools. These are
optional integrations, not suite-wide dependencies.

## Agent and MCP integration

Grav's MCP server already maps model clients onto the Grav REST API. Jarvis
will integrate rather than replace it:

- document MCP setup and least-privilege, dedicated API users;
- expose approved Jarvis capabilities through ordinary permission-checked
  Grav API endpoints before considering additional MCP tools;
- use ETag/`If-Match` conflict handling for REST-backed writes;
- preserve API events/webhooks so AI-originated changes remain observable; and
- keep internal provider keys unrelated to MCP API keys.

The 0.x line does not embed a general autonomous agent loop in PHP. External
agents can plan through MCP; the Admin assistant can run bounded, named
workflows. Site-wide agents must enumerate their targets, propose changes, and
wait for the configured approval boundary.

## Security and privacy

Permissions are separate and default-deny. Version 0.2.0 implements:

- `grav-jarvis.access` — view the Jarvis Admin2 page/provider status;
- `grav-jarvis.use` — run permitted prompts and page proposals; and
- `grav-jarvis.approve` — accept a proposal into an unsaved editable buffer.

Later workflow permissions remain planned:

- `grav-jarvis.manage` — configure non-secret provider and prompt settings;
- `grav-jarvis.batch` — create bounded multi-item jobs.

Page use also requires effective `api.pages.read`; approval additionally
requires effective `api.pages.write`. Both use the API plugin's page ACL,
API-key scope, demo, and super-user rules rather than inventing another page
permission system.

Provider secrets are server-only environment variables such as
`GRAV_JARVIS_OPENAI_API_KEY` and `GRAV_JARVIS_ANTHROPIC_API_KEY`.
Versions 0.1.1 through 0.2.1 accept no secret source other than the process
environment.
Configuration may eventually name an environment variable but never contains
its value. Secrets never enter Admin2 JavaScript, API payloads, prompts, logs,
cache keys, job records, exported diagnostics, screenshots, fixtures, or
release ZIPs.

Additional requirements:

- show which provider receives which context before a sensitive request;
- minimize and cap transmitted content;
- validate endpoints and block redirect-based origin changes or cloud metadata
  access; private/local compatible endpoints require explicit opt-in;
- redact authorization headers, request bodies, personal data, and provider
  error details according to policy;
- use per-request, per-user, and per-job token/cost ceilings and rate limits;
- separate conversation history by user and make retention/deletion explicit;
- require CSRF/authentication/permission checks on every Admin2 API route;
- never execute generated PHP, shell, JavaScript, template code, or arbitrary
  tool calls; and
- treat prompt injection and malicious model output as expected input, not an
  exceptional case.

## Batch and site-wide workflows

Batch operations are query plus proposal pipelines, not loops that directly
save every page. A workflow captures its selection rule and immutable target
list, excludes unpublished/protected content the actor cannot read, enforces a
maximum target and budget, creates per-target results, and presents a summary
before apply. Resume is idempotent. Cancellation and partial failure are
visible. Applying a batch rechecks permissions and source versions for every
item.

Early named workflows may audit missing descriptions, propose page summaries,
or review frontmatter consistency. Broad page moves, deletes, plugin installs,
theme changes, and account/configuration administration stay with Grav MCP/API
and their native permission systems.

## Versioned milestones

### 0.1.0 — contract foundation (implemented)

- runnable, independently packageable `plugins/grav-jarvis` metadata and entry
  point;
- public service/provider/registry interfaces and immutable completion request,
  result, and provider-declared usage values;
- `$grav['gravJarvis']`, `onJarvisProviderRegister`, duplicate/missing-provider
  denial, and a deterministic fake provider that is never registered in
  production;
- typed and normalized provider failures, credential-key rejection, and
  redaction of environment secrets, authorization text, successful output, and
  result metadata; and
- executable contracts proving service/event registration, stable fake output,
  provider failure, missing/disabled/invalid-service fallback, and redaction.

There is deliberately no live provider, network request, Admin2 UI, content
mutation, Commander integration, background job, or MCP workflow in 0.1.0.

### 0.1.1 — provider boundary (implemented)

- additive validation/model-discovery provider contracts and an introspection
  service extension without changing the 0.1.0 service, provider, or registry;
- immutable provider-neutral validation issue/result and model descriptor/
  catalog values with deterministic ordering and no raw provider shapes;
- provider-scoped environment credential resolvers, non-serializable in-memory
  credential values, safe authentication prefixing, and typed missing,
  malformed, and cross-provider errors;
- sanitized HTTP request/response/transport contracts, deterministic fixture
  matching with no network fallback, and a reference offline conformance
  provider; and
- nineteen passing compatibility/conformance checks covering successful and
  failed validation/discovery, credentials, malformed configuration/responses,
  authentication, rate limiting, HTTP/transport failures, redaction, absence,
  and deterministic output.

### 0.1.2 — first live OpenAI provider (implemented)

- bounded production HTTP applies exact HTTPS origin/base-path allowlists,
  rejects literal/private/reserved destinations, validates every resolved
  address, pins the chosen address against DNS rebinding, verifies TLS,
  disables redirects and environment proxies, rejects transport-controlled
  headers, and limits connect time, total time, request bytes, response-header
  bytes, and decompressed response bytes;
- the official `openai` adapter maps `GET /models` and `POST /responses`
  entirely inside `Grav\Plugin\GravJarvis\Provider\OpenAI`;
- validation performs model discovery rather than content generation, model
  records normalize into `ModelCatalog`, and output plus provider-reported
  token usage normalize into the unchanged completion result/usage contract;
- the adapter uses only `GRAV_JARVIS_OPENAI_API_KEY`, defaults to
  `gpt-5.6-luna`, honors the neutral request model override, sets remote storage
  off, rejects unsupported options, and exports no vendor response metadata;
- plugin boot registers the provider but neither resolves the credential nor
  makes a request; missing/malformed credentials and configuration degrade
  through the existing validation/failure model; and
- deterministic fixtures cover success, usage, malformed/empty data,
  authentication, rate limits, other 4xx/5xx responses, timeouts, transport
  failure, destination policy, offline behavior, and redaction. Live account
  smoke remains optional and was not needed for the release gate.

Version 0.1.4 additively teaches the OpenAI adapter the provider-neutral
`max_output_units` bound for the live-smoke path; the 0.1.2 default request
shape and all shared contracts remain unchanged.

### 0.1.3 — generic OpenAI-compatible provider (implemented)

- created a provider separate from the official `openai` adapter, not a mode or
  configurable endpoint on that provider;
- requires an operator-approved public HTTPS base URI at provider construction,
  use the 0.1.2 bounded transport, and permit neither per-request endpoint
  overrides nor private/local destinations in this checkpoint;
- supports environment-only credentials through provider-scoped configurable
  environment-variable names without accepting secret values in configuration;
- declares only implemented text completion, provider validation, and optional
  model discovery; streaming, structured output, and tool calling remain
  deliberately absent rather than being inferred from a product label;
- documents the exact Responses request/response subset and fails gracefully when
  model discovery is absent or a response is only partially compatible; and
- includes deterministic full-compatibility, multi-instance, no-discovery,
  missing-endpoint, Chat Completions-only, malformed/partial response,
  rate-limit, cross-credential, and private-destination fixtures.

### 0.1.4 — Anthropic provider (implemented)

- added the second first-party wire family behind byte-identical frozen public
  interfaces using the fixed official API base, `x-api-key`, required
  [API version](https://platform.claude.com/docs/en/api/versioning),
  [Models discovery](https://platform.claude.com/docs/en/api/models), and
  [Messages generation](https://platform.claude.com/docs/en/api/http/messages/create);
- normalized ordered text blocks, model IDs/labels, capability truth, and
  provider-reported input/output usage while keeping message fields, content
  blocks, stop details, model capability objects, errors, and headers private;
- reused environment-only `GRAV_JARVIS_ANTHROPIC_API_KEY` resolution and the
  bounded HTTP transport, with typed auth, rate, other 4xx, 5xx/overload,
  malformed-response, configuration, and transport failures;
- added provider-neutral `max_output_units` mapping inside both first-party
  adapters so an opt-in live smoke has a hard generation bound without shared
  vendor vocabulary; and
- added ten deterministic Anthropic checks plus an OpenAI/Anthropic live-smoke
  harness that is off by default and cleanly skips when no credential exists;
  and
- packaged `dist/grav-jarvis-0.1.4.zip`, SHA-256
  `242fef895caaf6ea926a17ad0e711f7801d2f39e0779bf67db487e3c82d02a8b`.

No live provider request was required for 0.1.4. Gemini, OpenRouter, CLI,
streaming, tools, structured output, jobs, MCP, Commander, and mutations remain
deferred.

### 0.2.0 — first Admin2 usability slice (implemented)

- added a permission-filtered Jarvis sidebar page and native page-editor context
  panel with provider/model selectors, validation/status, prompt/response,
  loading/error/retry, and safe missing-credential/unavailable states;
- added Rewrite, Proofread, Shorten, Expand, Summarize, and Custom Prompt through
  a versioned provider-neutral internal prompt library;
- bounded current unsaved Markdown to 49,152 bytes, frontmatter to 8,192 encoded
  bytes, media metadata to 4,096 encoded bytes/32 items, and reviewable output
  to 65,536 bytes; secret keys and known environment-secret values are redacted;
- used Admin2's public current-buffer/replace-buffer events and a robust before/
  after preview. Reject is non-mutating; Accept requires a separate permission
  and updates only the unsaved buffer, never save/publish;
- added 15-minute one-time hash-only receipts with actor, route, current-source,
  proposal, expiry, page ACL, and API-scope rechecks. Truncated/oversize work is
  preview-only and stale/replayed receipts fail closed;
- preserved all six frozen public interface files byte-for-byte and added no
  provider-specific concept to shared contracts; and
- added seven deterministic backend and seven browser-component checks plus
  authenticated DDEV 200/401/403 route and Admin2 asset checks without a live
  provider call.

The packaged release is `dist/grav-jarvis-0.2.0.zip`, SHA-256
`1ef863da43c0e0038764f7564e72a77175add1a5c8bce89526c01318817455ae`.

### 0.2.1 — Admin2 hardening (implemented)

- added a repeatable full signed-in Admin2 browser regression backed by a
  deterministic test-only server provider. It covers the sidebar, page panel,
  all six actions, missing/unavailable/rate-limit states, retry, stale conflict,
  Reject, Accept once, reload non-persistence, keyboard use, and narrow/light/
  dark layouts;
- hardened random one-time receipts with deterministic expiry/capacity cleanup,
  actor/route/source/proposal binding, explicit Reject, successful replacement
  revocation, and cross-user/cross-page/stale/replay denial;
- classified safe provider failures without exposing provider bodies, stack
  traces, credentials, authorization headers, endpoint authority, or vendor
  response structures to Admin2;
- refined semantic labels/status announcements, focus behavior, disabled state,
  non-color-only before/proposed context, responsive wrapping, and inherited
  Admin2 theme variables;
- retained unaccepted proposals on retryable failure and preserved the unsaved
  buffer on every failure path. Accept still dispatches only the public replace-
  buffer event and never saves or publishes; and
- left optional non-secret provider/model preferences unimplemented to avoid a
  new persistence surface. Whole-buffer proposals remain explicit because
  Admin2 still exposes no stable public selection/form mutation contract.

The packaged release is `dist/grav-jarvis-0.2.1.zip`, SHA-256
`6c956919d47a2af029b8b37700bbfa4fc051948f4d7bfc31ab37150ad3e73de9`.

### 0.3.0 — reliability and large context (implemented)

- added `ReliabilityServiceInterface` as an optional additive extension of the
  frozen provider/introspection service. `ReliableJarvisService` decorates the
  existing provider boundary; original `complete()` behavior and every frozen
  0.1.x interface file remain unchanged;
- added transient-only retry for normalized retryable failures, with three/five-
  second default attempt/time bounds, exponential backoff, bounded jitter,
  normalized retry-after, deterministic runtime fixtures, and retry diagnostics.
  Credential, configuration, authentication, and other non-retryable failures
  receive one attempt;
- added a disabled-by-default response-cache contract, deterministic in-memory
  fake, and owner-only transient file implementation. SHA-256 keys cover the
  canonical request and hashed site/actor/page context plus action/provider/
  model. Named transformation actions are eligible; custom/general prompts and
  every failure are not. Entries carry only redacted successful results, scope
  hash, issue/expiry, and are TTL/capacity bounded;
- added `UsageReport` with truthful nullable provider-reported input/output/
  total/cache usage, provider/model/unit, provider request count, retry count,
  and cache-hit state. Unknown values remain `null`;
- added versioned operator-supplied pricing catalogs and fixed-point
  nanocurrency arithmetic. Input/output/cache rates remain separate, pricing is
  never fetched at runtime, authoritative provider cost remains separate/null,
  and unknown/stale data produces an explicit unknown estimate. Retry cost is
  shown as a conservative amplified upper estimate;
- added disabled-by-default operation-scoped budgets for request/retry count,
  input bytes, known output units, and known request/operation estimated cost.
  Known excesses raise `BudgetExceededException` before the next provider call;
  unknown pricing/usage is disclosed and never falsely enforced;
- added deterministic Grav/Markdown chunking at atomic frontmatter, paragraph/
  list, and fenced-code blocks, with source SHA-256/byte provenance, ordered
  chunks, and explicit size/total/count/synthesis bounds. 0.3.0 implements only
  chunk-summary plus one bounded final synthesis. Truncated sources and unsafe
  rewrite/proofread reconstruction fail or remain deferred;
- added concise Admin2 usage, estimated-cost, request/retry, cache-hit, and
  budget-blocked presentation without changing receipt, page authorization, or
  unsaved-buffer-only Accept behavior; and
- added deterministic reliability/chunking suites and updated the signed-in
  browser fixture to prove automatic recovery from one transient rate limit.

Version 0.3.0 intentionally adds no background job, durable request/history or
accounting store, proposal persistence change, Revision Ledger coupling,
Commander integration, MCP workflow, retrieval/vector layer, recursive
expansion, site crawl, provider family, tool call, streaming, or automatic
content mutation. Cache retention is bounded by configured TTL/capacity;
operation accounting is in-memory only; no external telemetry exists.

The packaged release is `dist/grav-jarvis-0.3.0.zip`, SHA-256
`5fff5ea5f10061c3fe95675f0732d20ce7ba0b6eb3623dbf22c6fe3aa452b158`.

### 0.4.0 — agent and site-wide workflows

- batch selection, per-item proposals, selected apply, resumability, and
  partial-failure reports;
- permission-checked REST endpoints suitable for MCP/agent composition;
- content/metadata audits and other bounded named workflows;
- Gemini and OpenRouter adapters if provider-contract evidence supports them;
  and
- optional Grav Commander and suite integrations implemented against the
  stable public contract.

### 1.0.0 — supported public platform

- documented compatibility/deprecation policy and migration path;
- stable provider, prompt, proposal, event, and consumer contracts;
- complete security review and black-box release matrix;
- install/upgrade/package evidence on supported Grav/PHP versions; and
- operator documentation for privacy, costs, recovery, and incident response.

## Testing strategy

Tests are layered and use fake credentials/providers by default. The 0.1.0
compatibility, 0.1.1 provider-boundary, 0.1.2 bounded-transport/OpenAI,
0.1.3 compatible-provider, 0.1.4 Anthropic, 0.2.0/0.2.1 Admin, and 0.3.0
reliability/chunking contracts run together with
`./scripts/test-grav-jarvis-contract.sh`; the runner uses host PHP when
available and otherwise the canonical DDEV fixture (or an explicitly selected
DDEV project).

`./scripts/test-grav-jarvis-admin-ui.sh` provides the deterministic isolated
component contract. `./scripts/test-grav-jarvis-admin-browser.sh` is the 0.2.1
black-box gate: it temporarily installs the test provider, creates a random
Admin2 account, runs actual authenticated page and editor interactions through
Chrome/Chromium, scans browser/backend failures, then restores the previous
plugin, account-index, notification, and cache state.

The continuing strategy is:

1. unit tests for request normalization, capability negotiation, prompt
   rendering, redaction, cache keys, retry classification, chunking, budgets,
   diffs, and proposal state transitions;
2. provider contract tests against deterministic HTTP fixtures for success,
   streaming, usage, rate limits, timeouts, malformed payloads, and redaction;
3. consumer contract tests for container discovery, missing-plugin fallback,
   registration events, and public DTO compatibility;
4. DDEV integration tests for Grav API permissions, Admin2 context, page/media
   extraction, Revision Ledger optionality, CLI, jobs, and packaging; and
5. external black-box tests proving unauthorized denial, SSE/stream behavior,
   preview causes no mutation, approval applies exactly once, stale proposals
   conflict, batch partial failures are truthful, and secrets never cross the
   browser/log/export boundary.

Live-provider smoke tests are opt-in, budget-capped, and never a routine release
prerequisite. No production content mutation or production credential is
required for regression testing.

Versions 0.2.0 through 0.3.0 also run `./scripts/test-grav-jarvis-admin-ui.sh`. Its isolated
component/event harness proves authenticated same-API browser transport,
provider-neutral requests, response escaping, graceful absence, current
unsaved-buffer capture, explicit replace-only Accept, non-mutating Reject, and
no save/publish or direct-provider path. DDEV adds authenticated 200, anonymous
401, limited-scope 403, provider misconfiguration, current-page context,
page/panel asset, and context-panel registration evidence. A repeatable full
signed-in visual browser flow with the deterministic provider is the 0.2.1
follow-up.

## Compatibility philosophy

Jarvis targets supported Grav 2, the Grav API plugin, Admin2, and the PHP
version required by that stack. It uses documented `onApi*` events, controllers,
web components, Grav services, and MCP/API contracts. Capability detection is
preferred over version guessing. Grav 1.7 and classic Admin compatibility are
not 0.x goals; provider-only operation outside Admin2 may be evaluated later
without weakening the Grav 2 architecture.

Public PHP and event contracts follow semantic versioning. Provider adapters
are replaceable. A changing provider API or model name must not require another
plugin to change its code. Every extension stays independently installable.
Versions 0.1.1 through 0.3.0 therefore leave `ProviderInterface`,
`JarvisServiceInterface`, and `ProviderRegistryInterface` unchanged and expose
introspection through a service subinterface and optional provider interfaces.

## Explicit non-goals

- copying, reverse-engineering, or matching Grav AI Pro release-for-release;
- replacing Grav REST API, Grav MCP, Admin2, Revision Ledger, Grav Commander,
  Page Studio, or provider dashboards/billing;
- training or hosting foundation models;
- a universal file manager, IDE, shell/code executor, or unrestricted tool
  runner;
- silent, autonomous, site-wide writes or approval chosen by model output;
- storing secrets in tracked YAML, browser storage, prompt history, or plugin
  packages;
- promising exact cost figures when provider pricing/usage is unavailable;
- image generation or binary-media analysis in the minimal release; and
- making any existing plugin depend on Jarvis for its core behavior.

## Canonical references and resume point

Durable rationale is in
[Decision 0004](../decisions/0004-grav-jarvis-agent-framework.md). The suite-level
status and exact next action are in [`CURRENT-STATE.md`](../../CURRENT-STATE.md).
Before live-provider or API implementation, re-check the current official
[Grav API developer guide](https://learn.getgrav.org/20/api/developer-guide),
[Grav MCP security model](https://learn.getgrav.org/20/advanced/mcp-server),
and each provider's official API documentation; those external interfaces can
change after this specification.
