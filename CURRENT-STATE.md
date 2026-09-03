# Grav Extensions current state

This is the canonical short-form project handoff. It records the active state,
not the full history or future roadmap.

## Repository

- **Local integration checkout:** `~/Code/grav-extensions`
- **Remote:** `origin` → `https://github.com/cdaters/grav-extensions.git`
- **GitHub visibility:** private (verified 2026-08-22)
- **Integration branch:** `main`
- **Primary Grav/DDEV fixture:**
  `~/Documents/Spitfire/custom-plugins/file-vault-ddev`
- **Fixture URL:** `https://spitfire-file-vault.ddev.site`

The Git remote is the off-machine recovery copy for tracked source,
documentation, tests, decisions, and history. Runtime packages, stages, site
content/configuration, protected File Vault binaries, credentials, and DDEV
volumes require their own protected backups.

## Last completed checkpoint

Jarvis 0.3.3 is complete as the encrypted credential-usability and host-
readiness release. The audited 0.3.2 implementation already had strong provider
introspection, official setup guidance, environment-only credentials, fixed
official endpoints, bounded HTTPS/SSRF controls, non-generating validation,
model discovery/default retention, and useful non-secret Admin2 settings. Its
real usability gaps were the lack of a safe beginner key-entry path, no host-
capability explanation, and a Plugin settings link buried below the page header.

Admin2 now provides a permission-filtered top-right **Settings** shortcut,
write-only OpenAI/Anthropic key fields, **Save & Validate**, Replace/Remove,
effective credential source/backend, environment-override explanation, and a
concise required/recommended/optional/fallback readiness panel. A key is sent
only in its initial authenticated write request, immediately cleared from the
form, and never returned in bootstrap, status, validation, model discovery,
errors, JavaScript state, ordinary YAML, or later browser traffic. Storage
success remains distinct from remote rejection so an invalid key can be
replaced, retried, or removed without silent loss.

The frozen credential resolver contract remains unchanged. OpenAI and
Anthropic now use a private composite resolver with exact priority: direct
provider environment credential, encrypted local provider credential, then
missing. A malformed higher-priority environment value fails closed. Compatible
providers remain environment-only. Non-secret preferred/default provider/model,
compatible-instance, selected reliability/cache/budget/chunking, and versioned
pricing controls remain ordinary validated Grav configuration.

Encrypted records live only under `user/data/grav-jarvis/credentials`, separate
from plugin YAML and packages. New writes prefer native Sodium XChaCha20-
Poly1305 and fall back only to OpenSSL AES-256-GCM authenticated encryption.
Hosts with neither backend remain fully usable with environment credentials;
Jarvis implements no plaintext compatibility store. Records are versioned and
bind provider identity, backend, key source, nonce/IV, authenticated metadata,
and ciphertext. Tampering, malformed/unknown versions, wrong/missing keys,
unavailable named backends, symlinked paths, and unsafe/unwritable storage fail
closed without regeneration or downgrade.

First Admin use atomically creates a separate random 32-byte local master key
with `0700` directory and `0600` file hardening. Advanced operators may instead
set `GRAV_JARVIS_MASTER_KEY=base64:<exactly-32-random-bytes>`; arbitrary
passwords, truncation, and padding are rejected. The external key is preferred
for new records, while existing records retain their explicit local/external
source. The local-key threat model is honest: compromise of the runtime account
that exposes both key and ciphertext can recover credentials. It primarily
protects against normal config export/Git/YAML disclosure and isolated
ciphertext theft.

Migration is explicit in 0.3.3. Replacing a provider key writes it with the best
current backend/key source; untouched records retain their named backend and
source. Environment removal reveals a retained local record. Whole-store,
rollback-safe master-key rotation is deliberately deferred to Jarvis 0.3.4
rather than risking partial multi-record re-encryption.

The canonical DDEV advanced workflow remains the fixture's already-ignored
`.ddev/config.local.yaml` plus `web_environment`, `ddev restart`, and a
presence-only `ddev exec` check. Production may use hosting secrets,
service/container environment, or protected PHP-FPM variables. Environment
credentials retain operational priority. No OpenAI or Anthropic key was present
for this release, so the opt-in live smoke was skipped and no provider call or
charge occurred.

```yaml
# .ddev/config.local.yaml
web_environment:
  - GRAV_JARVIS_OPENAI_API_KEY=sk-REPLACE-ME
```

```bash
ddev restart
ddev exec bash -lc 'test -n "$GRAV_JARVIS_OPENAI_API_KEY" && echo configured || echo missing'
```

Production must set the same exact variable in the web PHP service, for example
`env[GRAV_JARVIS_OPENAI_API_KEY] = sk-REPLACE-ME` in a protected PHP-FPM pool,
then reload PHP-FPM. Shell-only exports do not update an already-running web
process.

OpenAI guidance now sends operators to the API platform/project/key pages,
explains that ChatGPT login/subscription billing is separate and supplies no API
credential, recommends (but does not require) a dedicated Jarvis project/key,
and identifies the adapter's actual practical restricted-key needs: Models read
for `GET /v1/models` and Responses write for `POST /v1/responses`. Jarvis never
uses ChatGPT cookies, browser sessions, OAuth state, local storage, or
subscription credentials. The traveling README also covers Anthropic, billing,
rotation/removal, production PHP, DDEV, validation, model choice, and categorized
troubleshooting.

Caxton 0.3.0 is complete as the visual-rhythm, authoring, and optional Jarvis
proposal release for the suite's modern Grav 2 editor. The previous **Page
Studio** name remains a superseded recovery link only. Decision 0005 still makes
ordinary page source authoritative; all fourteen public 0.1.0 PHP contract
files remain byte-identical and protected by the checked-in SHA-256 baseline.
The unchanged foundation registers `$grav['gravCaxton']`, the public source/
edit/extension contracts, exact bounded parser/serializer, namespaced registry,
and `onCaxtonExtensionRegister`.

Version 0.3.0 absorbs every committed 0.2.2 authoring requirement because the
new optional intelligence boundary is materially larger than a patch release.
The Admin2 field now reads the current page-media inventory through Admin2's
public bridge, inserts/edits safe image references, edits inline/reference
links, inserts horizontal rules, chooses code-fence languages, and supports
multi-item list Enter behavior. It adds no upload or page-write route. Unknown,
unsafe, HTML, Twig, shortcode, table, malformed, and ambiguous source remains
an exact inert text card.

Visual mode owns scoped presentation beneath its ProseMirror surface rather
than inheriting Admin2 resets: paragraphs use 1rem trailing space; headings use
1.65em above/.55em below; lists, blockquotes, code blocks, horizontal rules,
media, and protected cards have deliberate surrounding rhythm; list items stay
compact at .25rem; and only outer first/last margins are removed. Explicit
light/dark variables preserve contrast, markers, selection, focus, quote/code/
card boundaries, and toolbar/dialog state. Mounting, theme changes, and resize
produce no source mutation, serialization, `change`, selection remapping, or
false dirty state. Signed-in Chrome measures all required mixed-block gaps in
both themes under Admin2 reset styles.

Caxton now offers optional Jarvis actions without changing Jarvis 0.3.3 or
declaring a dependency. The server resolves `$grav['gravJarvis']` only after its
public contracts exist; Caxton contains no provider adapter, credential,
endpoint, retry, cache, pricing, budget, background-job, batch, or MCP logic.
Rewrite, Proofread, Shorten, Expand, Explain, Summarize, and Custom Prompt use
the current selection first, otherwise its safe semantic block, plus bounded
context. The same authoritative source/SHA-256 and UTF-16↔UTF-8 byte map support
Visual and Source mode. Protected/opaque spans are never targets; code permits
read-only Explain/Summarize or an explicit Custom Prompt only.

The proposal dialog renders Original and Proposed as text, reports provider,
model, usage, and estimated cost, and requires Reject or Accept. Reject consumes
the receipt and leaves content unchanged. Accept rechecks Caxton/Jarvis/page
permissions and a hash-only 15-minute one-time receipt bound to actor, route,
source, byte range, target, proposal, action, and mutability, then changes only
the current unsaved buffer. Cross-user/page, stale, expired, replayed, malformed,
oversized, unknown-provider, authentication, rate-limit, timeout, budget, and
unavailable paths fail closed with redacted categories. Accepted content is one-
step undoable/redoable while the buffer matches. Only ordinary Admin2 Save/
Publish persists a page.

`grav-caxton.use` controls the field, `grav-caxton.source` Source mode,
`grav-jarvis.use` optional proposals, and `grav-jarvis.approve` Accept. Proposal
routes additionally enforce page read/update authority. Missing, disabled,
misconfigured, credential-less, provider-less, or failed Jarvis hides or fails
only the compact optional control; all ordinary Caxton editing remains intact.
The package includes no Jarvis source. Underline remains excluded because
portable Markdown has no underline syntax; existing `<u>` stays exact/inert.

The clean-room Editor Pro 2.0.10 behavior reference remains only at
`~/Downloads/editor-pro.zip`, SHA-256
`15617f2adbeb6012204507dcb8eff93d6accf2d59a4015cc7a977dd70ec6f0a8`.
No code or asset from it entered this repository. Public Grav documentation and
the supplied screenshots informed behavior only. TipTap, Milkdown, and Lexical
remain unselected. The complete 0.2.1 dependency/license/size, safe-subset,
mapping, performance, accessibility, limitation, and Admin2 boundary record
is `docs/caxton-editor-engine.md`.

Jarvis 0.3.1 remains complete as an optional consuming-plugin milestone through
Grav Commander 0.3.12; Jarvis 0.3.3 changes only private provider/security/Admin2,
configuration, documentation, and test surfaces. Commander has no Jarvis package
dependency and resolves `$grav['gravJarvis']` only after the public contracts
are available, so all existing Commander behavior survives a missing,
disabled, invalid, misconfigured, unavailable, capability-limited, or budget-
blocked Jarvis path.

Commander now offers bounded Explain, Summarize, Review, Improve / Rewrite,
and Custom Prompt actions for one eligible current text/source file. Commander
owns path containment, sensitivity filtering, context bounds, permissions,
and application authority; Jarvis owns provider validation/model discovery,
completion, retry/cache/budget/cost reporting, and safe Markdown summary
chunking. Credential locations, `.env`, account/secret/private-key paths,
private-key bodies, unsupported/binary files, and unsafe partial rewrites fail
closed. High-confidence credential assignments and bearer/token values are
redacted before context leaves Commander.

All actions require `grav-commander.browse` plus `grav-jarvis.use` on the
server. Improve, Custom Prompt, and Apply additionally require
`grav-commander.write`. A safe editable result receives a Commander-owned,
hash-only, 15-minute, one-time receipt bound to actor, root/path, disk modified/
size version, source, and proposal. Apply returns the proposal only to the
current unsaved textarea and never invokes a file-write route. Reject, expiry,
cross-user/file use, source or disk change, and replay fail closed. The normal
Commander Save action remains the only persistence boundary.

Jarvis 0.3.3 (`grav-jarvis`) retains the 0.3.0 reliability, cost-control, and
safe large-context infrastructure under Decision 0004 and adds the operator
setup experience described above.
The 0.1.0 `JarvisServiceInterface`,
`ProviderInterface`, and `ProviderRegistryInterface` files and every additive
0.1.1 validation, discovery, credential, HTTP, and DTO interface remain byte-
identical to the compatibility baseline.

`ReliableJarvisService` decorates the unchanged provider/introspection service
and is exposed through the additive `ReliabilityServiceInterface`. It provides
bounded retry only for normalized retryable failures (three attempts/five
seconds by default), exponential backoff with bounded deterministic jitter,
normalized retry-after, and safe count/timing diagnostics. Authentication,
credential, and configuration failures are never retried. One logical Admin
completion still creates at most one proposal/receipt, so internal attempts do
not duplicate review or Accept state.

The optional response cache is disabled by default. Eligible named actions use
SHA-256 canonical keys scoped to hashed installation/actor/page context plus
action/provider/model. General/custom prompts and failures bypass it. The
owner-only transient file cache holds only redacted successful results, scope
hash, issue/expiry, has a five-minute/128-entry default bound, and fails open to
the provider path. No request or raw prompt is stored as a key/value field.

`UsageReport` adds truthful nullable provider/model/unit/input/output/total/
cache usage plus request/retry/cache-hit state. `CostEstimator` reads only
operator-supplied versioned pricing, uses fixed-point nanocurrency arithmetic,
keeps input/output/cache rates separate, and distinguishes estimates from the
still-null authoritative billed amount. Unknown/stale pricing or usage remains
unknown. Disabled-by-default operation budgets can stop known request/retry,
input-byte, output-unit, per-request cost, or cumulative logical-operation cost
before the next provider call; unknown cost is disclosed, not falsely enforced.

`GravMarkdownChunker` preserves deterministic source SHA-256 and byte
provenance across YAML frontmatter, headings/paragraph/list blocks, and fenced
code. Size, total bytes, chunk count, and synthesis are bounded and fail
clearly. Version 0.3.0 executes chunks only for summarization: ordered partial
summaries feed one bounded synthesis. Rewrite/proofread reconstruction is
deferred rather than risking content integrity. Chunking sees only explicit
supplied content and has no crawl, retrieval, RAG/vector, or recursive path.

Admin2 now receives a permission-filtered Jarvis sidebar page and a native
`onApiContextPanels` page-editor panel. The main page provides provider/model
selection, safe validation/status, general prompt/response, usage, loading,
error, unavailable, and retry states. The page panel implements Rewrite,
Proofread, Shorten, Expand, Summarize, and Custom Prompt through the versioned
provider-neutral `ActionPromptLibrary`.

The panel reads the current unsaved Markdown through Admin2's public
`grav:editor:get-content`/`grav:editor:content-response` events. Jarvis builds a
deterministic envelope with route/title/template/language, parsed frontmatter,
49,152 content bytes, and at most 4,096 bytes/32 media metadata items;
frontmatter is capped at 8,192 encoded bytes. Credential-like keys and known
environment-secret values are redacted. No media bytes, filesystem paths,
unrelated pages, arbitrary endpoints, provider headers, or credential names
come from the browser.

Every edit is a proposal with before/after review. A reviewable proposal gets a
private 15-minute one-time receipt containing only actor/route/source/proposal
hashes and expiry. Accept requires `grav-jarvis.approve`, effective page update
authority, matching current unsaved-buffer/proposal hashes, and unused receipt;
it dispatches only Admin2's replace-buffer event. Reject changes no content and
explicitly revokes the receipt. Successful regeneration revokes the replaced
receipt; failed generation preserves the reviewed proposal. Active receipts
are capped at 128 and deterministic expiry cleanup keeps storage bounded.
Actor, route, source, proposal, expiry, rejection, replacement, and one-time
consumption all fail closed. Content-truncated or over-65,536-byte output is
preview-only. No Jarvis route or component saves, publishes, deletes, executes,
or persists prompt/page/output content.

Permissions are `grav-jarvis.access`, `grav-jarvis.use`, and
`grav-jarvis.approve`; page context/accept also use API plugin page read/write,
frontmatter ACL, API-key scope, demo, and super-user rules. Authenticated Grav
API requests use fixed routes and Admin2's API token with browser credentials
omitted. Missing Jarvis/provider/credential/model paths disable or fail only
Jarvis controls with categorized redacted errors. Retry is offered only for
transient failure categories, and an existing unaccepted proposal survives a
failed retry. The UI uses semantic status/error announcements, labels and
visible focus, native keyboard actions, bounded responsive preview areas, and
Admin2's inherited light/dark variables.

`BoundedHttpTransport` provides the production network path behind the existing
`HttpTransportInterface`. Exact HTTPS origin/base paths are allowlisted; every
resolved address must be public and the selected address is pinned against DNS
rebinding; TLS verification is mandatory; redirects and environment proxies
are disabled; transport-controlled headers are refused; and connect time,
overall time, request bytes, response headers, and decompressed response bytes
are bounded. Diagnostics omit bodies, credentials, and raw provider errors.

The built-in `openai` adapter uses only the fixed official API base,
`GRAV_JARVIS_OPENAI_API_KEY`, `GET /models` for validation/discovery, and
`POST /responses` for synchronous generation. It defaults to
`gpt-5.6-luna`, accepts the existing provider-neutral model override, disables
remote response storage, and normalizes only models, text, and provider-
reported usage into shared DTOs. It now accepts the neutral
`max_output_units` option for a bounded private wire mapping. OpenAI fields
remain under `Provider/OpenAI`.

The built-in `anthropic` adapter is a separate first-party wire family. It uses
only `https://api.anthropic.com/v1`, the required `2023-06-01` API version,
`GRAV_JARVIS_ANTHROPIC_API_KEY`, `GET /models`, and `POST /messages`. It maps
display names and truthful text capability, ordered text blocks, and provider-
reported input/output usage into the existing DTOs. Anthropic headers, message
shapes, content/stop blocks, model capability objects, error bodies, and raw
metadata remain under `Provider/Anthropic`. Missing, malformed, incomplete,
tool-oriented, and refusal-style results fail closed through existing typed
failures.

Plugin boot registers both official adapters without resolving credentials or
making requests. The generic compatible adapter still implements only the
explicit Responses-compatible profile.
Named compatible instances are disabled by default and contain only a stable
ID, immutable operator-selected public HTTPS base URI, provider-scoped
environment-variable name, default model, and truthful model-discovery flag.
There is no per-request endpoint or private/local-network opt-in. The required
Responses text subset is documented; Chat Completions-only and partial shapes
fail closed. Only text completion, provider validation, and optional discovery
are declared—streaming, structured output, and tool calling are not overclaimed.

The separate live-smoke harness still supports OpenAI and Anthropic through the
public service/registry, environment resolver, bounded production transport,
adapter, and neutral result. Both shell and PHP entry points require explicit
`GRAV_JARVIS_LIVE_SMOKE=1`; absence of the selected credential is a clean skip.
Output is capped and request/response content is not printed. No credential was
available for the 0.2.1 release validation, so no live request was attempted.

The 0.2.1 black-box gate temporarily installs a deterministic test-only
provider in the canonical DDEV fixture, creates a random disposable signed-in
Admin2 account, and drives both real Admin2 Jarvis surfaces through system
Chrome/Chromium. It covers both selectors, safe provider states and retry, all
six actions, Reject, Accept exactly once, stale/regeneration behavior, unsaved-
only reload, keyboard/labels, narrow layout, inherited light/dark theme, API-
token and provider-authority denial, no save/publish request, browser errors,
and recent Jarvis fatal logs. Cleanup restores the previous plugins, account
index, notifications, and Grav cache. The fixture plugin/account never enter
the release ZIP.

Selection-aware editing is deferred because Admin2 2.1.2 publishes no stable
selected-text contract. Non-secret provider/model defaults now use ordinary
validated Grav plugin configuration; no per-user preference or secret store was
added.
Jarvis still has no CLI command, Gemini/OpenRouter
adapter, additional suite integration, background job, streaming/tool call, durable
conversation/proposal history, structured frontmatter apply, automatic page
mutation, or MCP workflow.

Spitfire theme 1.2.1 adds an Admin **Section spacing** selector to Features,
Text, and Form modular pages. Existing pages default to Normal, Tight reuses
Quark 2's responsive `section-tight` utility, and Tighter adds a responsive
`section-tighter` utility with half of Tight's padding. The choice is separate
from the Features card-layout field, so compact spacing does not disturb its
responsive column layout.

Flexible Markdown Alerts 1.0.1 is now part of the canonical extension suite.
It provides editable alert definitions, `[!TYPE|Custom title]`, configurable
colors and icons, site-owned SVG overrides, and optional `pack/icon`
interoperability with Site Workshop's public Icon Bench service. Both plugins
remain independently installable: missing or disabled Icon Bench output
degrades to the alert's text title, and Site Workshop has no reverse
dependency. Its traveling README and separate syntax, icon, configuration, and
migration guides document the complete operator workflow.

An urgent Grav Commander follow-up corrected the canonical source of the live
blueprint failure: the `backup.path` and archive-name help text now quote their
colon-bearing scalars. Repository preflight now syntax-parses every extension
YAML file so the same defect is rejected before packaging or deployment. The
standalone Commander repository remains synchronized through the documented
subtree workflow.

Site Safeguard 0.3.11 closes the protected-download, shared-host restore, and
Admin destructive-action failures found while moving the SpitfireBBS.com site
between production and DDEV:

- tickets are short-lived, replay-bounded, HMAC-signed, and stateless;
- browser HEAD probes and range retries cannot consume a ticket;
- Admin returns a root-relative route and JavaScript pins it to the browser's
  current origin, so restored production URL configuration cannot send a DDEV
  ticket to `spitfirebbs.com`;
- newly issued tickets are bound to the actual request host, and restore always
  preserves the destination's `user/config/security-private.php`, preventing
  cross-host replay and future nonce/HMAC identity copying;
- the signed ticket carries its issuing protected-directory locator, which is
  re-resolved and containment-checked outside the Grav root, so Admin/base and
  public/hostname configuration scopes find the same retained ZIP;
- HTTP HEAD, single-range, full download, expiry/tamper denial, and safe error
  references are implemented; and
- the regression is preserved by the external DDEV runner
  `scripts/test-site-safeguard-download.sh`;
- package and stage deletion use visible in-page two-click confirmation and
  explicit authenticated POST action routes, so a blocked native dialog or a
  host that rejects raw `DELETE` cannot make the control silently inert;
- a production worker still holding the preceding route table is detected from
  its exact missing-route response and retried through the already-established
  authenticated package/stage route with method override, avoiding a PHP-FPM
  restart during a rolling plugin upload;
- create-stage and restore confirmation are rendered inside Admin2 instead of
  depending on native browser dialogs; the first stage/restore click, a wrong
  restore phrase, and Cancel are all non-mutating;
- unrelated directories beneath the staging root are explicitly classified as
  unrecognized and non-restorable; and
- restored public assets receive missing read bits while private config/data
  retain their source modes, preventing LiteSpeed/nginx 403 responses when a
  DDEV/macOS source mount records CSS/JavaScript/fonts/images as `0600`.

Environment Readiness, Package Library, Isolated Staging, and Recovery Journal
are collapsible with remembered browser preferences and attention-aware
defaults. Environment Readiness pairs a textual aggregate result and icon with
green, amber, or red presentation. Every disclosure uses the same inline SVG
chevron rotated by state, avoiding inconsistent fallback-font glyphs.

Decision 0003 establishes external black-box contract coverage as a suite-wide
rule. `docs/testing.md` contains the risk-ordered coverage inventory.

## Current quality evidence

- Caxton 0.3.0's deterministic DDEV PHP 8.3 contract and integration suites pass.
  The frozen fourteen-file 0.1.0 hash baseline remains intact; additive checks
  cover Jarvis-permission/config exposure, all seven actions, provider model/
  validation contracts, absent/no-provider/failure categories, hash-only one-
  time receipts, cross-user/page/stale refusal, code/protected restrictions,
  custom-prompt separation, and usage/cost/reliability data.
- Nineteen Node component/security/performance tests and the actual-Chrome field
  proof pass. They additionally cover page-media insertion/image editing,
  reference-link definitions, horizontal rules, code languages, multi-item
  lists, Jarvis undo/redo, scoped visual rhythm, light/dark palettes, and exact
  source preservation. The signed-in DDEV browser gate measures every required
  mixed-block gap in light and dark under reset styles and proves no styling
  change/dirty/source/page mutation. It then uses Jarvis's deterministic offline
  fixture for Visual and Source selection preview, Reject, Accept, undo/redo,
  no Save, responsive layout, clean console, and clean recent logs.
- Reproducible 0.3.0 bundles are 879,152 bytes for the proof asset, SHA-256
  `1ed1a7b03e467a013d0e1f447805419c3cdc83173fa411a69966e0ecc816350f`,
  and 921,750 bytes (312,395 bytes gzip -9) for the Admin2 field, SHA-256
  `44e9d7f34fd14d5b4559aa0a6aeeaf6960e20df9dc34a60dce8e5e3bf932df87`.
  The installable `dist/grav-caxton-0.3.0.zip` is 653,091 bytes, SHA-256
  `d07e493ea286c0487395d194f0bbfc86113b9c7e6c8e428a8f0ef1efa2bc4c4b`;
  ZIP CRC and one-root checks pass.
- Caxton's deterministic PHP contract passes in DDEV PHP 8.3.31 and all
  fourteen 0.1.0 public contract hashes remain byte-identical. It additionally
  proves permission-filtered, nested, idempotent page Markdown-field replacement,
  separate Source permission, ordered/aliased/bounded toolbar configuration,
  unknown-item denial, and non-replacement of explicit editor fields.
- Nineteen Node component/security/performance checks pass against the actual
  ProseMirror/CodeMirror/Lezer adapters. They prove safe nodes and opaque cards,
  byte-exact LF/CRLF/no-final/Unicode mode round trips, localized text/mark/link/
  GFM-strikethrough/non-1-list/fenced-code edits, safe contiguous multi-paragraph
  patches with stale/opaque denial, exact or safely absent selection mapping,
  SHA-256-bound UTF-16/UTF-8-byte conversion with split-code-point denial,
  content-only dirty/stale/read-only behavior, inert hostile constructs/media,
  bounds, and deterministic offline behavior. Diagnostic observations cover
  10,000 safe spans, 4,000 opaque/trivia spans, a 20,000-line fence, and a
  419,682-unit/14,002-span combined document.
- Actual system Chrome loads both reproducible bundles and mounts the real
  editors plus the Admin2-shaped custom field. Visual mode hides Markdown
  punctuation, mode switches remain exact and clean, direct visual typing emits
  localized source values, unsafe links fail closed, links/quotes/lists/code/
  strikethrough serialize as Markdown, split/undo/join and link shortcuts retain
  focus, read-only controls disable, opaque Twig remains text-only, external
  replace stays in the unsaved buffer, light/dark contrast is explicit, and no
  private global API leaks. The proof bundle is 878,032 bytes, SHA-256
  `5693b30fc1436c15464a3827a953c8e162208f5f7e6b87d97cb2d0d0544f64a9`;
  the field is 897,644 bytes (306,410 bytes with gzip -9), SHA-256
  `d3223b675cd898ced21bb03fb8b5a4083329908e5a55df23ee492f81f0674382`.
- The signed-in DDEV Chrome regression proves authenticated field loading,
  formatted text without Markdown markers, exact no-edit Source/Visual switching,
  one localized visual edit, opaque-byte survival, public current-buffer value,
  reload-before-Save non-persistence, ordinary Save persistence, safe links,
  strikethrough, paragraph split/undo/join, quotes/lists, toolbar settings,
  real dark visual/source contrast, narrow layout, and clean relevant browser/
  page/log state.
- `npm ci --ignore-scripts` and audit pass with zero known vulnerabilities.
  Exact runtime/build versions, all bundled transitive packages, MIT/BSD/
  Apache licensing, notices, and the adapter/build/test boundaries are
  documented. Node/JavaScript and shell syntax, Composer strict validation,
  Caxton YAML/JSON, whitespace/hygiene, changed-documentation links,
  credential/forbidden-file scans, ZIP CRC, one-root inspection, and final
  repository-wide preflight pass. A transient concurrent Jarvis 0.3.2 edit
  briefly made one preflight rerun fail at its blueprint; that unrelated work
  was not modified or included and its own session cleared the error before the
  final audit. Host PHP remains absent, so host preflight truthfully skipped
  PHP; DDEV PHP 8.3.31 supplied PHP syntax/runtime evidence.
- Historical exact packaged 0.2.0-to-0.2.1 installation passed in DDEV, including PHP
  syntax, cache clear, public/Admin/field-asset HTTP 200, version inspection,
  installed bundle hashes, and recent-log inspection. It is superseded by the
  0.3.0 installed state recorded above; Commander and Jarvis remained unchanged.
- The historical 0.2.1 package is `dist/grav-caxton-0.2.1.zip` (633,791 bytes), SHA-256
  `e4cbc6704eab77e3160c029196ec8c510ece748189b8ee4183a2b2b62e96c5b7`.
  The 0.2.0 archive remains intact at SHA-256
  `8b5b97e13caf527dd68333556ccab417759b6e87b56774f3099677d09a70bd83`.
  The 0.1.1 archive remains intact at SHA-256
  `45f2c2e976e75b2552535f4b9e54f4cd12586a6205d56bafee308e6341d88bd0`.
  The 0.1.0 archive remains intact at SHA-256
  `21604885f1f551f20f482d00021d6a2bbf02c0d1654da585725794725bbf381c`.
- Commander 0.3.12 passes ten deterministic PHP integration checks and six
  isolated browser-component checks. They prove public-contract-only service
  discovery, all five actions, bounded/redacted context, safe large-Markdown
  summarization, deterministic provider/usage/cost data, absent/invalid service
  fallback, credential/configuration/rate-limit/timeout/malformed/budget/
  capability failures, actor/file/version/source/replay-bound proposals,
  unsaved-buffer-only Apply, and absence of provider/private-class coupling or
  filesystem writes.
- The signed-in DDEV Chrome regression passes with Jarvis present and absent.
  It proves provider validation/model discovery, Review, usage/cost display,
  Reject, one-time Apply, reload non-persistence, safe provider failure, and
  continued Commander usability. The fixture has a known unrelated external
  `spitfirebbs.com/user/data/grav-security-probe.dat` CORS failure; the runner
  filters only that exact baseline and its deliberate provider-unavailable
  response while rejecting unexpected integration console/page errors.
- Commander PHP syntax passes in DDEV PHP 8.3.31; Composer, repository and
  Grav YAML, JSON, JavaScript, shell, preflight, whitespace, and package
  integrity checks pass. Exact 0.3.11-to-0.3.12 upgrade and fresh 0.3.12 ZIP
  install preserve the absent site config, clear Grav cache, return public/
  Admin/Commander HTTP 200 and anonymous API 401, and produce no relevant
  fatal/uncaught log entry. Host PHP remains unavailable, so repository
  preflight truthfully skips host PHP. The disposable fixture was restored to
  Commander 0.3.11 with temporary Jarvis plugins removed.
- Jarvis's deterministic PHP source suite passes seventy-five checks: seven
  frozen 0.1.0 registration/service/failure/redaction checks, twelve 0.1.1
  provider-boundary checks, and nine 0.1.2 bounded-transport/OpenAI checks.
  Those checks cover destination policy/DNS pinning, generic service routing,
  models and Responses normalization, usage, model selection, credentials and
  configuration, malformed/empty data, 401/429/other 4xx/5xx/timeout paths,
  offline determinism, contract neutrality, and redaction. Six 0.1.3 checks add
  full compatible service operation, multi-instance separation, limited/no-
  discovery behavior, missing endpoints, multiple incompatible shapes, rate
  guidance, cross-instance credential denial, and private-destination denial.
  Ten 0.1.4 checks add official Anthropic headers, bounded non-secret cursor
  pagination, validation/discovery, model labels and truthful capabilities,
  ordered multiple text blocks, usage/no-usage success, empty/malformed/
  incomplete output, credential/configuration denial, auth/rate/other 4xx/5xx/
  timeout classification, offline determinism, redaction, and byte-identical
  frozen-interface hashes. Seven 0.2.0 backend checks add the prompt library,
  all six actions, bounded/redacted current-page context, provider/model/status
  bootstrap, hash-only receipts, accept once, replay/stale denial, and preview-
  only truncation. Seven Node browser-component checks add same-API/no-store
  transport, graceful absence, output escaping, official unsaved-buffer events,
  replace-only Accept, non-mutating Reject, and no save/publish/provider path.
  Five 0.2.1 backend hardening checks add opaque/capped/expiring receipts,
  cross-user/cross-page/rejected/replaced/stale/replay denial, safe failure
  categories, fresh regeneration, coded errors, and fixed provider authority.
  Five 0.3.0 reliability checks cover bounded timeout/rate-limit retry and
  exhaustion, retry-after/jitter/non-retryable/budget behavior; cache miss/hit/
  expiry/capacity and site/user/page/provider/model isolation; hashed keys and
  request-free private values; nullable usage; known/unknown/versioned decimal-
  safe costs and retry amplification; and known-versus-unknown budgets. Three
  chunk checks cover Markdown/frontmatter/fence/list ordering and provenance,
  oversize/count/truncation bounds, summarize-only partials and bounded final
  synthesis. Five 0.3.2 provider-setup checks cover built-in/compatible/
  extension metadata, exact environment names without values, disabled and
  missing/configured/malformed/authentication-failed states, preferred/default
  providers, unavailable/default models, discovery-failure retention, blueprint
  secrecy, and DDEV/package guards. Six 0.3.3 credential/readiness checks add
  Sodium/OpenSSL save/decrypt/replace/remove, tamper/tag/wrong-key/malformed/
  version failures, external/local key state, environment precedence/reveal,
  no-AEAD/unwritable downgrade, and symlink refusal. Nine grouped Node checks
  add write-only key entry, Settings authority, readiness, storage-success/
  validation-failure state, and leakage denial. The separate signed-in browser
  gate passes eleven grouped end-to-end checks covering both Admin2 surfaces,
  Settings, readiness, submission-only secret handling, setup/validation
  states, and all six actions through actual interactions.
- Every Jarvis PHP file passes PHP 8.3 syntax in DDEV. Repository
  structure/YAML/hygiene preflight, whitespace validation, Composer/JSON and
  YAML parsing, Composer validation, JavaScript/shell syntax and component
  contracts, changed-Markdown link validation, source/package credential
  scans, ZIP integrity, packaged 0.3.2-to-0.3.3 upgrade, fresh 0.3.3 package
  install, Grav cache clearing, cURL availability, public/Admin/Jarvis/page
  HTTP health, anonymous 401, authenticated signed-in Admin2 flows, browser
  console/page errors, and clean relevant log checks pass. Host PHP remains
  unavailable, so root preflight truthfully skips host PHP; DDEV PHP 8.3.31
  supplied lint/runtime evidence. No live credential existed on the host or in
  DDEV, so the explicitly opt-in smoke was skipped and no live request/charge
  occurred.
- The verified Jarvis package is `dist/grav-jarvis-0.3.3.zip`, SHA-256
  `4d33947e17447cbec447d90eaf0d34e9106e678c196d44a03cd055c1cada0451`.
  Versioned 0.1.0 through 0.3.2 packages and hashes remain intact.
- The verified Commander package is `dist/grav-commander-0.3.12.zip`, SHA-256
  `b662269b2fb3749e9ab594c9674e3c8ff9d6531aed458d829aa4ee1bd3011789`.
  The consumer-only 0.3.1 milestone still has no separately manufactured
  Jarvis package.
- Spitfire theme 1.2.1 passes repository preflight, Grav YAML linting, ZIP
  integrity, and local DDEV rendering checks. Features and Text produced 28px
  desktop edge padding for Tighter, Form produced 56px for Tight, and the Home
  and Contact routes returned HTTP 200. Temporary page selections were removed
  after testing, leaving existing site presentation unchanged by default.
- Flexible Markdown Alerts passes PHP syntax, Composer, YAML, ZIP integrity,
  public HTTP, and external DDEV rendering checks. Bundled icons, site-owned
  SVGs, Icon Bench references, missing-reference fallback, and operation with
  Site Workshop disabled were exercised through the public Markdown route.
- The plugin's Admin README uses verified public asset URLs for its two example
  images, and its source, DDEV installation, and release package identify Craig
  Daters as author/maintainer.
- Every plugin and theme YAML file passes a real parser, and the Grav Commander
  blueprint preserves its expected metadata, form, and field structure.
- The Grav Commander package passes ZIP integrity and contains the repaired
  blueprint at the required top-level plugin path.
- Site Safeguard PHP files pass PHP 8.3 syntax checks in DDEV.
- Admin JavaScript passes Node syntax parsing.
- Cross-environment ticket resolution passes when the simulated public package
  configuration deliberately points to a nonexistent directory.
- The externally requested DDEV route remains on
  `spitfire-file-vault.ddev.site`, returns HTTP 200 for HEAD and 206 for a
  128-byte range, and streams bytes from the protected ZIP.
- The latest portable package was independently inspected and staged:
  `safeguard-localhost-portable_site-20260823-065733-349135.zip`, SHA-256
  `b88aed045b0775abf43138d337044d14e6db72f30b525958dfab526da5c96813`;
  see the latest `docs/SESSION-LOG.md` entry for the stage identifier.
- Live Admin2 restore settings were confirmed durable after a fresh page load.
  Site Safeguard 0.3.6 changes both restore toggles to highlight the selected
  **Enabled** state; 0.3.5 highlighted **Disabled**, which made a successful
  enablement look visually inactive. The orange field marker is Admin2's saved
  override indicator, not an error.
- The DDEV settings contract proves public restored asset mode `0644` versus
  private configuration mode `0600`; the disposable staging cleanup contract
  proves unrecognized classification, contained removal, and traversal denial.
- The Admin UI behavior contract proves that the first destructive click makes
  no request, the confirmation click uses a POST action route, a stale route
  table falls back through authenticated method override, cancellation is non-
  mutating, disclosure preferences persist, and readiness renders ready,
  warning, and error states. Unauthenticated external POSTs to both DDEV route
  forms return 401 rather than 404/405, proving that Grav registered the routes
  and applied its authentication boundary.
- The same UI contract rejects any native `confirm()`/`prompt()` dependency,
  proves Create stage is a two-click action, and proves Restore requires its
  visible exact-phrase form. Opening either action, entering the wrong phrase,
  or cancelling produces no API request.

## Active milestone

Jarvis 0.3.3 is complete and packaged as the encrypted credential-usability and
host-readiness checkpoint.
Caxton 0.3.0 is complete and is the active packaged/installed review release.
It reconciles the committed 0.2.2 authoring work with optional Jarvis proposals;
Page Studio is a superseded recovery link only. Jarvis required no code or
release change because its existing public 0.3.x contracts were sufficient.
The previously recommended Commander 0.3.13 work remains paused—not cancelled—
because the owner explicitly selected Caxton.

Spitfire theme 1.4.0 reconciles the accepted SPITFIRE NG documentation and
project presentation from the copied DDEV integration tree into canonical
`themes/spitfire` source. Current-project and historical Archive navigation
remain separate; native disclosure elements keep the sidebars usable without
JavaScript, while the small script only recenters the active entry. Canonical
source, its installable package, and a copied site deployment have distinct
roles documented in the theme manual and development workflow.

The reconciled DDEV deployment is byte-identical to all 27 canonical theme
files. The final 1.4.0 package is 880,330 bytes with SHA-256
`dba7f96c568c8f302139083d317a31bee1e13cbe2b2584013bd88af53e85aa22`.
Twenty-two representative public routes pass, Lantern indexes 110 public pages,
and the required practical searches lead to relevant current documentation.
Fresh viewport and browser-console inspection remains external because the
supported browser controller still attempts to load its removed cached service
module; server, rendered-HTML, asset, responsive-CSS, accessibility, search,
and log checks all pass.

## Exact next action

Implement Caxton 0.3.1 as accessibility and proposal hardening. Add complete
dialog focus trapping/return and screen-reader announcements; prove keyboard-
only Jarvis operation, reduced-motion/high-contrast, RTL, IME, touch, zoom, and
narrow behavior; extend direct API permission-denial, receipt-expiry, Source-
mode protected-boundary, and large-document proposal-mapping coverage. Keep the
0.1.0 PHP contracts frozen and do not add provider code, credentials, media
upload, autosave/publish, HTML/Twig/shortcode editing, collaboration, batch,
jobs, MCP, or a parallel page store.

## Explicitly deferred

- Selection-aware editing and structured metadata proposal application are
  deferred until stable public Admin2 events exist.
- Transactional whole-store master-key/backend rotation is deferred to Jarvis
  0.3.4; 0.3.3 supports explicit per-provider replacement under the current
  preferred backend/key source and never silently migrates an untouched record.
- Gemini and OpenRouter, private/local compatible endpoints, broader compatible
  profiles, live streaming, prompt/response persistence,
  durable accounting/history, background jobs, batch/site-wide workflows, and
  MCP-facing endpoints remain later work. Rewrite/proofread chunk execution is
  deferred until deterministic structure-preserving reconstruction exists.
- Caxton's live preview, table/HTML/Twig/shortcode editing, client extension
  loading, media upload, split view, automatic typography replacement,
  collaboration, autonomous AI, batch/jobs/MCP, and richer inline diff remain
  deferred. Underline remains excluded until a portable source policy exists.
  Page-field replacement and optional Jarvis proposals ship behind separate
  configuration and permissions.
- Commander 0.3.13 ordinary-Save optimistic-concurrency hardening remains the
  exact next Commander milestone after the explicitly selected Caxton work.
- The previous File Vault black-box milestone remains required under Decision
  0003 and is paused, not cancelled: prove anonymous denial, authorized
  delivery, ACL/password/download-limit enforcement, range/resume behavior,
  correct analytics counting, and byte-identical protected delivery without
  coupling it to Site Safeguard.
- Site Safeguard standalone Recovery Console, scheduling, encrypted SSA/SSS,
  remote providers, and external-data-set orchestration remain roadmap work.
- Site Safeguard restore-worker black-box coverage remains the next test for
  that plugin after the download contract.
- Other extension contracts remain listed as required in `docs/testing.md` and
  should be implemented in risk order, not all in one broad rewrite.
- Local Site Workshop/Cache Hearth edits may exist outside this checkpoint.
  Inspect `git status`; do not assume uncommitted work exists in GitHub or
  include it in an unrelated commit.

## Resume procedure

1. Read `AGENTS.md` and this file.
2. Read `README.md`, `docs/roadmap.md`, and `docs/architecture.md`.
3. Read `docs/decisions/README.md` and the latest entries in
   `docs/SESSION-LOG.md`.
4. Read `docs/planned/grav-caxton.md`, Decision 0005,
   `plugins/grav-caxton/README.md`, and every file under
   `tests/grav-caxton/` before changing the source model or engine adapters.
   The Editor Pro ZIP is not a build/test dependency and must not be copied.
5. Run `./scripts/test-grav-caxton-contract.sh`,
   `./scripts/test-grav-caxton-editor.sh`, and
   `./scripts/test-grav-caxton-admin-browser.sh`. Preserve the frozen PHP hashes,
   both reproducible browser bundles, and the signed-in value/Save boundary.
6. Rebuild only the package whose source changed. For the active milestone use
   `./scripts/package-extension.sh plugin grav-caxton`; Jarvis 0.3.3 and
   Commander 0.3.12 remain their verified releases.
7. Run `git status` and `git log --oneline --decorate -10`.
8. Confirm the active milestone, exact next action, deferred work, and local
   uncommitted changes before modifying files.
