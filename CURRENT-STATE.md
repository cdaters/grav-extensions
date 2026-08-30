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

Jarvis 0.1.0 (`grav-jarvis`) is now a runnable, independently packageable Grav
2 plugin and the first implementation of Decision 0004. It registers the
provider-neutral `$grav['gravJarvis']` service and
`onJarvisProviderRegister`, exposes public service/provider/registry contracts
plus immutable completion request/result/usage values, and includes a
deterministic fake provider for tests only. Typed failure normalization,
credential-key rejection, and environment-aware redaction protect exception
messages, successful provider output, and result metadata. Optional consumers
have a documented and tested absence/disabled/failure fallback pattern.

The package registers no provider itself and makes no network request. It does
not yet contain OpenAI, Anthropic, OpenAI-compatible, Gemini, or OpenRouter
adapters; Admin2 UI; Commander integration; background jobs; content mutation;
or MCP workflows. Jarvis remains an original framework, not a clone of Grav AI
Pro, and future site operations continue to use Grav REST/MCP permissions and
concurrency controls.

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

- Jarvis's seven-check contract passes under PHP 8.3 in the canonical DDEV
  fixture. It covers actual plugin service/event registration, deterministic
  fake responses, duplicate/missing providers, normalized failures,
  missing/disabled/invalid-service consumer fallback, request credential
  rejection, and secret redaction across failures, successful output, and
  result metadata.
- Every Jarvis PHP file passes PHP 8.3 syntax in DDEV. Repository
  structure/YAML/hygiene preflight, whitespace validation, Composer/JSON and
  YAML parsing, ZIP integrity, package installation, Grav cache clearing, and
  public/Admin HTTP 200 checks pass. Host PHP remains unavailable, so the root
  preflight truthfully reports its host-side PHP syntax step as skipped; DDEV
  supplied the PHP lint and runtime evidence.
- The verified Jarvis package is `dist/grav-jarvis-0.1.0.zip`, SHA-256
  `b786a65de8a15551ace2a2c2164ac305ffabb74e25c711876a8535ca666b87ab`.
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

Jarvis 0.1.1 provider boundary. Preserve the frozen 0.1.0 contracts while
defining the narrow validation, model-discovery, credential-resolution, and
HTTP-fixture seams needed for live adapters.

## Exact next action

Design and implement non-breaking optional provider-validation and model-
discovery contracts, an environment credential resolver, and deterministic
local HTTP transport fixtures. Add conformance tests for success, timeout,
malformed response, authentication failure, and redaction, then review and
freeze that boundary before adding any live adapter. Do not change the 0.1.0
`ProviderInterface`, and do not add Admin2, Commander integration, jobs, batch
work, content mutation, or MCP endpoints in this checkpoint.

## Explicitly deferred

- OpenAI, Anthropic, and OpenAI-compatible live adapters follow the reviewed
  0.1.1 provider boundary as separate 0.1.x increments. Gemini and OpenRouter,
  the Admin2 assistant, live streaming, prompt persistence, caching/retries,
  cost accounting, chunking, background jobs, batch/site-wide workflows, and
  MCP-facing endpoints remain later work.
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
