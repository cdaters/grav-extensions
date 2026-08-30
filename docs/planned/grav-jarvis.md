# Jarvis

**Status:** accepted specification; no installable plugin exists yet  
**Product name:** Jarvis  
**Plugin slug and future directory:** `grav-jarvis`  
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

The public contract will normalize provider differences behind small PHP
interfaces and immutable request/result objects. The initial vocabulary is:

- `JarvisServiceInterface` for `complete()`, `stream()`, capability discovery,
  prompt resolution, proposal creation, and usage reporting;
- `ProviderInterface` for validation, model discovery, synchronous generation,
  and optional streaming;
- `ProviderRegistryInterface` plus `onJarvisProviderRegister` so companion
  plugins can add providers without editing Jarvis;
- `CompletionRequest`, `CompletionResult`, `StreamEvent`, `Usage`, and
  provider-neutral error categories; and
- explicit capabilities such as text input, image input, structured output,
  streaming, usage data, and model discovery.

The planned Grav container key is `$grav['gravJarvis']`. Consumers type-check the
public interface and degrade cleanly when the plugin or a requested capability
is absent. They never read Jarvis configuration, provider classes, caches,
jobs, or secrets directly.

Initial providers:

1. OpenAI;
2. Anthropic; and
3. OpenAI-compatible HTTP endpoints.

Gemini and OpenRouter follow after the contract is stable. OpenRouter may be an
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

Planned permissions are separate and default-deny:

- `grav-jarvis.use` — run permitted interactive prompts;
- `grav-jarvis.manage` — configure non-secret provider and prompt settings;
- `grav-jarvis.batch` — create bounded multi-item jobs; and
- `grav-jarvis.approve` — apply proposals through an allowed mutation path.

Exact `api.*`/legacy permission declarations will follow current Grav 2
guidance when the blueprint is implemented. Super-user bypass behavior remains
Grav's, not a plugin invention.

Provider secrets are server-only environment variables such as
`GRAV_JARVIS_OPENAI_API_KEY` and `GRAV_JARVIS_ANTHROPIC_API_KEY`.
Configuration stores an environment-variable name or credential reference,
never a copied secret. Secrets never enter Admin2 JavaScript, API payloads,
prompts, logs, cache keys, job records, exported diagnostics, screenshots,
fixtures, or release ZIPs.

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

### 0.1.0 — minimal provider and proposal foundation

- public interfaces, immutable request/result/usage types, provider registry,
  and a deterministic fake provider for tests;
- OpenAI, Anthropic, and OpenAI-compatible adapters;
- environment-only credentials, provider validation, model discovery, timeout,
  and normalized errors;
- synchronous `chat`, initial streaming, and CLI `validate`, `models`, `chat`;
- versioned prompt library and bounded page/frontmatter/media context;
- Admin2 page context panel with preview/diff/accept/reject; and
- first optional consumer example or contract fixture, without coupling
  Commander to unreleased internals.

The first coding checkpoint inside 0.1.0 is smaller: scaffold the installable
plugin, freeze interfaces/DTOs and registration names, add the fake provider,
and prove service discovery plus absence/failure behavior. Network providers
and Admin2 follow only after that contract is reviewed.

### 0.2.0 — reliability and automation

- bounded retries, privacy-safe response caching, token/cost reporting, rate
  and budget controls;
- Grav-aware chunking and synthesis;
- durable background jobs, cancellation, Scheduler/CLI worker, and progress;
- proposal persistence, Revision Ledger checkpoints, conflict handling, and
  structured frontmatter/media-metadata proposals; and
- stable plugin-facing PHP API with compatibility tests.

### 0.3.0 — agent and site-wide workflows

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

Tests are layered and use fake credentials/providers by default:

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
Before implementation, re-check the current official
[Grav API developer guide](https://learn.getgrav.org/20/api/developer-guide),
[Grav MCP security model](https://learn.getgrav.org/20/advanced/mcp-server),
and each provider's official API documentation; those external interfaces can
change after this specification.
