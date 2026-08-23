# Changelog

## 0.3.11 — 2026-08-23

- Replace the disclosure controls' separate font-rendered up/down characters
  with one shared inline SVG chevron. Expanded state rotates the same path, so
  all four collapsible headers retain identical weight, size, and alignment.
- Extend the Admin UI contract to reject the old Unicode glyphs and require the
  shared SVG plus state-based rotation.

## 0.3.10 — 2026-08-22

- Remove the remaining native browser dialog dependency from **Create stage**.
  The first click now arms only that inspected package and presents an in-page
  **Confirm stage** action; Cancel leaves the package and running site intact.
- Replace restore's native `prompt()` plus `confirm()` sequence with a visible
  stage-row confirmation panel. The operator must deliberately open it, type
  the exact restore phrase, and select **Confirm restore** before the API can be
  called.
- Extend the Admin UI contract to forbid native dialog calls, prove the first
  stage/restore clicks and a wrong restore phrase are non-mutating, verify
  cancellation, and confirm the exact authorized request bodies.

## 0.3.9 — 2026-08-22

- Recover automatically when Admin2 has loaded the updated dashboard but a
  long-running production PHP worker still exposes Site Safeguard's preceding
  API route table. A missing explicit POST action route is retried through the
  established authenticated package/stage route with
  `X-HTTP-Method-Override: DELETE`.
- Extend the Admin UI contract with the production-observed stale-route
  response and prove both package and stage deletion reach the compatible
  fallback without weakening two-click confirmation or authorization.

## 0.3.8 — 2026-08-22

- Replace native browser confirmation dialogs for package and stage deletion
  with visible, reversible two-click controls in Admin2. The first click arms
  only the selected item; **Cancel** or navigating away removes that intent.
- Add explicit POST deletion actions while retaining the REST-style DELETE
  routes, avoiding shared-host/CDN rejection of mutation verbs before Grav can
  authorize and process the request.
- Make Environment Readiness, Package Library, Isolated Staging, and Recovery
  Journal collapsible with keyboard-operable semantic controls and remembered
  per-browser preferences.
- Give Environment Readiness an accessible green, amber, or red aggregate
  header state with a textual result. Attention states open by default, while
  a healthy readiness panel and an all-complete journal start collapsed.
- Add an Admin UI behavior contract covering two-click deletion, POST action
  routes, disclosure defaults, persistence, and readiness status presentation.

## 0.3.7 — 2026-08-22

- Retry blocked `DELETE`, `PATCH`, and `PUT` requests as the API's supported
  `POST` plus `X-HTTP-Method-Override` form, restoring package/stage cleanup on
  shared hosts that reject mutation verbs before PHP receives them.
- Classify directories without a valid stage record as **Unrecognized** rather
  than presenting them as an unknown recovery package. They are explicitly not
  restorable and use a distinct **Remove directory** action and warning style.
- Bring action failures into view as accessible alerts instead of leaving the
  explanation above a deeply scrolled Package Library or Isolated Staging row.
- Add a disposable staging-root regression that proves unrecognized-directory
  classification, recursive cleanup, and parent-traversal denial.
- Normalize intentionally web-deliverable restored files so CSS, JavaScript,
  fonts, images, and page media remain readable by a split LiteSpeed/nginx
  static worker even when a DDEV/macOS source mount records them as `0600`.
  Private configuration and data files retain their original modes.

## 0.3.6 — 2026-08-22

- Highlight the selected **Enabled** state for both restore switches in Admin2.
  Version 0.3.5 highlighted the safe default (**Disabled**) instead, so a
  successfully persisted enablement appeared gray and looked as though Save
  had turned it off.
- Document Admin2's saved-override marker and the reload check that distinguishes
  a transient form-state display from a configuration write failure.
- Normalize and deduplicate the editable preserve/exclude path lists whenever
  Site Safeguard settings are saved. Runtime path validation and mandatory
  preservation remain independently enforced.
- Keep the dashboard service version and JavaScript fallback synchronized with
  the package blueprint, and verify that contract in a fresh Grav process.

## 0.3.5 — 2026-08-22

- Bind the protected package-directory locator into the HMAC-signed ticket so
  an Admin2 request using the base/Default configuration and a public download
  request using the hostname environment resolve the same verified ZIP.
- Return a root-relative download route and have Admin2 explicitly retain the
  browser's current origin, preventing a restored production canonical URL from
  sending a DDEV package ticket to the live site (or vice versa).
- Bind newly issued tickets to the actual request host and reject cross-host
  replay even if two restored installations temporarily share a Grav nonce key.
- Always preserve `user/config/security-private.php` at the destination during
  restore so future transfers keep each host's nonce/HMAC identity local.
- Re-resolve and containment-check the signed directory on every download; it
  must exist outside the public Grav root and the requested basename must still
  be a retained ZIP.

## 0.3.4 — 2026-08-22

- Start the signed download directly after the authenticated ticket request.
  Safari can report a generic `Load failed` network exception when JavaScript
  probes an attachment response with `fetch(HEAD)`, even though the signed URL
  itself is valid; the probe is unnecessary now that tickets are stateless and
  reusable until expiry.

## 0.3.3 — 2026-08-22

- Replace filesystem-backed download tokens with short-lived, HMAC-signed
  stateless tickets so a LiteSpeed worker change, shared-host process split, or
  token-file visibility issue cannot invalidate a newly created link.
- Retain compatibility with unexpired 0.3.1/0.3.2 tokens during an update.
- Verify a ticket with a non-consuming HEAD request before moving the browser
  into the download and keep failures inside the Site Safeguard dashboard.
- Attach a safe reference code to download failures and record the detailed
  cause in the Grav log without logging the ticket or protected file path.

## 0.3.2 — 2026-08-22

- Keep protected download tickets valid until their short configured expiry so
  browser HEAD probes no longer consume the authorization before the real GET.
- Add single-range HTTP responses and `Accept-Ranges` headers so large recovery
  packages can resume without restarting from byte zero.
- Start Admin2 downloads through a temporary same-origin download link instead
  of navigating the operator away from the recovery dashboard.
- Expose the bounded 30–900 second download-link lifetime in plugin settings.

## 0.3.1 — 2026-08-19

- Added direct navigation from the Site Safeguard dashboard to plugin settings.
- Made the recovery dashboard reliably follow Admin2 light and dark mode,
  including installations that expose their theme through computed colors.

## 0.3.0 — 2026-08-19

- Add a guarded **Restore** action to Admin2 while retaining the manual CLI
  recovery path.
- Queue each Admin restore as a uniquely identified operation and launch the
  existing rollback-first restore command as a detached PHP CLI worker instead
  of replacing the running site inside an HTTP request.
- Require separate `site-safeguard.restore` permission, independently disabled
  Admin-launch setting, exact typed confirmation, and a final browser warning.
- Detect unsupported launch environments and keep the CLI command visible as
  the fallback.
- Poll a protected recovery journal and display queued, validation, rollback,
  maintenance, copy, verification, completion, failure, and automatic-rollback
  progress in Admin2, including bounded worker output on failure.
- Add a live environment-readiness panel distinguishing current minimum,
  recommended, optional, and restore-only host capabilities, including clear
  Sodium/Zlib/OpenSSL guidance for the future archive formats.
- Reserve `.ssa` and `.sss` as documented, benchmark-gated future streaming and
  authenticated-encryption formats while keeping ZIP as the compatibility
  baseline.

## 0.2.3 — 2026-08-19

- Keep isolated stage directories private while normalizing restored,
  non-preserved Grav directories to web-traversable `0755` permissions.
- Verify restored directory traversal permissions and fail into the automatic
  rollback path if a hosting platform refuses the normalization.
- Record the number of normalized directories in restore and rollback journals.

## 0.2.2 — 2026-08-18

- Display the embedded operator note directly in every recovery-package card
  and in the expanded inspection results, including an explicit empty state.

## 0.2.1 — 2026-08-18

- Supply the required resource location when returning HTTP 201 responses so
  package creation, import, and staging work with the released Grav API plugin.

## 0.2.0 — 2026-08-18

- Add CLI-only, rollback-first full-site restore from verified deployable stages.
- Automatically create, stage, checksum, and fresh-process boot a rollback package before maintenance mode begins.
- Preserve configurable host-local paths such as `.ddev`, environment files, the destination Site Safeguard configuration, runtime caches/logs, and external File Vault storage.
- Mirror the staged site, remove stale non-preserved entries, and verify restored file content before completion.
- Record file permission modes and modification times and restore executable Grav CLI commands correctly.
- Add isolated pre-restore and post-restore Grav boot health checks with automatic rollback on failure.
- Persist protected restore journals and serialize restore operations with a non-blocking lock.
- Narrow runtime cache exclusions to the Grav root so Composer packages such as `vendor/symfony/cache` remain portable.
- Document the standalone Recovery Assistant, scheduler, encrypted off-site storage, and external-data-set roadmap.

## 0.1.0 — 2026-08-18

- Create independently portable full-site, user-folder, and pages/media ZIP packages.
- Record package metadata and a per-file SHA-256 manifest.
- Exclude caches, logs, backups, development metadata, macOS debris, and environment secrets by default.
- Import packages through a guarded Admin2 upload surface.
- Inspect ZIP structure, entry count, expanded size, traversal attempts, duplicate entries, metadata, and every file checksum.
- Extract valid deployable packages only into a unique directory outside the running Grav root.
- Re-verify staged files after extraction.
- Provide one-time, short-lived package download links.
- Serialize token consumption and keep token state outside the backup source.
- Enforce explicit retention, compressed-size, expanded-size, and entry-count limits without auto-pruning packages.
- Provide Admin2 and CLI create/inspect/stage workflows.
- Reserve live promotion permission and UI state without implementing live replacement.
