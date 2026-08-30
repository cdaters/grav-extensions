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

Jarvis 0.1.3 (`grav-jarvis`) completes the bounded compatible-provider stretch under
Decision 0004. The 0.1.0 `JarvisServiceInterface`, `ProviderInterface`, and
`ProviderRegistryInterface` method sets and every additive 0.1.1 validation,
discovery, credential, HTTP, and DTO contract remain unchanged.

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
remote response storage, rejects unsupported options, and normalizes only
models, text, and provider-reported usage into shared DTOs. OpenAI wire fields
remain under `Provider/OpenAI`. Plugin boot registers the adapter without
resolving a credential or making a request; missing or malformed credentials
degrade through the existing typed validation/failure path.

Jarvis still has no Anthropic, Gemini, or OpenRouter adapter; Admin2 assistant;
Commander integration; background job; content mutation; or MCP workflow. The
generic compatible adapter implements only the explicit Responses-compatible
profile.
Named compatible instances are disabled by default and contain only a stable
ID, immutable operator-selected public HTTPS base URI, provider-scoped
environment-variable name, default model, and truthful model-discovery flag.
There is no per-request endpoint or private/local-network opt-in. The required
Responses text subset is documented; Chat Completions-only and partial shapes
fail closed. Only text completion, provider validation, and optional discovery
are declared—streaming, structured output, and tool calling are not overclaimed.

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

- Jarvis's full suite passes thirty-four checks under DDEV PHP 8.3.31: seven
  frozen 0.1.0 registration/service/failure/redaction checks, twelve 0.1.1
  provider-boundary checks, and nine 0.1.2 bounded-transport/OpenAI checks.
  The new checks cover destination policy/DNS pinning, generic service routing,
  models and Responses normalization, usage, model selection, credentials and
  configuration, malformed/empty data, 401/429/other 4xx/5xx/timeout paths,
  offline determinism, contract neutrality, and redaction. Six 0.1.3 checks add
  full compatible service operation, multi-instance separation, limited/no-
  discovery behavior, missing endpoints, multiple incompatible shapes, rate
  guidance, cross-instance credential denial, and private-destination denial.
- Every Jarvis PHP file passes PHP 8.3 syntax in DDEV. Repository
  structure/YAML/hygiene preflight, whitespace validation, Composer/JSON and
  YAML parsing, Composer validation, ZIP integrity, 0.1.2-to-0.1.3 packaged
  upgrade, fresh 0.1.3 package install, Grav cache clearing, cURL availability,
  and public/Admin HTTP 200 checks pass. Relevant logs contain no Jarvis,
  fatal, or uncaught match. Host PHP remains unavailable, so the root preflight
  truthfully reports its host-side PHP syntax step as skipped; DDEV supplied
  PHP lint and runtime evidence. No live OpenAI account request was needed or
  attempted.
- The verified Jarvis package is `dist/grav-jarvis-0.1.3.zip`, SHA-256
  `4c2c20996f0a46dac704beaa6db91f7b5fa1f03aec251e5d0ac4075fccea4f0c`.
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

Jarvis 0.1.4 Anthropic adapter: prove a second first-party vendor wire family
without changing the shared 0.1.0/0.1.1 contracts or existing providers.

## Exact next action

Re-check Anthropic's current official API, then add an isolated adapter using
the bounded transport and a provider-scoped environment credential. Map non-
generating validation and model discovery only where the official API supports
them; normalize synchronous text and reported usage; keep all vendor fields and
errors private; and require deterministic success, malformed, auth, rate,
server, timeout, redaction, and no-network-fallback fixtures. Do not change the
shared contracts, existing OpenAI/compatible adapters, or add Admin2,
Commander, jobs, streaming, batch, content mutation, or MCP endpoints.

## Explicitly deferred

- Gemini and OpenRouter, private/local compatible endpoints, broader compatible
  profiles, the Admin2 assistant, live streaming, prompt persistence,
  caching/retries, cost accounting, chunking, background jobs, batch/site-wide
  workflows, and MCP-facing endpoints remain later work.
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
- Local Site Workshop/Cache Hearth and Spitfire-theme edits may exist outside
  this checkpoint. Inspect `git status`; do not assume uncommitted work exists
  in GitHub or include it in an unrelated commit.

## Resume procedure

1. Read `AGENTS.md` and this file.
2. Read `README.md`, `docs/roadmap.md`, and `docs/architecture.md`.
3. Read `docs/decisions/README.md` and the latest entries in
   `docs/SESSION-LOG.md`.
4. For the active Jarvis milestone, read `docs/planned/grav-jarvis.md` and
   `docs/decisions/0004-grav-jarvis-agent-framework.md` completely.
5. Read `plugins/grav-jarvis/README.md`, then run
   `./scripts/test-grav-jarvis-contract.sh`.
6. Rebuild with `./scripts/package-extension.sh plugin grav-jarvis` after any
   package change; checksums are expected to change.
7. Run `git status` and `git log --oneline --decorate -10`.
8. Confirm the active milestone, exact next action, deferred work, and local
   uncommitted changes before modifying files.
