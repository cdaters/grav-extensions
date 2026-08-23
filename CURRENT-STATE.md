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

Site Safeguard 0.3.10 closes the protected-download, shared-host restore, and
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
green, amber, or red presentation.

Decision 0003 establishes external black-box contract coverage as a suite-wide
rule. `docs/testing.md` contains the risk-ordered coverage inventory.

## Current quality evidence

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

Expand Decision 0003 coverage across the suite in risk order without coupling
the plugins. File Vault protected delivery is the next candidate because it
shares the highest-risk authentication, authorization, range, analytics, and
external-storage boundaries.

## Exact next action

Design a disposable DDEV black-box contract for File Vault that proves
anonymous denial, authorized delivery, ACL/password/download-limit enforcement,
range/resume behavior, analytics counted once at the correct boundary, and
byte-identical delivery from protected storage. Do not weaken or replace File
Vault's independent public interface to share Site Safeguard code.

## Explicitly deferred

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
4. Run `git status` and `git log --oneline --decorate -10`.
5. Confirm the active milestone, exact next action, deferred work, and local
   uncommitted changes before modifying files.
