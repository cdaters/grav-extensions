# Grav Extensions session log

This is an append-only chronology of meaningful work, discoveries, tests, and
checkpoints. `CURRENT-STATE.md` remains authoritative for the active state.

## 2026-08-22 — Site Safeguard protected-download boundary and continuity checkpoint

- Reproduced the original download failure in DDEV: a browser HEAD request
  consumed Site Safeguard's one-use token before the real GET.
- Released the first repair with reusable bounded tickets and HTTP range
  support, then used production-visible safe references to separate subsequent
  failures instead of treating every error as an expired token.
- Replaced filesystem token state with HMAC-signed stateless tickets so PHP or
  LiteSpeed worker changes cannot lose authorization between requests.
- Removed a JavaScript `fetch(HEAD)` attachment probe after Safari surfaced it
  as a generic `Load failed` network exception before the actual download.
- Diagnosed `SS-DL-02`: a ticket created from DDEV was sent to
  `spitfirebbs.com` because the restored site retained production canonical URL
  state. The live server correctly could not find the DDEV package named in the
  valid ticket.
- Corrected the API contract to return a root-relative route and made Admin2
  retain `window.location`'s origin. DDEV now stays on
  `spitfire-file-vault.ddev.site`; production stays on `spitfirebbs.com`.
- Bound new tickets to the actual request host and made the destination Grav
  nonce/HMAC key an always-preserved restore path, adding defense if two
  installations temporarily share a key after an older transfer.
- Bound the issuing protected-directory locator into the signed ticket and
  revalidated its outside-webroot containment at delivery, closing the
  base/Admin versus hostname/public configuration-scope split.
- Added Decision 0003, the suite coverage inventory, a DDEV black-box runner,
  `AGENTS.md`, and `CURRENT-STATE.md` after reviewing Spitfire-NG's durable
  continuity pattern. The repository, not conversation memory, is now the
  canonical handoff.
- Verified PHP and JavaScript syntax, root-relative URL generation,
  cross-environment resolution, external HTTP 200 HEAD, external HTTP 206 range
  delivery, exact ZIP hashes, modified-ticket denial, repository hygiene, ZIP
  integrity, package inspection, and isolated staging.
- Created and verified portable package
  `safeguard-localhost-portable_site-20260822-204008-fc93f4.zip` with SHA-256
  `9ce11f46492854fd6c8bebfc9809c6d2da0386310a9bac2d61f4753572c39d8f`;
  6,800 files were checked, 6,798 checksum records matched, and the verified
  stage is `safeguard-localhost-portable_site-20260822-204008-fc93f4-43be53`.
- Archived superseded intermediate DDEV packages instead of deleting them.

### Next action

Add File Vault's independent protected-delivery black-box contract, beginning
with denial/authorization and exact-byte/range behavior. Keep the remaining
suite inventory incremental and risk ordered.

## 2026-08-22 — Site Safeguard restore-toggle presentation repair

- Confirmed from a fresh live Admin2 settings load that both restore settings
  had persisted; the orange markers were Admin2's saved-override indicators.
- Traced the apparent reset to Site Safeguard's blueprint: both dangerous-by-
  default switches used `highlight: 0`, so Admin2 colored **Disabled** purple
  and rendered a successfully selected **Enabled** state gray. This looked like
  the setting had turned itself off even though the saved value was true.
- Changed both restore switches to `highlight: 1` in Site Safeguard 0.3.6 so
  the enabled selection follows the same visual convention as the surrounding
  settings. The underlying default remains disabled and every restore guard is
  unchanged.
- Reviewed the current public Grav Admin2 and Form issue trackers. No reported
  issue matched this restore-toggle behavior or the earlier raw contact-field
  attribute regression; the toggle problem was local blueprint presentation,
  not a known upstream persistence failure.
- Documented the fresh-load verification procedure and the meaning of the
  orange override marker in the plugin README and current-state handoff.
- Added a read-only DDEV settings contract. A fresh Grav process confirmed both
  live-style DDEV settings enabled, disabled-by-default blueprint behavior,
  enabled-state highlighting, and path-list normalization. The existing
  same-origin/range/exact-byte download contract also remained green.
- Corrected a release-metadata mismatch found from the DDEV dashboard: the
  blueprint was 0.3.6 but the service status constant and JavaScript fallback
  still reported 0.3.5. The settings contract now refuses any future mismatch
  between the blueprint and dashboard-reported versions.
- Verified all Site Safeguard PHP in DDEV, the repository preflight, the ZIP
  archive, DDEV home/Admin responses, and all three documentation routes.
  The initial 0.3.6 archive was superseded after the dashboard-version mismatch
  was found. The corrected `site-safeguard-0.3.6.zip` has SHA-256
  `31d51b0aeecd077ae2963d4649946044cb1f091630199c7e2700152c7a644d52`.
- Preserved the superseded localhost package
  `safeguard-localhost-portable_site-20260818-231120-89a778.zip` by moving it
  from the 12-item active library into `/var/www/site-safeguard-packages-archive`;
  nothing was deleted.
- Created and independently inspected the initial 0.3.6 DDEV recovery package
  `safeguard-localhost-portable_site-20260822-205443-ec1c47.zip`, SHA-256
  `344fe550df3d2fe14908f141700fcd56e8c5f15a366160a58cb21d02fdb52ba7`.
  It contains 6,800 checked files and 6,798 checksum records. Its verified,
  non-deployed stage is
  `safeguard-localhost-portable_site-20260822-205443-ec1c47-763400`; creating
  the stage did not modify the running site. The package was later moved to the
  protected archive when the display-version mismatch made it obsolete.
- Rebuilt, independently inspected, and staged the corrected final recovery
  package `safeguard-localhost-portable_site-20260822-210027-1328e6.zip`,
  SHA-256
  `5d278172cfd21c59c4b44e02e88ccc3407ab1b1a340085d0a0dcc1e4f4caf850`.
  It contains 6,800 checked files and 6,798 checksum records. Its verified,
  non-deployed stage is
  `safeguard-localhost-portable_site-20260822-210027-1328e6-8c6e14`; the
  running site was not modified.
- Recorded the next-version Admin2 disclosure design: Environment Readiness,
  Package Library, Isolated Staging, and Recovery Journal become collapsible;
  healthy/empty sections may start collapsed, while warning, failure, active,
  or unrecognized states open automatically. Environment Readiness receives an
  accessible green/amber/red aggregate header treatment with text and icon.
- Diagnosed the first live restore's unstyled public page from outside the
  server: the HTML returned HTTP 200, while restored Quark2, Spitfire, Image
  Foundry, Prism Gallery, and Lantern Search CSS/JavaScript returned LiteSpeed
  HTTP 403. The recovery manifest proved the failed public assets were recorded
  as mode `0600`; a working Form stylesheet was `0644`.
- Released Site Safeguard 0.3.7 to add missing read bits only to intentionally
  web-deliverable restored files. Private configuration/data retains its source
  mode. Directory normalization from the earlier split-process repair remains
  enforced independently.
- Added API mutation-method fallback for shared hosts, visible accessible action
  errors, explicit unrecognized/non-restorable staging-directory presentation,
  and a distinct Remove directory action. This repairs the live symptom where
  clicking Delete stage appeared to do nothing.
- Verified PHP and JavaScript syntax, repository hygiene, settings/version/mode
  policy, disposable unrecognized-directory cleanup and traversal denial, and
  the existing external download contract. Packaged `site-safeguard-0.3.7.zip`
  with SHA-256
  `70eafa30dcdc95fbdc0708702a91b9e2771c7ff6f0d3c6e982488e4cc09cf621`.
- Preserved the superseded 0.3.6 recovery ZIP in protected archive storage,
  then created, independently inspected, and staged
  `safeguard-localhost-portable_site-20260822-212126-ca6c9c.zip`, SHA-256
  `a2a9ce8ffb8ef094a2441128a9fdacf54ecedc7b28a040c3ee55bb5e9c847c5d`.
  It contains 6,800 checked files and 6,798 checksum records. Its verified,
  non-deployed stage is
  `safeguard-localhost-portable_site-20260822-212126-ca6c9c-581e2d`; the
  running DDEV site was not modified.
- Follow-up live testing showed that every package/stage delete control in
  Site Safeguard 0.3.7 still appeared inert. All destructive controls depended
  on a native browser confirmation dialog before any request was sent, and the
  dashboard still preferred a raw HTTP `DELETE` request. The 0.3.7 retry could
  not help when the dialog never completed or when an edge rejected a method
  with a status other than the single handled response.
- Released Site Safeguard 0.3.8 with visible two-click package/stage removal,
  a local Cancel action, and explicit authenticated POST deletion routes while
  retaining REST-style DELETE routes for compatible API clients. The first
  click is now provably non-mutating and only arms the selected row.
- Implemented the planned collapsible Environment Readiness, Package Library,
  Isolated Staging, and Recovery Journal sections. Browser preferences persist;
  attention-aware defaults expose warnings and active/failed work. Readiness
  uses an icon, result text, and green/amber/red aggregate treatment.
- Added `scripts/test-site-safeguard-admin-ui.sh` and its isolated component
  harness. It verifies two-click deletion, Cancel, POST action paths,
  disclosure defaults/persistence, and readiness states. The DDEV cleanup
  contract again removed a disposable unrecognized directory and denied parent
  traversal. An external unauthenticated POST reached the new deletion route
  and returned 401, confirming route registration plus the auth boundary.
- Installed the packaged 0.3.8 ZIP over 0.3.7 in DDEV, cleared Grav caches, and
  passed PHP syntax, JavaScript syntax, repository hygiene, settings/version/
  mode, stage cleanup, protected download, Admin UI, public HTTP 200, and Admin
  HTTP 200 checks. The in-app browser reached the DDEV Admin login cleanly but
  had no signed-in session, so authenticated visual click-through remains a
  short operator confirmation after upload.
- Packaged `site-safeguard-0.3.8.zip`, SHA-256
  `8b8a54570f7a6c4f6b790d396950414200cc5324c0ba78d48f23ad5ce4342611`.
  To honor the 12-package hard stop without deletion, moved the superseded
  `safeguard-localhost-portable_site-20260819-003204-2f5ff6.zip` into the
  existing protected archive directory.
- Created and independently inspected the final DDEV handoff package
  `safeguard-localhost-portable_site-20260822-214109-3fc01e.zip`, SHA-256
  `821e9947f49e048114e539b3636d8f70551c922f077d38478a5d91121f4e7761`.
  It contains 6,800 checked files and 6,798 checksum records. Its verified,
  non-deployed stage is
  `safeguard-localhost-portable_site-20260822-214109-3fc01e-499bb8`; the
  running DDEV site was not modified.
