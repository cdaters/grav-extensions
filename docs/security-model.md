# Shared security model

The extensions follow minimum authority and enforce protection at the resource
delivery or mutation boundary—not merely in the browser interface.

## Protected delivery

File Vault and Prism Gallery use separate implementations with a shared contract:

- opaque public identifiers;
- short-lived, tamper-evident same-origin URLs;
- delivery-time authorization;
- no protected filesystem path in page markup or token payload;
- restrictive cache, sniffing, indexing, and referrer headers;
- byte-range support where media/download clients require it.

This prevents stable direct links and casual hotlinking. It is not DRM: a person
authorized to receive browser-visible bytes can ultimately save them.

## Secrets and personal data

Signing keys, API tokens, passwords, raw/full IP addresses, user agents, and
protected files are runtime data. They never belong in plugin defaults, source
control, example screenshots, fixtures, or release ZIPs.

Analytics are opt-in. Use the least identifying mode, shortest useful retention,
restricted Admin permissions, and an accurate site privacy notice.

## Destructive operations

File mutation, extraction, restore, derivative replacement, and batch editing
require explicit scope, path validation, and recoverability. A web request must
not erase the running site before a staged replacement has been validated.

Site Safeguard applies this boundary to recovery packages: archive structure is
validated before deep hashing, complete packages are extracted only to a unique
directory outside the running Grav root, and extracted files are hashed again.
Version 0.1 deliberately exposes no live-promotion operation.

## Source-faithful editing

Caxton treats page source, Markdown extensions, HTML, Twig, shortcodes, media
metadata, pasted content, extension descriptors, and future AI proposals as
untrusted. The authoritative value is the bounded source buffer, not rendered
HTML or an editor-engine document.

- No-edit parse/serialization must return exact bytes. A visual edit is allowed
  only for a source span explicitly classified as safe and is applied against
  the expected document SHA-256; every byte outside the edited span remains
  unchanged.
- Unknown, ambiguous, malformed, raw HTML, Twig, shortcode, and executable-
  looking content is inert and opaque. The editor does not execute site Twig or
  shortcode rendering merely to display it, and does not insert untrusted raw
  HTML into its chrome.
- Parsing and editing are bounded by source bytes, replacement bytes, block
  coverage, and deterministic failure. NUL and oversized input fail without
  including source content in exceptions or logs.
- Normal Grav page permissions and Save/Publish remain authoritative. Caxton
  0.1.0 has no HTTP route, page-write endpoint, autosave, hidden page copy,
  preview renderer, network client, provider credential, or executable client
  extension.
- Trusted installed plugins may register namespaced public descriptors, but
  registration grants no implicit page, filesystem, rendering, network, or
  write authority. Missing extensions leave ordinary source intact.
- Future Admin2, preview, media, collaboration, and Jarvis paths require their
  own permission, sanitization, stale-source, and external black-box evidence
  before those boundaries ship.

Caxton 0.1.1 keeps its browser proof private and offline. ProseMirror and
CodeMirror are views over the canonical source string, not persistence formats.
Raw HTML, script/on-handler content, Twig, shortcodes, tables, mixed source,
unsafe URLs, and malformed or over-nested input become inert text-only opaque
cards; media nodes never fetch their source. Scheme allowlists, own-property
records, NUL/source/block/nesting/replacement bounds, source identities, and
conservative selection failure close the proof boundary. Browser selection
offsets are UTF-16 source units while frozen PHP offsets are bytes; a future
Admin2 wrapper must convert explicitly against the same source/hash and never
mix those units. No Caxton asset is registered or loaded by PHP in 0.1.1.

The complete trust and preservation contract is in
[`docs/planned/grav-caxton.md`](planned/grav-caxton.md) and Decision 0005.

## AI providers and agent workflows

Jarvis treats model providers, model output, page/media context, and MCP-
retrieved material as separate trust boundaries.

- Provider credentials are server-only environment variables. They never enter
  tracked YAML, Admin2 JavaScript, prompts, logs, caches, jobs, diagnostics,
  fixtures, or packages.
- A request discloses its destination provider and bounded context. Provider
  endpoints are validated; redirects cannot change origin, and local/private
  OpenAI-compatible endpoints require explicit opt-in.
- Untrusted content cannot become system instructions, grant a tool, expand a
  target set, execute generated code, or approve a change.
- Model work is limited by permission, rate, token, cost, context-size, and job
  budgets. Conversation/cache/job records are access-scoped and have explicit
  retention.
- Content and configuration output remains a non-mutating proposal until an
  authorized user reviews a diff and approves it. Apply rechecks permission and
  source version; stale proposals fail closed.
- External agents use a dedicated least-privilege Grav API user through the
  native REST/MCP permission model. Provider keys and Grav API keys are never
  interchangeable.

The full boundary is specified in
[`docs/planned/grav-jarvis.md`](planned/grav-jarvis.md).

Jarvis 0.1.0 implements the first portion of this boundary: request options and
metadata reject credential keys; `GRAV_JARVIS_*` environment values are loaded
only into an in-memory redactor; provider failures are converted to typed
exceptions without chaining the unsafe original; and successful provider
output/metadata is redacted before it crosses the public service. The release
contains no live provider, remote endpoint, API route, UI, persistent history,
or mutation path.

Jarvis 0.1.1 adds provider-scoped environment resolvers and in-memory
credential values that cannot be serialized and redact their debug form.
Provider namespaces prevent an accidental cross-provider lookup. Ordinary HTTP
header maps reject credential-like headers; only credential value objects may
populate the separate transport-only credential channel, while request
fingerprints and fixture history contain redaction markers. Raw HTTP responses
also refuse serialization. The release still contains no network transport,
live provider, persisted provider secret, API route, UI, or mutation path.

Jarvis 0.1.2 adds the first production network boundary without weakening the
credential model. Its HTTP transport accepts only configured HTTPS origin/base-
path destinations; rejects literal, private, loopback, link-local, reserved,
or mixed public/private DNS results; pins a validated address to prevent DNS
rebinding; verifies TLS; disables redirects and environment proxy inheritance;
rejects caller-controlled host/proxy/framing headers; and applies explicit
connect, total-time, request-body, response-header, and decompressed-response
limits. Diagnostics never include request/response bodies, authorization
values, or provider error details.

The official OpenAI adapter is the only live provider in 0.1.2. It uses the
fixed official API base and `GRAV_JARVIS_OPENAI_API_KEY`; no configurable
endpoint or secret field exists. Plugin boot registers the adapter but does not
resolve a credential or contact the provider. Raw OpenAI response/error fields
stay inside the adapter, remote response storage is disabled, and only
provider-neutral models, output, and provider-reported usage cross Jarvis's
redacted service boundary.

Jarvis 0.1.3 reuses that transport for compatible providers without turning
the official adapter into an arbitrary proxy. Each compatible instance has an
immutable operator-selected public HTTPS base URI, stable provider ID, and a
provider-scoped environment-variable name; secret values and per-request
endpoints are impossible configuration fields. Private/local destinations stay
blocked. Compatibility is an explicit Responses text subset, declared model
discovery may be disabled, and the adapter claims no streaming, structured-
output, or tool-calling capability it does not implement. Partial and Chat
Completions-only responses fail closed.

Jarvis 0.1.4 adds Anthropic as a genuinely different first-party wire family
without changing the shared interfaces. The adapter uses only the fixed
official HTTPS base, required version header, Models and Messages endpoints,
and environment-only `GRAV_JARVIS_ANTHROPIC_API_KEY`. The key enters only the
transport's credential-header channel; message bodies, content blocks, model
capability objects, stop details, raw errors, and request IDs remain adapter-
private. Bounded non-secret cursor queries may be used only on the already
allowlisted provider path; credential query keys remain rejected. Multiple
text blocks and provider-reported usage normalize to shared
DTOs; empty, malformed, incomplete, tool-oriented, or refusal-style responses
fail closed. The opt-in live harness hard-bounds output, prints no prompt or
response, and cleanly skips without a configured credential.

Jarvis 0.2.0 adds an authenticated Admin2/API boundary without granting the
model or browser page authority. `grav-jarvis.access`, `grav-jarvis.use`, and
`grav-jarvis.approve` are independently enforced server-side; page context and
acceptance additionally pass through Grav's page ACL, API-key-scope, demo, and
`api.pages.read`/`api.pages.write` checks. The browser supplies only provider
ID, model ID, action, user instruction, and current editor state to fixed
Jarvis routes. It cannot supply provider classes, URLs, headers, paths, or
environment-variable names and uses Admin2's API token with browser credentials
omitted.

Current-page context is deterministic and minimal: 49,152 content bytes,
8,192 encoded frontmatter bytes, and 4,096 encoded media-metadata bytes/32
items. Media bytes and paths never enter the envelope. Credential-like keys and
known `GRAV_JARVIS_*` values are redacted before provider submission. A
content-truncated or over-65,536-byte provider proposal is preview-only.

Acceptance uses a private 15-minute one-time cache receipt containing only
hashed actor, route, current source, and proposal plus expiry. No prompt, page
body, output, provider response, credential, or conversation history is
persisted. Accept rechecks Jarvis approval, page update authority, route,
current unsaved-buffer hash, proposal hash, expiry, and one-time consumption,
then dispatches only Admin2's replace-buffer event. Reject is non-mutating, and
no Jarvis endpoint or browser component saves, publishes, deletes, or executes
model output. Selection-aware editing is deferred rather than reading private
editor state.

Jarvis 0.2.1 makes receipt closure explicit and bounded. Reject consumes the
actor/route-bound receipt, and a successful regeneration consumes the receipt
it replaces; a failed regeneration preserves it. The private directory is
owner-only, each receipt and its coordination lock are owner-readable only,
active receipts are capped at 128, and deterministic expiry cleanup reads only
the non-secret receipt metadata. Cross-user, cross-page, stale, expired,
rejected, replaced, accepted, and replayed identifiers fail closed. Opaque
random identifiers disclose no actor, route, page, provider, or content data.

Admin-facing failures are mapped to stable safe categories for missing or
malformed credentials, invalid configuration, authentication, rate limit,
timeout, unsupported capability, invalid response, and unavailability. Raw
provider JSON, request/response bodies, stack traces, credentials,
Authorization headers, endpoints, and environment-variable authority never
cross the API boundary. Retry is presented only where the category is
transient. The deterministic signed-in browser regression deliberately tries
anonymous token omission and browser-supplied endpoint/environment authority;
both are denied without a provider request.

Jarvis 0.3.0 composes reliability outside the frozen provider adapters. Retry
accepts only normalized failures explicitly marked retryable, has attempt and
elapsed-time ceilings, caps jitter/retry-after delay, and reports only counts
and timing. Credential, configuration, and authentication failures are not
retried. An Admin proposal is created only after one logical completion
succeeds, so internal attempts cannot duplicate a proposal receipt or Accept.

Response caching is disabled by default. When enabled, eligibility is limited
to configured named actions; general and custom prompts bypass it. The key is a
SHA-256 digest of canonical request data plus hashed installation, actor, page/
context, action, provider, and model scope, so filenames reveal no raw prompt,
page, username, or route. Owner-only transient records hold only a redacted
successful `CompletionResult`, scope hash, issue time, and expiry. Failures are
never cached. TTL, capacity, deterministic cleanup, and fail-open cache errors
bound availability and retention; cache data is separate from environment-only
credentials. Operators enabling caching must still treat generated output as
private site data and protect the Grav cache directory accordingly.

Usage and cost accounting is in-memory for the logical operation. Unknown
provider usage or pricing remains null/unknown rather than zero. Pricing is
operator-supplied versioned metadata, never fetched from the network, and cost
is labeled estimated rather than authoritative. Opt-in budgets stop known
request/input/output/retry/cost excesses before the next provider call. Unknown
cost is disclosed and cannot be claimed as enforced. Admin2 receives only
normalized counts, estimate metadata, hashed diagnostics, and safe budget codes.

The Markdown chunker reads only explicitly supplied content. It treats YAML
frontmatter and fenced code as indivisible, preserves source SHA-256 and byte
offsets, and fails clearly when size/count/synthesis bounds cannot preserve the
source. Chunked execution is summarize-only in 0.3.0; there is no retrieval,
site crawl, RAG/vector index, recursive expansion, autonomous rewrite, job
queue, durable request/history store, behavioral telemetry, or external
telemetry.

Jarvis 0.3.1 proves the optional-consumer boundary without changing Jarvis
code. Grav Commander 0.3.12 checks for the public service/contracts at runtime
and retains all file authority. The server re-resolves the configured root and
relative path through Commander's contained file service, then enforces both
Commander operation permission and `grav-jarvis.use`. The browser cannot name
a provider class, endpoint, header, credential, environment variable, path
outside Commander roots, or reliability policy.

Commander permits only one current eligible text/source buffer. `.env`,
credential/account/secret/private-key locations, private-key bodies,
unsupported extensions, and binary content fail closed. High-confidence
credential assignments and bearer/token values are replaced with
`[REDACTED]`; a redacted or truncated transformation cannot be applied. This is
a bounded disclosure guardrail rather than a perfect secret scanner, so the
visible context and provider choice still require human review.

Read-only results never receive an application receipt. Safe editable Improve
or Custom results receive a private 15-minute receipt containing only hashes
and expiry. It binds actor, root/path, disk modified/size version, source, and
proposal and is consumed by Apply or Reject. Apply rechecks
`grav-commander.write` and `grav-jarvis.use`, current file editability/version,
current buffer hash, proposal hash, actor/path, expiry, and one-time use. It
returns text to the unsaved textarea and never invokes Commander's write route.
The ordinary Save action remains a separate explicit persistence step. Its
pre-existing lack of a general optimistic-concurrency token is documented for
Commander 0.3.13 hardening rather than hidden by the AI integration.

Report vulnerabilities using the root [security policy](../SECURITY.md).
