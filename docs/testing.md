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
| Spitfire theme | public routes, asset delivery, responsive navigation, light/dark and no-JavaScript rendering | required |

New coverage should be added in risk order. A repaired security or delivery bug
must not wait for the whole inventory before receiving its own regression.

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
