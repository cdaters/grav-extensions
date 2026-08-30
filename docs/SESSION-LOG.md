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
- Live 0.3.8 confirmation reached the server but returned `No route matches
  'POST /site-safeguard/stages/image-foundry-data/delete'` even after Grav's
  cache was cleared. The updated service version and dashboard were loaded,
  while the route response proved a long-running production PHP worker still
  held the preceding route registration. Grav cache invalidation cannot reset
  PHP-FPM's in-memory opcode state.
- Released Site Safeguard 0.3.9 with a narrowly matched rolling-update fallback:
  only an explicit missing-route response from the new POST action is retried
  through the pre-existing authenticated package/stage route using
  `X-HTTP-Method-Override: DELETE`. Authorization and contained service deletion
  remain unchanged; unrelated API errors are not retried.
- Extended the Admin UI contract with the exact production error and proved
  package and stage fallback requests use POST plus method override only after
  the explicit action route is absent. External DDEV probes returned 401 for
  both route forms, proving both arrive at the authentication boundary rather
  than failing route matching. Settings/version/mode, disposable cleanup,
  traversal denial, protected download, PHP/JavaScript syntax, repository
  hygiene, and public/Admin HTTP checks all remained green after ZIP install.
- Packaged `site-safeguard-0.3.9.zip`, SHA-256
  `7046c645312bebacd8d03a635efe9aa45a194a449cf83829ee507914b4d7ae66`.
  Preserved the superseded DDEV package
  `safeguard-localhost-portable_site-20260819-012539-d93801.zip` in the existing
  protected archive directory rather than deleting it at the retention limit.
- Created, independently inspected, and staged the final 0.3.9 recovery package
  `safeguard-localhost-portable_site-20260822-215150-d6aded.zip`, SHA-256
  `062214c0208cb34ec667759d48d147648fa05da5a3e79b8bdf2dde2b94a96f77`.
  It contains 6,800 checked files and 6,798 checksum records. Its verified,
  non-deployed stage is
  `safeguard-localhost-portable_site-20260822-215150-d6aded-f8fdac`; the running
  DDEV site was not modified.

- Live 0.3.9 testing found that **Create stage** still appeared inert after a
  successful inspection. The handler retained the same native `confirm()`
  dependency previously removed from deletion; restore still used native
  `prompt()` plus `confirm()` as well. If the browser did not complete those
  dialogs, no API request or visible error could exist.
- Independently checked the operator-supplied package
  `safeguard-spitfire-file-vault.ddev.site-portable_site-20260823-064154-dcf77e.zip`,
  SHA-256
  `9d28a2f9956f73afda29777169e2c596531e88b95b0fb7a4318505e735dd79b6`.
  ZIP integrity and its deployable 0.3.9 manifest passed; DDEV inspection
  verified 6,816 archive files and 6,814 checksum records. CLI staging created
  verified, non-deployed stage
  `safeguard-spitfire-file-vault.ddev.site-portable_site-20260823-064154-dcf77e-92afb1`,
  proving the package and backend staging engine were healthy.
- Released Site Safeguard 0.3.10. Create stage now uses visible two-click
  confirmation, while Restore opens an inline exact-phrase panel describing
  rollback-first preparation. Cancel, first clicks, and wrong phrases are
  non-mutating; no Admin action depends on native browser dialogs.
- Extended the Admin UI contract to forbid native dialog calls and verify stage
  arming, stage confirmation, restore arming, wrong-phrase denial, exact restore
  request bodies, and action-specific cancellation. ZIP-installed DDEV passed
  settings/version/mode, disposable cleanup, traversal denial, exact protected
  download, PHP/JavaScript syntax, repository hygiene, and public/Admin HTTP
  checks.
- Packaged `site-safeguard-0.3.10.zip`, SHA-256
  `c3c04f7329c14ca7cb28d98ff92a31cc03a4521bfe0620fe366aac08119320d1`.
  Preserved the superseded DDEV package
  `safeguard-localhost-portable_site-20260819-012632-56cb9e.zip` in the existing
  protected archive directory rather than deleting it at the retention limit.
- Created, independently inspected, and staged the final 0.3.10 recovery package
  `safeguard-localhost-portable_site-20260823-065733-349135.zip`, SHA-256
  `b88aed045b0775abf43138d337044d14e6db72f30b525958dfab526da5c96813`.
  It contains 6,817 checked files and 6,815 checksum records. Its verified,
  non-deployed stage is
  `safeguard-localhost-portable_site-20260823-065733-349135-457383`; the running
  DDEV site was not modified.

## 2026-08-23 — Site Safeguard disclosure-icon consistency checkpoint

- Traced the visibly mismatched expanded/collapsed disclosure controls to two
  separate Unicode characters (`⌃` and `⌄`). Browser fallback-font selection
  gave them different weight, proportions, and vertical alignment.
- Released Site Safeguard 0.3.11 with one shared inline SVG chevron. The closed
  control renders the original path and the expanded control rotates that same
  path 180 degrees, preserving identical geometry in every collapsible header.
- Extended the Admin UI contract to reject the old font-rendered characters and
  require the shared SVG path plus state-based rotation.
- Installed the packaged plugin into DDEV and cleared Grav cache. A temporary,
  local-only rendering fixture confirmed all four controls use the same 16 by
  16 pixel path and 1.75 pixel stroke; computed expanded state was exactly a
  180-degree transform. The fixture was removed immediately after the visual
  check.
- Passed Node syntax, the Admin UI behavior contract, repository hygiene,
  DDEV settings/version/mode checks, all Site Safeguard PHP 8.3 syntax checks,
  ZIP integrity, and public/Admin HTTP 200 checks.
- Packaged `site-safeguard-0.3.11.zip`, SHA-256
  `2d9c967f0b7360a0c85928740d577cbf43d86618d9820cbe11f503e8b9f5fd0d`.

## 2026-08-23 — Grav Commander blueprint parser repair

- Confirmed the production-downloaded `blueprints.yaml` was byte-identical to
  the canonical Grav Commander source and reproduced Grav's parser failure at
  line 222, column 195 with an independent YAML parser.
- Quoted the `backup.path` help scalar, whose embedded `Recommended:` text was
  invalid inside the previous unquoted value. A second parser pass exposed and
  repaired the same latent defect in the archive-name help text's `Useful
  tokens:` clause. The actual file contained no Markdown fences or malformed
  metadata URLs; those artifacts existed only in a chat-rendered paste.
- Added YAML syntax parsing for all plugin and theme `.yaml`/`.yml` files to the
  repository preflight, closing the validation gap that allowed the invalid
  blueprint to reach production.
- Recorded the repair in Grav Commander's traveling changelog and updated the
  repository validation guidance. The active File Vault black-box milestone is
  unchanged.

## 2026-08-25 — Flexible Markdown Alerts 1.0.1 added

- Added Flexible Markdown Alerts to the canonical suite as an independently
  installable Grav 2 plugin maintained by Craig Daters.
- Preserved GitHub-style `[!TYPE]` markers and added per-instance titles through
  `[!TYPE|Custom title]`, editable default definitions, new configured types,
  per-type colors, and configurable icons.
- Corrected the Grav Admin README's broken relative screenshot references and
  expanded the traveling documentation with complete syntax, configuration,
  migration, and SVG icon guides.
- Moved custom SVG ownership outside the upgradeable plugin to
  `user/data/flexible-markdown-alerts/icons`; a same-key site file safely
  overrides a bundled icon and removing it restores the fallback.
- Added optional `pack/icon` interoperability with Site Workshop's public Icon
  Bench renderer. No Site Workshop source was changed: Flexible Markdown Alerts
  degrades to a text title if the service is absent, disabled, or cannot resolve
  a reference, while Site Workshop remains unaware of the alert plugin.
- Exercised bundled, site-owned, Icon Bench, and missing-icon paths through a
  temporary public DDEV Markdown route. Repeated the request with Site Workshop
  disabled and confirmed HTTP 200 plus intact alert title/body output. Removed
  the temporary page, configuration rows, icon, and disable override afterward.
- Kept the active File Vault black-box milestone and unrelated uncommitted Site
  Workshop/Cache Hearth and Spitfire-theme work unchanged.

## 2026-08-25 — Spitfire modular section-spacing controls

- Released Spitfire theme 1.2.1 with an Admin **Section spacing** selector for
  Features, Text, and Form modular pages. Existing content defaults to Normal;
  Tight uses Quark 2's existing responsive `section-tight` utility; and Tighter
  uses `section-tighter`, defined at half of Tight's responsive padding.
- Kept spacing independent from the Features module's existing card-layout
  field, avoiding the invalid layout value and responsive-column regression
  that would result from overloading `header.class`.
- Documented that adjacent modules each contribute padding, so operators should
  select compact spacing on both sides when reducing an inter-section gap.
- Parsed configuration, page frontmatter, and page blueprints with Grav's YAML
  linter. Temporary DDEV selections proved Features and Text at 28px desktop
  edge padding for Tighter and Form at 56px for Tight; Home and Contact both
  returned HTTP 200. Removed all temporary content selections after testing.
- Passed repository preflight and ZIP integrity. Packaged
  `spitfire-1.2.1.zip`, SHA-256
  `02db9ba50293864e3545d19896dbbe01b8643f3e6ccd276c809b51b89ed1da4a`.
- Left production untouched and preserved unrelated local Site Workshop,
  Cache Hearth, and Spitfire theme edits outside this checkpoint.

## 2026-08-30 — Jarvis architecture and recovery checkpoint

- Accepted Jarvis (`grav-jarvis`) as a first-class planned Grav 2 AI-service
  and agent-integration framework through Decision 0004.
- Recorded why Jarvis is not a clone of Grav AI Pro: provider access and safe
  AI workflows live in Jarvis, while Grav's REST API and MCP server retain site
  operations, permissions, optimistic-concurrency conflicts, and API events.
- Specified OpenAI, Anthropic, and OpenAI-compatible providers first, with
  Gemini and OpenRouter deferred until the provider contract is stable.
- Defined Admin2 page/frontmatter/media awareness, versioned prompts,
  streaming, CLI, retries, privacy-safe caching, token/cost reporting,
  Grav-aware chunking, background jobs, batch workflows, and bounded MCP/agent
  composition.
- Made diff/preview/explicit approval, stale-source denial, optional Revision
  Ledger checkpoints, environment-only secrets, prompt-injection resistance,
  budgets, and black-box evidence release requirements.
- Named the `Grav\Plugin\GravJarvis` namespace, `$grav['gravJarvis']`,
  `JarvisServiceInterface`, and `onJarvisProviderRegister` as the planned
  public discovery/extension seam.
  Grav Commander is the first optional consumer candidate but retains its own
  permissions, containment, backups, and write authority and must work without
  Jarvis.
- Kept the planned extension under `docs/planned/grav-jarvis.md`; no empty or
  misleading `plugins/grav-jarvis` directory and no runtime secret were added.
- Set the exact next milestone to a minimal runnable 0.1.0 contract skeleton:
  package metadata, public interfaces/DTOs, registry, deterministic fake
  provider, service registration, and absence/failure/redaction/consumer tests
  before live providers or Admin2 work.
- Preserved unrelated local Site Workshop/Cache Hearth and Spitfire-theme
  changes. The prior File Vault black-box milestone remains required but is
  paused behind the newly selected Jarvis checkpoint.
- Passed repository structure/YAML/hygiene preflight, `git diff --check`, and
  local Markdown target validation. Confirmed canonical Markdown contains no
  the former working-slug identifier. Host PHP was unavailable, so preflight
  skipped PHP syntax; this documentation-only checkpoint adds no PHP.

## 2026-08-30 — Jarvis 0.1.0 contract foundation

- Added the runnable, independently packageable `plugins/grav-jarvis` plugin
  with Grav metadata, defaults, traveling README/changelog/license, Composer
  namespace metadata, and `$grav['gravJarvis']` service registration.
- Added provider-neutral public service, provider, and registry interfaces;
  immutable completion request/result/usage values; typed registry/provider
  exceptions; and `onJarvisProviderRegister`. Production boot registers no
  provider and performs no network request.
- Added a deterministic fake provider for contract tests only. Optional
  consumers now have a documented/tested fallback when Jarvis is absent,
  disabled, invalid, or a provider fails; no Grav Commander source changed.
- Added credential-key rejection and environment-aware redaction. Provider
  failures are normalized without chaining the unsafe exception, and both
  successful output and result metadata are redacted before leaving the public
  service.
- Added `scripts/test-grav-jarvis-contract.sh` and a seven-check contract for
  actual plugin/event registration, deterministic output, duplicate/missing
  providers, typed failures, optional-consumer fallback, credential rejection,
  and failure/success/result-metadata redaction.
- Passed the Jarvis contract and every Jarvis PHP syntax check under PHP 8.3 in
  the canonical DDEV fixture. Also passed repository structure/YAML/hygiene
  preflight, whitespace, Composer/JSON and YAML parsing, ZIP integrity,
  packaged-plugin installation, Grav cache clearing, and public/Admin HTTP 200
  checks. Host PHP remains unavailable, so root preflight correctly reports
  its host-side PHP step as skipped rather than claiming it ran.
- Packaged `grav-jarvis-0.1.0.zip`, SHA-256
  `b786a65de8a15551ace2a2c2164ac305ffabb74e25c711876a8535ca666b87ab`.
- Preserved unrelated local Site Workshop/Cache Hearth and Spitfire-theme
  changes and did not push. The exact next milestone is Jarvis 0.1.1: additive
  provider validation/model-discovery contracts, an environment credential
  resolver, deterministic local HTTP fixtures, and adapter-conformance/
  redaction tests before any live provider is added.

## 2026-08-30 — Jarvis 0.1.1 provider boundary

- Preserved the exact 0.1.0 method sets of `JarvisServiceInterface`,
  `ProviderInterface`, and `ProviderRegistryInterface`. Added optional provider
  validation/model-discovery interfaces and an additive introspection service
  interface, so existing providers and consumers remain compatible.
- Added provider-neutral validation issue/result and model descriptor/catalog
  DTOs. Model discovery exposes only opaque identifiers, labels, descriptions,
  availability, and capability slugs; raw provider response structures do not
  enter shared contracts.
- Added provider-scoped environment credential resolution. Only matching
  `GRAV_JARVIS_<PROVIDER>_*` process variables can be resolved; credential
  values are in-memory, non-serializable, debug-redacted, and usable in safely
  prefixed authentication headers without entering YAML, Admin fields, request
  DTOs, ordinary headers, logs, or persisted configuration.
- Added sanitized HTTP request/response/transport contracts. Credential-like
  ordinary headers and query parameters are rejected; diagnostic request forms
  retain only redacted credential markers plus raw-body byte counts/digests;
  raw responses refuse serialization.
- Added `FixtureHttpTransport` with no network fallback and
  `ConformanceFakeProvider` for deterministic validation, model discovery,
  HTTP success/error, malformed response, authentication, rate-limit,
  transport-failure, redaction, and offline-generation evidence.
- Expanded `scripts/test-grav-jarvis-contract.sh` to run both the seven-check
  0.1.0 compatibility contract and twelve-check 0.1.1 provider-boundary
  contract. All nineteen checks and every Jarvis PHP syntax check passed under
  DDEV PHP 8.3.31; host PHP remains unavailable and repository preflight
  truthfully reported its host-side PHP step as skipped.
- Passed repository structure/YAML/hygiene preflight, whitespace, Composer/JSON
  and YAML parsing, local Markdown target checks, ZIP integrity, 0.1.0-to-0.1.1
  packaged upgrade, final 0.1.1 packaged install, Grav cache clearing, public/
  Admin HTTP 200 checks, and relevant log inspection with no Jarvis/fatal/
  uncaught matches. Temporary DDEV plugin installs were removed afterward.
- Packaged `grav-jarvis-0.1.1.zip`, SHA-256
  `cb2e9fbbde7ba568e6fd0b2f5fa0cac3a14822a37c8c90a2919db512b11c25ed`.
- Selected OpenAI for the 0.1.2 first-live-provider checkpoint. Its official
  generation and model-list APIs provide one authoritative vendor contract to
  map behind Jarvis; beginning with a generic compatible adapter would let
  variable third-party compatibility claims shape the core prematurely.
- Preserved unrelated local Site Workshop/Cache Hearth and Spitfire-theme
  changes and did not push. The exact next action is the bounded production
  HTTP transport plus OpenAI validation, model discovery, and synchronous
  generation, with deterministic fixtures required and live smoke tests
  opt-in/budget-capped.

## 2026-08-30 — Jarvis 0.1.2 bounded transport and OpenAI provider

- Re-read the canonical handoff, roadmap, architecture, security, testing,
  Jarvis specification/ADR, package manual, session history, and exact 0.1.0/
  0.1.1 contracts before implementation. Verified the official OpenAI Models
  list, Responses create/output/usage, bearer-authentication, and error-status
  documentation current on this date.
- Preserved the exact method sets of `JarvisServiceInterface`,
  `ProviderInterface`, and `ProviderRegistryInterface` and all additive 0.1.1
  contracts. A contract scan proves OpenAI endpoint, output, and usage terms do
  not enter shared DTOs or interfaces.
- Added a provider-neutral bounded production HTTP transport. It requires exact
  HTTPS origin/base-path allowlisting, rejects literal and non-public/mixed DNS
  destinations, pins a validated address against rebinding, verifies TLS,
  disables redirects and environment proxies, rejects transport-controlled
  headers, and bounds connection time, total time, request bytes, response
  headers, and decompressed response bytes. Diagnostics contain no request/
  response body or credential values.
- Added the isolated official `openai` adapter. It uses fixed
  `https://api.openai.com/v1`, resolves only
  `GRAV_JARVIS_OPENAI_API_KEY`, validates/discovers through `GET /models`, and
  completes through `POST /responses`. It defaults to `gpt-5.6-luna`, honors
  the neutral model override, sets provider storage false, rejects unsupported
  options, normalizes provider-reported token usage, and exports no raw vendor
  metadata.
- Registered OpenAI by default in `$grav['gravJarvis']` while keeping boot
  network-free and credential-lazy. Missing/malformed credentials,
  configuration, provider absence, and provider failure continue to degrade
  through the existing typed validation/service boundaries. No Admin2
  assistant, Commander integration, CLI, job, streaming, content mutation, or
  MCP surface was added.
- Added deterministic DNS/executor fixtures and nine 0.1.2 checks. Together
  with the seven 0.1.0 and twelve 0.1.1 checks, all twenty-eight pass under
  DDEV PHP 8.3.31 with no network fallback. Coverage includes success/models/
  usage, default and explicit models, missing/malformed credentials and config,
  empty/malformed JSON and output, 401, 429 with retry guidance, other 4xx,
  5xx, timeout/transport failure, SSRF/destination controls, redaction, and
  generic service routing through the bounded adapter.
- Passed every Jarvis PHP syntax check under DDEV, confirmed the cURL extension,
  repository structure/YAML/hygiene preflight, Composer/JSON and YAML parsing,
  local Markdown targets, whitespace, ZIP integrity, credential-pattern scans,
  0.1.1-to-0.1.2 packaged upgrade, fresh packaged install, Grav cache clearing,
  public/Admin HTTP 200 checks, and relevant log inspection with no Jarvis/
  fatal/uncaught matches. Host PHP remains unavailable, so preflight correctly
  recorded that host-side syntax was skipped. No live OpenAI account request
  was needed or attempted.
- Packaged `grav-jarvis-0.1.2.zip`, SHA-256
  `0179b155936800ebd9376c41a16283ae7e38951e4c051dd493d09904134d85ea`.
  The versioned 0.1.0 and 0.1.1 packages remain in `dist/`.
- Evaluated the optional 0.1.3 stretch after the 0.1.2 gates passed and kept it
  as a separate checkpoint. A generic compatible provider needs its own
  capability/compatibility claims, instance configuration, and multiple
  partial-server fixtures; folding those judgments into the first live-adapter
  commit would reduce reviewability.
- Preserved unrelated local Site Workshop/Cache Hearth and Spitfire-theme work
  and did not push. The exact next milestone is Jarvis 0.1.3: a separate generic
  OpenAI-compatible provider using the bounded transport, a deliberate public
  HTTPS base URI, environment-only instance credentials, truthful declarative
  capabilities, and full/partial/incompatible offline fixtures.
