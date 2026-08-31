# Testing and verification

## Repository preflight

Run:

```bash
./scripts/verify-extensions.sh
```

This verifies required package files, rejects common runtime/secret artifacts,
and lints every PHP file when a host PHP executable is available.

## Grav integration test

For a meaningful release candidate, also test in a clean Grav installation:

1. Install only the extension and its declared dependencies.
2. Clear Grav cache and confirm the public site still renders.
3. Confirm Admin loads in light and dark modes.
4. Exercise anonymous, authenticated, unauthorized, and super-admin paths.
5. Verify keyboard operation and narrow/mobile layout.
6. Review browser console, Grav logs, response headers, and network errors.
7. Upgrade over the preceding released version without deleting runtime data.
8. Package the extension and repeat the install from that ZIP.

## Black-box boundary contract

Repository preflight and direct service calls are necessary but cannot prove a
real HTTP, browser, filesystem, archive, authentication, cache, or rendering
boundary. Every extension must add a repeatable external regression for each
security-sensitive or state-changing boundary it owns. See
[Decision 0003](decisions/0003-black-box-extension-boundaries.md).

A black-box test should:

1. enter through the same public interface used by the real consumer;
2. verify durable output (bytes, hashes, headers, records, or rendered state),
   not only a success message;
3. cover a successful path and at least one denial, malformed, or interruption
   path;
4. cross process/configuration boundaries where the production workflow does;
5. redact credentials, bearer tokens, signed tickets, and protected paths; and
6. run against DDEV or another disposable fixture rather than requiring a live
   production mutation.

### Site Safeguard download contract

With the DDEV site running and at least one retained package available:

```bash
./scripts/test-site-safeguard-download.sh \
  ~/Documents/Spitfire/custom-plugins/file-vault-ddev
```

The runner creates a short-lived ticket inside the container, deliberately
changes the simulated public package configuration, and then behaves as an
external client. It verifies a root-relative/same-origin route, issuing-host
binding, scope-independent package resolution, HEAD, a 128-byte range, a
complete SHA-256-identical ZIP, and denial of a modified ticket. It never
prints the ticket or protected path.

### Site Safeguard settings contract

After deliberately enabling or disabling both restore switches in the DDEV
Admin2 settings page, verify their durable state through a separate fresh Grav
process:

```bash
./scripts/test-site-safeguard-settings.sh \
  ~/Documents/Spitfire/custom-plugins/file-vault-ddev enabled
```

Use `disabled` as the second argument when testing the safe defaults. The
runner confirms that both effective values survive a fresh boot, that the
blueprint still defaults them to disabled while highlighting the selected
Enabled state, and that editable path lists normalize duplicate and malformed
entries. It is read-only and does not print configuration contents.

### Site Safeguard staging cleanup contract

Run against the disposable DDEV fixture:

```bash
./scripts/test-site-safeguard-stage-cleanup.sh \
  ~/Documents/Spitfire/custom-plugins/file-vault-ddev
```

The runner creates a uniquely named disposable directory inside the configured
staging root, verifies that it is classified as unrecognized and never
restorable, removes it through Site Safeguard's containment-checked service,
and proves that a parent-traversal identifier is denied. A cleanup trap removes
the disposable directory if an assertion fails.

### Site Safeguard Admin UI contract

Run on the repository host:

```bash
./scripts/test-site-safeguard-admin-ui.sh
```

The isolated browser-component harness proves that package and stage removal
require two deliberate clicks, that the first click cannot call an API, and
that the confirmed action uses the shared-host-safe authenticated POST route.
It injects the production-observed stale-route response and proves that both
package and stage removal retry through the established authenticated route
with method override. It also verifies semantic disclosure defaults,
remembered choices, and the green/amber/red Environment Readiness aggregate
states. The disposable DDEV staging cleanup contract remains the filesystem-
boundary proof that actual contained removal succeeds and parent traversal is
refused.

The contract also refuses any native `confirm()` or `prompt()` call in the
dashboard source. It proves Create stage requires a separate in-page
confirmation, and that opening Restore, typing a wrong phrase, or cancelling
cannot call the API. Only the exact phrase followed by **Confirm restore**
produces the expected authenticated request body.

### Suite coverage inventory

| Extension | Highest-value black-box boundaries | State |
| --- | --- | --- |
| Site Safeguard | signed delivery, environment/origin separation, ranges, exact ZIP bytes, restore settings, state-changing Admin actions, restore worker | download, settings, stage-cleanup, and Admin UI contracts implemented; detached-worker restore contract next |
| File Vault | anonymous/authenticated ACL delivery, range/resume, analytics, external storage | required |
| Prism Gallery | authorized media enumeration, derivative delivery, missing/corrupt media | required |
| Image Foundry | source immutability, derivative cache, purge containment, concurrent generation | required |
| Meta Pilot | canonical/meta output, route overrides, cache invalidation, malformed fields | required |
| Revision Ledger | concurrent revisions, permission boundaries, restore conflict behavior | required |
| Lantern Search | index visibility, ACL filtering, stale-index repair, malformed queries | required |
| Site Workshop | tool permissions, cache operations, preview/apply separation | required |
| Flexible Markdown Alerts | Markdown parsing, custom-title escaping, configured type/color/icon rendering, site-owned SVG precedence, optional Icon Bench failure isolation | initial external DDEV rendering checks passed; durable runner required |
| Grav Commander | file-operation containment and permissions across its standalone and suite installs | required; coordinate with standalone tests |
| Jarvis | provider normalization/redaction, authorization, streaming, cache/context isolation, budgets, preview non-mutation, approval exactly once, stale conflicts, truthful batch partial failure, consumer fallback | 0.3.0 provider/Admin2/retry/cache/usage/cost/budget/chunk contracts and deterministic full signed-in browser regression implemented; streaming and later batch/job boundaries remain |
| Spitfire theme | public routes, asset delivery, responsive navigation, light/dark and no-JavaScript rendering | required |

New coverage should be added in risk order. A repaired security or delivery bug
must not wait for the whole inventory before receiving its own regression.

### Jarvis AI boundary contract

Run the complete 0.1.0 compatibility, 0.1.1 provider-boundary, 0.1.2 bounded-
transport/OpenAI, 0.1.3 compatible-provider, 0.1.4 Anthropic, 0.2.0/0.2.1 Admin,
and 0.3.0 reliability/chunking suite with host PHP or a DDEV project:

```bash
./scripts/test-grav-jarvis-contract.sh
```

The runner uses deterministic fake environment values/providers and no
network. It lints the package and tests in PHP, then proves actual plugin service
registration, `onJarvisProviderRegister`, stable fake output, duplicate/missing
provider denial, normalized provider failures, disabled/missing/invalid service
fallback, request credential rejection, and redaction of environment secrets,
authorization text, successful output, and result metadata. The provider-
boundary contract additionally freezes the 0.1.0 method sets and proves
validation without generation, provider-neutral model catalogs, provider-
scoped environment lookup, missing/malformed credentials and configuration,
non-serializable credential/HTTP objects, sanitized deterministic request
history, success/error/transport fixtures, malformed responses,
authentication/rate-limit classification, optional fallback, and offline
determinism. The 0.1.2 contract additionally proves HTTPS/base-path and public-
address policy, DNS pinning, disabled redirects/proxies, explicit time/size
bounds, transport-controlled header denial, generic service routing through the
OpenAI adapter, official model and Responses normalization, provider-reported
usage, default/explicit model selection, missing/malformed credentials and
configuration, empty/malformed data, 401/429/other 4xx/5xx/timeout failures,
secret redaction, and absence of OpenAI vocabulary in shared contracts. The
0.1.3 contract proves full Responses-compatible operation through the
bounded service; separate instance IDs/endpoints/environment references;
truthful no-discovery capability and limited-validation warning; declared
endpoint absence; Chat Completions-only, malformed, and incomplete failures;
rate guidance; cross-instance credential denial; and private-destination
denial. The 0.1.4 contract proves versioned Anthropic headers, bounded
destination policy, non-generating validation/model discovery with bounded
cursor pagination, normalized model labels/capabilities, ordered multiple text
blocks, usage and no-usage
success, missing/malformed/incomplete provider responses, missing/malformed/
cross-provider credentials and configuration, 401/429/other 4xx/5xx/timeout
classification, no network fallback, redaction, and byte-identical frozen
interfaces. When host PHP is absent, the runner defaults to the canonical DDEV
fixture when available or accepts a DDEV project path/
`GRAV_JARVIS_DDEV_PROJECT`.

The 0.2.0 backend contract keeps every frozen interface byte-identical and
proves the six-action prompt library, deterministic current-page envelope,
content/frontmatter/media/output bounds, key- and value-based secret redaction,
provider/model/status bootstrap, all six offline proposal paths, hash-only
receipt storage, accept-once/replay denial, and preview-only truncation. Static
controller assertions complement the DDEV HTTP checks for Jarvis access/use/
approve and page read/write gates and absence of page persistence primitives.
The additive 0.2.1 hardening contract proves opaque identifiers; deterministic
receipt expiry and a 128-receipt bound; cross-user, cross-page, stale, rejected,
replaced, and replay denial; fresh regeneration; safe failure category/
retryability mapping; coded redacted API errors; and rejection of request
fields that could widen provider authority.

The 0.3.0 reliability contract proves deterministic retry success/exhaustion,
timeout and rate-limit retryability, normalized retry-after, bounded jitter,
non-retryable authentication/configuration, and retry-budget stopping before an
extra call. It proves miss/hit/expiry/capacity, hashed keys with no raw prompt,
cross-installation-actor/page/provider/model isolation, custom-prompt bypass,
no cached failures, and owner-only file records without request or credential
material. It covers nullable normalized usage, OpenAI/Anthropic-shaped neutral
provider/model examples, known/unknown/versioned pricing, separate input/output
rates, fixed-point precision, and conservative retry amplification. Budget
fixtures cover allowed unknown-cost requests and pre-call denial for known
input, output, request, retry, and cost excesses.

The 0.3.0 chunking contract proves byte-identical ordering and source hashes
across Markdown headings, paragraphs, lists, fenced code, and YAML frontmatter;
oversized indivisible blocks; maximum count; explicit infrastructure-only
truncation; summarize-only chunk execution; bounded final synthesis; and denial
of rewrite-style chunk execution. All fixtures are offline and deterministic.

Run the isolated Admin2 browser-component contract on the host:

```bash
./scripts/test-grav-jarvis-admin-ui.sh
```

It loads the shipped plugin page and context-panel scripts in a disposable DOM/
event harness. It proves Admin2 API-token/no-store transport with browser
credentials omitted, no provider endpoint or provider credential in browser
code, safe response escaping, provider-neutral completion, graceful disabled
service behavior, the official current-unsaved-buffer request/response event,
explicit replace-buffer Accept, non-mutating Reject, no save/publish event, and
the visible selection-aware deferral. Its 0.2.1 assertions also cover semantic
status/error roles, accessible labels, visible focus rules, replacement and
discard requests, safe proposal retention, inherited theme variables, and
responsive layout rules.
The 0.3.0 assertions add budget-blocked rendering and concise normalized usage,
estimated/unknown cost, request/retry, and cache-hit indicators.

Run the authenticated Admin2 black-box regression against the canonical
disposable DDEV fixture (or pass project, base URL, and page route arguments):

```bash
./scripts/test-grav-jarvis-admin-browser.sh
```

The harness copies the current Jarvis source and a test-only deterministic
provider into the fixture, creates a random temporary Admin2 account, drives
Chrome/Chromium through the actual Admin and page editor, then restores prior
plugins, account index, notifications, and cache state. It never uses a live
provider or credential. The eleven signed-in checks cover login, both Jarvis
surfaces, provider/model selection, validation, missing/unavailable provider,
typed rate-limit retry, API-token and provider-authority denial, all six action
identifiers, bounded exact whole-buffer context, proposal preview, explicit
Reject, Accept exactly once, stale/regeneration behavior, unsaved-only reload,
keyboard/labels, narrow layout, light/dark inheritance, no page mutation
request, browser console/page errors, and recent Jarvis fatal log entries.
In 0.3.0 the flaky-provider case succeeds through one automatic bounded retry
and must render `2 requests, 1 retry`; it no longer requires a second user-
initiated request.

The canonical DDEV release check additionally uses short-lived API keys that
are revoked by cleanup: an unrestricted key receives 200 from bootstrap,
provider validation, model discovery, current-page context, page-script,
panel-script, and context-panel registration; a key scoped only to
`api.access` receives 403 from both Jarvis Admin and page-context routes; an
anonymous request receives 401. Missing provider credentials produce a safe
`misconfigured` state without a live request. The signed-in deterministic
browser harness is the 0.2.1 black-box gate; the isolated component and direct
authenticated HTTP boundaries remain complementary release evidence.

Live-provider smoke is a separate explicit path:

```bash
GRAV_JARVIS_LIVE_SMOKE=1 ./scripts/test-grav-jarvis-live.sh openai
GRAV_JARVIS_LIVE_SMOKE=1 ./scripts/test-grav-jarvis-live.sh anthropic
```

Without `GRAV_JARVIS_LIVE_SMOKE=1`, the shell and PHP entry points both skip.
With opt-in but no selected provider credential, the result is also a clean
skip. A live run traverses the public service, registry, environment resolver,
bounded production transport, selected adapter, validation/discovery, and
provider-neutral completion result. It caps output and reports only provider/
model metadata, catalog size, usage availability, non-empty success, and
whether the optional short acknowledgement matched; prompt/response bodies and
credentials are never printed. Live smoke never replaces deterministic tests.

Future external contracts must prove that an unauthorized request is denied,
streamed events arrive in order and terminate cleanly, preview does not mutate
content, one explicit approval applies once, and a changed source rejects a
stale proposal. Consumer tests must continue to prove safe degradation when
Jarvis is missing, disabled, or lacks a requested capability.

The next optional-consumer contract must prove Grav Commander uses only public
Jarvis contracts and remains fully functional when Jarvis/provider/capability/
credential/budget paths are absent or fail. Later batch/job coverage must prove target and budget limits, cancellation,
idempotent resume, per-item permission/source rechecks, and truthful partial-
failure reporting. Live-provider smoke tests remain opt-in and budget-capped;
they do not replace deterministic release tests.

## Security-sensitive checks

- Signed links expire and do not reveal protected storage paths.
- ACL/password/limit checks run at delivery time, not only at page rendering.
- Range and HEAD requests do not inflate analytics.
- Remote URLs are validated and redirect with a restrictive referrer policy.
- User-controlled paths cannot escape configured roots.
- Generated image derivatives live outside the public root; only opaque plugin
  routes can deliver them, and building/purging never changes source hashes.
- Logs redact secrets and collection failures never weaken access checks.
- Disabled JavaScript leaves useful public HTML and no unauthorized URL.

Record package-specific regressions in its changelog and, when the failure
changes a durable rule, in an architecture decision.
