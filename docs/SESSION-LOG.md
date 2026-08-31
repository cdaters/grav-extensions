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

## 2026-08-30 — Jarvis 0.1.3 compatible-provider proof

- Continued only after the complete 0.1.2 release matrix passed and preserved
  that checkpoint in its own commit (`44b4571`). The compatible-provider work
  is a separate additive increment; no 0.1.0/0.1.1 public contract or official
  OpenAI behavior changed.
- Added a distinct `OpenAICompatibleProvider`, not an endpoint mode on the
  official provider. Named instances carry only a stable provider ID,
  operator-selected public HTTPS base URI, provider-scoped environment-
  variable name, default model, and optional model-discovery declaration.
  Instances are disabled by default; secret values and per-request endpoints
  are not configuration fields.
- Defined the exact 0.1.3 compatibility claim: `POST /responses` with model,
  string input, optional instructions, and remote storage disabled; text through
  Responses output text; optional token usage; and `GET /models` only when
  declared. Chat Completions-only, empty, incomplete, malformed, and other
  partial shapes fail closed.
- Reused the 0.1.2 bounded transport. Compatible endpoints must remain public
  HTTPS and receive the same base-path, DNS/public-address pinning, TLS,
  redirect/proxy, header, time, and size protections. Private/local endpoints
  remain unsupported.
- Capability metadata reports only text completion, provider validation, and
  optional model discovery. No streaming, structured-output, or tool-calling
  support is claimed. A no-discovery instance validates local configuration and
  credential presence with an explicit warning that remote non-generating
  validation was unavailable.
- Added six deterministic checks for full compatible service operation,
  multi-instance endpoint/credential separation, no-discovery behavior,
  missing declared endpoints, multiple incompatible response types, rate
  guidance, cross-instance credential denial, and private-address denial.
  All thirty-four Jarvis checks pass under DDEV PHP 8.3.31 with no network
  fallback.
- Passed DDEV PHP syntax and cURL checks, repository preflight, Composer/JSON/
  YAML/Markdown/whitespace and credential-pattern checks, ZIP integrity,
  packaged 0.1.2-to-0.1.3 upgrade, fresh 0.1.3 install, Grav cache clearing,
  public/Admin HTTP 200 checks, and clean relevant log inspection. Host PHP is
  still unavailable and is not claimed; no live provider request was attempted.
- Packaged `grav-jarvis-0.1.3.zip`, SHA-256
  `4c2c20996f0a46dac704beaa6db91f7b5fa1f03aec251e5d0ac4075fccea4f0c`.
  Earlier versioned archives remain in `dist/`.
- Preserved unrelated local Site Workshop/Cache Hearth and Spitfire-theme work
  and did not push. The exact next milestone is Jarvis 0.1.4: an isolated
  Anthropic adapter as the second first-party wire family, using current
  official APIs, environment-only credentials, bounded HTTP, provider-neutral
  normalization, and deterministic error/redaction fixtures.

## 2026-08-30 — Jarvis 0.1.4 Anthropic provider foundation

- Re-read the canonical handoff, architecture, roadmap, security/testing
  guidance, Jarvis specification/ADR/manual, session history, and every frozen
  contract and existing provider implementation before editing. Re-verified
  Anthropic's official direct API authentication/version, Models pagination,
  Messages request/response/usage, error statuses, and current model guidance;
  re-verified OpenAI's official bounded Responses output parameter for the
  opt-in smoke path.
- Added the isolated official `anthropic` adapter using only
  `https://api.anthropic.com/v1`, `anthropic-version: 2023-06-01`, environment-
  only `GRAV_JARVIS_ANTHROPIC_API_KEY`, `GET /models`, and `POST /messages`.
  Model/display/capability data, ordered text blocks, and reported input/output
  usage normalize into existing neutral DTOs; headers, messages, content/stop
  blocks, raw errors, request IDs, and other vendor fields stay adapter-private.
- Extended the bounded transport to allow only bounded non-secret queries on
  an already allowlisted HTTPS path, while credential query keys remain denied.
  Anthropic model discovery requests the official maximum page size and follows
  at most four validated opaque cursors before failing closed, so provider
  pagination does not leak into the shared catalog contract.
- Added the neutral `max_output_units` option to both first-party adapters.
  OpenAI maps it privately to its Responses output cap; Anthropic maps it to
  the required Messages output cap and otherwise defaults to 1,024 units. Other
  options and invalid bounds fail clearly. No provider-specific field entered
  a shared DTO or interface.
- Proved the 0.1.0/0.1.1 compatibility baseline byte-for-byte: all six public
  service/provider/registry/introspection/validation/discovery interface hashes
  match 0.1.3. Plugin boot now registers `openai` and `anthropic` lazily without
  credential resolution or network activity; compatible instances remain
  separate and disabled by default.
- Added ten deterministic Anthropic checks. Together with seven 0.1.0, twelve
  0.1.1, nine OpenAI, and six compatible-provider checks, all forty-four pass
  under DDEV PHP 8.3.31 with no network fallback. Coverage includes bounded
  headers/destination, cursor discovery, validation, models/capabilities,
  completion with and without usage, multiple content blocks, empty/malformed/
  incomplete output, missing/malformed/cross-provider credentials and config,
  auth/rate/other 4xx/5xx/timeout failures, redaction, and frozen contracts.
- Added a separate live-smoke harness for OpenAI or Anthropic through the public
  service, registry, environment resolver, bounded production transport, live
  adapter, validation/discovery, and neutral completion result. Both shell and
  PHP paths require `GRAV_JARVIS_LIVE_SMOKE=1`; output is capped and no prompt,
  response, credential, or authorization header is printed. Both providers
  cleanly skipped because no corresponding key existed on host or DDEV; no live
  request or API charge occurred.
- Passed repository preflight (host PHP truthfully skipped), DDEV PHP syntax and
  cURL/runtime checks, Composer/JSON/YAML/Markdown/whitespace/hygiene and
  credential scans, ZIP integrity, final packaged 0.1.3-to-0.1.4 upgrade, fresh
  0.1.4 installation, Grav cache clear, public/Admin HTTP 200, and clean recent
  log inspection. The fixture was restored to its prior state with no Jarvis
  installation.
- Packaged `dist/grav-jarvis-0.1.4.zip`, SHA-256
  `242fef895caaf6ea926a17ad0e711f7801d2f39e0779bf67db487e3c82d02a8b`.
  Versioned 0.1.0 through 0.1.3 archives and hashes remain intact.
- Made Jarvis 0.2.0 the exact next milestone: a permission-filtered Admin2
  Jarvis/sidebar and page-editor surface with provider/model selectors,
  validation state, prompt/response, inspectable bounded current-page context,
  Rewrite/Proofread/Shorten/Expand/Summarize/Custom actions, diff preview, and
  explicit Accept/Reject into the unsaved editor buffer. It must recheck page
  permission/source hash, never auto-save, and leave Admin2 usable when Jarvis
  or a provider is absent or fails.
- Preserved unrelated local Site Workshop/Cache Hearth and Spitfire-theme work.
  No Gemini/OpenRouter, CLI commands, streaming, tools, structured output,
  jobs, MCP, Commander integration, site-wide mutation, push, tag, or publish
  was performed.

## 2026-08-30 — Jarvis 0.2.0 first user-usable Admin2 release

- Re-read the canonical handoff, Jarvis specification/ADR/manual, roadmap,
  architecture, security/testing guidance, recent history, every frozen public
  contract, every provider/transport/security implementation, and current API/
  Admin2 source before editing. Preserved unrelated Site Workshop/Cache Hearth
  and Spitfire-theme work.
- Added a permission-filtered Jarvis Admin2 sidebar page with provider/model
  selection, safe validation and missing-credential state, general prompt/
  response, provider usage, loading, error, retry, and graceful absent/no-
  provider behavior. Browser code calls only fixed authenticated Jarvis API
  routes with no-store and browser credentials omitted; it cannot supply a
  provider class, URL, header, endpoint, or environment name.
- Added the native `onApiContextPanels` page-editor panel and the six versioned
  provider-neutral actions: Rewrite, Proofread, Shorten, Expand, Summarize, and
  Custom Prompt. It reads the current unsaved Markdown only through Admin2's
  public get/content-response events and accepts only through replace-buffer.
  Admin2 2.1.2 has no stable selection contract, so whole-buffer editing is
  explicit and selection-aware behavior is deferred.
- Added deterministic 49,152-byte current-content, 8,192-byte frontmatter, and
  4,096-byte/32-item media-metadata bounds plus a 65,536-byte reviewable-output
  limit. Secret-like keys and known `GRAV_JARVIS_*` values are redacted; media
  bytes/paths and unrelated pages are excluded. Content/output over the review
  boundary is visibly preview-only.
- Added a before/after proposal flow and 15-minute one-time private receipt
  containing only actor/route/source/proposal hashes and expiry. Access/use/
  approve permissions, API key scopes, page read/update ACL, current unsaved-
  buffer hash, proposal hash, expiry, and one-time consumption are rechecked.
  Reject is non-mutating; Accept changes only the unsaved editor buffer. No
  Jarvis route or component saves, publishes, deletes, executes, or persists
  prompt/page/provider-output content.
- Preserved all six public interface files byte-for-byte. The original forty-
  four provider checks plus seven Admin backend and seven browser-component
  checks all pass (fifty-eight total). New coverage proves all six actions,
  provider/model/status, bounded/redacted context, hash-only receipts, accept
  once/replay/stale denial, truncation, same-API browser transport, response
  escaping, graceful absence, current-buffer events, Replace-only Accept,
  Reject, and no save/publish/provider-browser path.
- Passed DDEV PHP 8.3.31 syntax/runtime and cURL checks, Node syntax, Composer/
  JSON/YAML validation, repository preflight (host PHP truthfully skipped),
  Markdown-link/whitespace/hygiene and package credential scans, ZIP integrity,
  packaged 0.1.4-to-0.2.0 upgrade, fresh 0.2.0 install, cache clear, public/
  Admin/Jarvis Admin/page-editor HTTP 200 health, anonymous 401, limited-scope
  Admin/page 403, authenticated bootstrap/validation/models/page-context/page-
  script/panel-script/context-panel 200, malformed/unsupported 422, missing-
  credential 503, stale receipt 409, and zero relevant recent log/browser-
  console matches. Short-lived test API keys were revoked; the disposable
  fixture was restored with Jarvis uninstalled. No live provider request or
  charge occurred.
- Packaged `dist/grav-jarvis-0.2.0.zip`, SHA-256
  `1ef863da43c0e0038764f7564e72a77175add1a5c8bce89526c01318817455ae`.
  Prior 0.1.0 through 0.1.4 archives remain intact.
- Set exact next milestone to Jarvis 0.2.1 Admin2 hardening: a repeatable full
  signed-in browser regression with a deterministic server provider, then non-
  secret provider/model preference and accessibility/responsive refinements.
  Selection/metadata editing is reconsidered only if stable public Admin2 events
  exist. No 0.2.1, Commander, jobs, batch, MCP, new provider, push, tag, or
  publish work was started.

## 2026-08-30 — Jarvis 0.2.1 Admin2 hardening and signed-in regression

- Re-read the canonical state, roadmap, architecture, Jarvis specification/ADR/
  manual, security/testing guidance, 0.2.0 implementation, and frozen 0.1.x
  baseline before editing. Preserved unrelated Site Workshop/Cache Hearth and
  Spitfire-theme work.
- Hardened the signed-in assistant and page panel with categorized redacted
  provider failures, meaningful retry-only behavior, proposal preservation on
  failed generation/acceptance, provider/model/capability/usage presentation,
  semantic status/error announcements, complete labels, visible focus, native
  keyboard actions, narrow responsive layout, bounded preview overflow, and
  inherited Admin2 light/dark variables. Manual browser inspection found and
  fixed a real super-user approval-state mismatch in the bootstrap response.
- Added explicit Reject/discard and successful-regeneration receipt revocation.
  Opaque 15-minute receipts remain hash-only and actor/route/source/proposal
  bound; owner-only storage is capped at 128 and deterministic expiry cleanup
  removes malformed/expired records. Accepted, rejected, replaced, expired,
  cross-user, cross-page, stale, and replayed identifiers fail closed.
- Added five deterministic backend hardening checks plus expanded isolated UI
  assertions. Added a test-only provider plugin and Playwright Core harness that
  creates a random disposable Admin2 account, exercises the real Admin page and
  page editor in system Chrome/Chromium, inspects browser/backend failures, and
  restores prior plugin/account-index/notification/cache state. The eleven
  signed-in checks cover both selectors/surfaces, missing/unavailable/rate-limit
  states and retry, API-token/provider-authority denial, all six action IDs and
  bounded exact whole-buffer context, preview, Reject, Accept once, duplicate/
  stale/replacement behavior, reload non-persistence, keyboard/labels, narrow/
  theme behavior, and absence of save/publish requests or unexpected errors.
- Preserved every frozen 0.1.x interface and the 0.2.0 unsaved-buffer-only
  safety contract. Optional non-secret provider/model preferences were omitted
  because hardening did not justify a new persisted user-data lifecycle.
  Selection-aware and structured metadata work remains deferred because Admin2
  still provides no stable public event for it.
- Passed sixty-three deterministic PHP/component checks and eleven signed-in
  browser checks; DDEV PHP 8.3.31 syntax/runtime/cURL; Node and shell syntax;
  Composer, JSON, repository/Grav YAML, repository preflight, changed-Markdown
  links, whitespace/hygiene, source/package credential scans; ZIP integrity;
  packaged 0.2.0-to-0.2.1 upgrade; fresh packaged 0.2.1 install; Grav cache
  clear; public/Admin/Jarvis/page health; anonymous denial; browser console/page
  error inspection; and recent Jarvis log inspection. Host PHP remains absent,
  so host preflight truthfully skipped PHP. No live provider call or charge was
  made. Temporary browser and package fixtures were removed from DDEV.
- Packaged `dist/grav-jarvis-0.2.1.zip`, SHA-256
  `6c956919d47a2af029b8b37700bbfa4fc051948f4d7bfc31ab37150ad3e73de9`.
  All earlier versioned Jarvis packages remain intact.
- Recommended Jarvis 0.3.0 reliability next, beginning with a bounded additive
  contract/design checkpoint for transient-only retry, privacy-scoped cache
  identity/storage, provider-reported versus estimated usage and versioned
  cost data, budgets, and Grav-aware chunk/synthesis provenance. Do not begin
  jobs, Commander, batch, MCP, automatic apply, or new providers in that first
  reliability checkpoint.

## 2026-08-30 — Jarvis 0.3.0 reliability, cost control, and large context

- Preserved all six frozen 0.1.x interface files byte-for-byte and the 0.2.x
  review-first, permission-checked, unsaved-buffer-only acceptance model. Local
  Site Workshop/Cache Hearth and Spitfire-theme edits were inspected and left
  outside the Jarvis work and commit.
- Added an optional provider-neutral reliability service decorator with bounded
  transient-only retry, normalized retry-after, exponential bounded jitter,
  deterministic runtime fixtures, and safe request/retry/timing diagnostics.
  Credential, configuration, and authentication failures are never retried.
- Added disabled-by-default response caching with canonical SHA-256 keys scoped
  by installation, actor, page/context, action, provider, and model. Custom and
  general prompts and all failures bypass it. The owner-only transient store is
  TTL/capacity bounded and contains only redacted success results and hashed
  scope—not request/prompt or credential fields. Added deterministic memory and
  file-cache fixtures.
- Added nullable normalized usage/request/retry/cache reports, operator-supplied
  versioned model pricing with fixed-point nanocurrency math, explicit unknown/
  estimated/authoritative distinctions, and disabled-by-default pre-call
  budgets for request/retry/input/output/request-cost/operation-cost bounds.
- Added deterministic Grav/Markdown chunking that preserves YAML frontmatter,
  paragraph/list/fenced-code atomicity, source SHA-256 and byte provenance,
  order, and size/count/total/synthesis bounds. Implemented only ordered chunk
  summarization plus one bounded final synthesis; unsafe rewrite/proofread
  reconstruction, truncating summary, crawling, RAG/vector work, and recursion
  remain deferred.
- Added concise Admin2 usage, estimated-cost, request/retry, cache, and budget-
  blocked presentation. One automatic transient retry creates no duplicate
  proposal/receipt, and Accept still updates only the current unsaved buffer.
- Passed sixty-four deterministic PHP checks in DDEV PHP 8.3.31, eight isolated
  Node component checks, and eleven signed-in Chrome/Chromium Admin2 checks.
  The browser gate covers both surfaces, all six actions, one bounded automatic
  rate-limit recovery, receipt lifecycle, unsaved-only behavior, accessibility,
  responsive/theme behavior, authority denial, console inspection, and logs.
- Passed DDEV PHP lint/runtime and Composer validation; Node and shell syntax;
  repository YAML/JSON/preflight/whitespace/hygiene; changed-Markdown links;
  source/package credential-value scans; ZIP integrity; exact packaged
  0.2.1-to-0.3.0 upgrade; fresh package install; Grav cache clear; public/Admin/
  archive HTTP 200; anonymous Jarvis 401; authenticated browser paths; and no
  recent Jarvis fatal/uncaught logs. Host PHP remains unavailable, so host
  preflight truthfully skipped PHP. No live provider request, credential, or
  charge was used. DDEV test plugin/account state was restored/removed.
- Packaged `dist/grav-jarvis-0.3.0.zip`, SHA-256
  `5fff5ea5f10061c3fe95675f0732d20ce7ba0b6eb3623dbf22c6fe3aa452b158`.
  All prior versioned Jarvis archives and verified hashes remain intact.
- Set the exact next milestone to Jarvis 0.3.1: one optional, bounded, preview-
  first Grav Commander consumer path using only public/additive Jarvis
  contracts. Commander must keep its non-AI behavior, permissions, containment,
  and apply authority when Jarvis/provider/credential/capability/budget paths
  are absent or fail. No Commander, job, MCP, batch, new-provider, automatic-
  apply, push, tag, or publish work was started.

## 2026-08-30 — Jarvis 0.3.1 optional Grav Commander consumer

- Implemented the milestone additively in Grav Commander 0.3.12 without
  changing Jarvis source, frozen contracts, 0.3.0 metadata, or its canonical
  archive. Commander has no Jarvis package dependency, checks public interface
  availability before `$grav['gravJarvis']`, and imports no Jarvis Admin,
  provider, transport, reliability implementation, storage, security, or test
  fixture class.
- Added bounded Explain, Summarize, Review, Improve / Rewrite, and Custom Prompt
  actions for one eligible current text/source file. Commander retains root/
  path containment and rejects `.env`, credential/account/secret/private-key
  locations, private-key bodies, unsupported/binary content, and incomplete
  rewrite context. High-confidence credential assignments and bearer/token
  values are redacted. Large Markdown Summarize uses Jarvis's public reliability
  contract; Commander does not duplicate provider, retry, cache, budget, cost,
  or chunk implementations.
- Added provider/model selection, validation, bounded-context/provenance and
  usage/cost/retry/cache presentation, safe proposal review, Copy, Reject, and
  explicit Apply. Apply changes only the current unsaved textarea and never
  invokes a file-write route. A private hash-only 15-minute one-use Commander
  receipt binds actor, root/path, disk modified/size version, source, and
  proposal; expiry, rejection, cross-user/file use, disk/source change, and
  replay fail closed.
- Enforced `grav-commander.browse` plus `grav-jarvis.use` for read-only actions,
  validation, and discovery. Improve, Custom Prompt, and Apply also require
  `grav-commander.write`. Absent, disabled, invalid, misconfigured, unavailable,
  capability-limited, budget-blocked, timeout, rate-limit, authentication, and
  malformed-provider paths affect only Jarvis controls; Commander remains
  usable.
- Added ten deterministic PHP integration checks, six isolated browser-
  component checks, and a real signed-in DDEV Chrome regression for both
  Jarvis-present and Jarvis-absent modes. The browser proves discovery/
  validation, Review, usage/cost, Reject, Apply once, reload non-persistence,
  safe provider failure, and continuing Commander operation. Full frozen
  Jarvis PHP, component, and signed-in Admin2 suites also pass. No live provider
  request, credential, or charge was used.
- The DDEV fixture has an unrelated external
  `spitfirebbs.com/user/data/grav-security-probe.dat` CORS failure. The new
  browser gate filters only that exact known baseline and its deliberate 503,
  while failing on other integration console/page errors. Host PHP remains
  unavailable; PHP syntax/runtime evidence comes from DDEV PHP 8.3.31.
- Passed DDEV PHP 8.3.31 syntax/runtime, Composer, repository/Grav YAML, JSON,
  JavaScript, shell, repository preflight, whitespace, ZIP integrity, exact
  0.3.11-to-0.3.12 upgrade, fresh packaged install, Grav cache clear, public/
  Admin/Commander HTTP 200, anonymous API 401, and relevant log inspection.
  Host PHP remains unavailable, so preflight truthfully skipped host PHP. The
  DDEV fixture was restored to Commander 0.3.11 and temporary Jarvis plugins
  were removed.
- Packaged `dist/grav-commander-0.3.12.zip`, SHA-256
  `b662269b2fb3749e9ab594c9674e3c8ff9d6531aed458d829aa4ee1bd3011789`. Jarvis
  `dist/grav-jarvis-0.3.0.zip` remains canonical at SHA-256
  `5fff5ea5f10061c3fe95675f0732d20ce7ba0b6eb3623dbf22c6fe3aa452b158`.
- The integration exposed one concrete consumer-side gap: Jarvis Apply checks
  the disk version, but Commander's pre-existing ordinary Save endpoint has no
  general optimistic-concurrency token. The exact next milestone is Commander
  0.3.13 ordinary-Save concurrency hardening before Jarvis 0.4.0, additional
  consumers, jobs, MCP, batch, or autonomous write work.

## 2026-08-30 — Caxton 0.1.0 source-fidelity contract foundation

- Re-read the canonical working agreement, state, roadmap, architecture,
  security/testing/release guidance, all decisions, planned briefs, Jarvis
  public boundaries, Commander/Revision Ledger Admin2 patterns, package tools,
  and current dirty work before editing. Reconciled the former Page Studio plan
  into Caxton (`grav-caxton`) rather than creating a competing editor project.
- Inspected the owner-supplied Editor Pro 2.0.10 archive only as a clean-room
  behavioral specimen outside the repository. Recorded SHA-256
  `15617f2adbeb6012204507dcb8eff93d6accf2d59a4015cc7a977dd70ec6f0a8`
  and lessons about source/visual modes, fences, Twig/shortcodes, media paths,
  nested formatting, dirty state, keyboard/RTL/theme behavior, extension seams,
  and explicit replacement. No reference source, asset, API, or dependency
  entered Caxton.
- Reviewed current official Grav 2 API/Admin2/Markdown, Admin2 source,
  ProseMirror, CodeMirror, TipTap, Milkdown, and Lexical documentation. Accepted
  Decision 0005: a bounded source-backed concrete document is authoritative;
  ProseMirror core is the future visual adapter and CodeMirror 6 the future
  source adapter. TipTap adds an unnecessary evolving wrapper, Milkdown's
  remark serialization does not preserve source trivia, and Lexical offers less
  direct leverage for Grav Markdown/source embedding. Editor-engine state is
  never the persisted page format.
- Added the runnable Caxton 0.1.0 package with metadata/defaults/permissions,
  public source document/block/diagnostic/edit/service and extension contracts,
  typed failures, `$grav['gravCaxton']`, `onCaxtonExtensionRegister`, a sorted
  namespaced registry, a 2 MiB/NUL-bounded parser, integrity-checking exact
  serializer, stale-SHA-protected localized plain-heading/plain-paragraph edits,
  and opaque fallback for unsupported or unsafe constructs. Extension-event
  failure logs only the failure class and cannot suppress the core service.
- Added golden representative and malformed fixtures plus deterministic service,
  registry, absence/failure, redaction, bounds, line-ending, Unicode, exact-
  coverage, no-edit, localized-edit, opaque, and stale-source tests. The release
  has no Admin2 field, JavaScript editor, endpoint, page write, renderer,
  preview, media browser, network client, credentials, Jarvis integration,
  executable extension module, background job, collaboration, or MCP path.
- Updated README, architecture, roadmap, security/testing/index, planned brief
  index, Jarvis future-consumer wording, Page Studio recovery link, ADR index,
  and canonical handoff. Preserved the user's unrelated Site Workshop/Cache
  Hearth and Spitfire-theme edits outside the Caxton commit. A stray ignored
  `plugins/.DS_Store` blocked repository hygiene and was moved recoverably to
  `/tmp/grav-extensions-plugins.DS_Store.caxton-20260830`.
- Passed the Caxton contract and all Caxton PHP syntax under DDEV PHP 8.3.31;
  Composer strict validation; repository and package YAML/JSON/shell checks;
  repository structure/hygiene preflight; whitespace and forbidden-package-file
  checks; ZIP CRC and one-top-level-directory inspection; fresh packaged install;
  Grav cache clear; public/Admin HTTP 200; and recent Caxton/fatal/uncaught log
  inspection. Host PHP is absent, so host preflight truthfully skipped PHP.
  There was no earlier Caxton release to upgrade. The DDEV test install was
  removed and its prior absent/config-free state restored.
- Packaged `dist/grav-caxton-0.1.0.zip`, SHA-256
  `21604885f1f551f20f482d00021d6a2bbf02c0d1654da585725794725bbf381c`.
- Set the exact next milestone to Caxton 0.1.1: pin audited ProseMirror core and
  CodeMirror 6 dependencies behind private adapters and prove safe visual/source
  switching, selection mapping, headings/paragraphs/thematic breaks, and opaque
  cards in an isolated deterministic component harness with license/bundle-size
  inventory. Do not add the Admin2 field/persistence boundary, Jarvis, or richer
  parser coverage in that milestone. Commander 0.3.13 is paused, not cancelled.

## 2026-08-30 — Caxton 0.1.1 isolated editor-engine proof

- Re-read the canonical state/specification/ADR/roadmap/architecture/security/
  testing/release guidance and every Caxton 0.1.0 implementation/test file.
  Captured SHA-256 for all fourteen public PHP contract files and kept them
  byte-identical. Preserved unrelated Site Workshop/Cache Hearth, Spitfire
  theme, and concurrent Jarvis work outside the Caxton checkpoint.
- Audited and exact-pinned ProseMirror core, CodeMirror 6, Lezer Markdown,
  esbuild, and Playwright Core. The locked install reports zero known
  vulnerabilities. Recorded all bundled direct/transitive versions, MIT/BSD-
  2-Clause notices, build/test licenses, unpacked/bundle cost, and why no UI
  framework, sanitizer, network client, hosted service, or reference-plugin
  asset is included.
- Added private source-document, ProseMirror visual, CodeMirror source, and
  dual-mode session adapters. The canonical source string/current SHA-256 stays
  authoritative; editor state is never persisted. Safe headings, paragraphs,
  marks/links, lists/tasks, quotes, horizontal rules, fenced code, and inert
  media receive semantic nodes. Frontmatter, HTML/script/on-handler source,
  Twig, shortcodes, tables, unsafe URLs, mixed/malformed/unknown source, and
  excessive nesting become focusable text-only opaque cards.
- Added localized paragraph/heading, strong/emphasis/link, non-1 ordered-list,
  and fence-preserving code edits; content-only dirty/stale/read-only state;
  exact-or-absent source/visual mapping; and an explicit JavaScript UTF-16
  versus PHP-byte offset boundary. Escapes, entities, opaque content, and
  unsafe crossings refuse mapping rather than guessing. Mount/focus/mode/UI
  changes do not dirty or normalize source.
- Added complex/hostile/malformed fixtures plus sixteen deterministic Node
  component/security/performance checks. Evidence includes LF/CRLF/no-final-
  newline, Unicode, nested marks/lists, task/media, fences that shield Twig/
  shortcode-like code, XSS/on-handler/unsafe-URL/prototype/control/NUL/source/
  block/depth bounds, adjacent opaque spans, 10,000 safe spans, 4,000 opaque/
  trivia spans, a 20,000-line fence, and a 419,682-unit/14,002-span combined
  document.
- Added an actual system-Chrome Playwright proof that imports the packaged ES
  module, mounts real ProseMirror/CodeMirror views, and proves focus, labels/
  roles, focusable opaque notes, read-only/source transactions, selection,
  clean mode switches, inert hostile source, text-only cards, no leaked global
  API, and no browser error. Two builds are identical at SHA-256
  `3cdd481694ce3b86986c0c41d10de72595e946d4f4b1dc6fe1c93b1c8984e8c5`;
  the minified module is 868,783 bytes and 299,453 bytes with gzip -9.
- Passed the frozen PHP contract and all Caxton PHP syntax in DDEV PHP 8.3.31;
  clean npm install/audit; Node and shell syntax; Composer strict validation;
  Caxton YAML and JSON; whitespace/hygiene, changed-documentation links,
  credential and forbidden-file scans; ZIP CRC/one-root inspection; exact
  packaged 0.1.0-to-0.1.1 upgrade; fresh packaged 0.1.1
  install; cache clear; public/Admin HTTP 200; and recent Caxton/fatal/uncaught
  log inspection. Host PHP remains absent, so host preflight truthfully skipped
  PHP. A transient concurrent Jarvis 0.3.2 blueprint edit briefly blocked one
  repository-wide preflight rerun; that unrelated work was left untouched, its
  own session cleared the error, and final preflight passes. A regenerated
  ignored `plugins/.DS_Store` was moved recoverably to
  `/tmp/grav-extensions-plugins.DS_Store.caxton-011-20260830`.
- Restored the DDEV fixture to its prior Caxton-absent/config-free state while
  leaving Commander 0.3.12 and Jarvis 0.3.0 installed and unchanged. Packaged
  `dist/grav-caxton-0.1.1.zip` (321,139 bytes), SHA-256
  `45f2c2e976e75b2552535f4b9e54f4cd12586a6205d56bafee308e6341d88bd0`.
  The 0.1.0 archive/hash remains intact.
- Set exact next milestone to Caxton 0.2.0: a narrow Admin2 custom field over
  the private adapter lifecycle/current unsaved value, explicit UTF-16/byte
  conversion, normal Save/Publish only, and a signed-in DDEV browser gate for
  permissions, no-edit fidelity, localized edit, opaque survival, unsaved-
  only reload, explicit Save, keyboard/focus/read-only, responsive/theme,
  console, and logs before default page-field replacement. Do not add Jarvis,
  collaboration, jobs, batch, MCP, autosave, or broad rich-construct work.

## 2026-08-30 — Jarvis 0.3.2 provider setup and operator experience

- Re-read the canonical state, suite README/architecture/roadmap, Decision 0004,
  Jarvis brief/manual/configuration/blueprint, Admin2 controller/page/panel,
  provider/introspection/credential/transport/reliability implementations,
  deterministic and signed-in tests, package guards, and the canonical DDEV
  fixture before editing. Preserved unrelated Site Workshop/Cache Hearth and
  Spitfire-theme dirty work. The audited starting point was Jarvis 0.3.0 plus
  the source-free 0.3.1 Commander consumer milestone.
- Confirmed existing security and provider strengths: credentials were already
  lazy environment-only values; official origins and compatible-instance bases
  were immutable server configuration; bounded transport enforced HTTPS,
  public DNS/pinning, TLS, no redirects/proxies, and response limits; validation
  used non-generating model discovery; errors were typed/redacted; API/page
  permissions, hash-only receipts, and unsaved-only Accept remained authoritative.
  The actual usability gaps were missing exact environment names and setup links,
  invisible disabled built-ins, conflated local credential/remote validation
  status, ambiguous configured-versus-discovered models, selection loss on
  discovery failure, and YAML-only compatible/reliability/pricing configuration.
- Reviewed current Grav 2/Admin2 configuration behavior. Layered/server
  environment loading is documented, but Admin2's `ConfigSecretMasker` only
  masks secret-looking ordinary YAML from API output; it is not encrypted
  storage and has no independent key-management/rotation trust model. Added no
  plaintext secret field, browser secret round trip, or invented encryption.
  Environment variables remain the required credential resolver and preferred
  production path.
- Added a private provider-neutral setup catalog and Admin bootstrap projection
  for built-in, compatible, and extension providers. It exposes enablement,
  registration, exact credential-variable name, local Missing/Configured/
  Invalid state, configured model, safe compatible base URI, discovery support,
  declared capabilities, official help, and normalized validation state—never
  the resolved credential. Explicit Validate/Test Connection remains server-
  side, permission checked, API-token protected, fixed-provider authority, and
  model-list based; initial Admin2 render makes no provider request.
- Updated both Admin2 surfaces with setup/status cards, separate credential and
  validation badges, official links and OpenAI ChatGPT/API distinction, explicit
  validation action, preferred provider, configured-default labels, discovered
  capability/model choices, unavailable-default warning, and graceful discovery
  failure that retains the configured/current model. Failures distinguish
  missing/malformed credentials, authentication, rate/quota, transport,
  configuration/response, and provider availability without raw JSON, response
  bodies, endpoint authority, headers, stack traces, or secrets.
- Expanded the Admin2 blueprint with server-validated non-secret preferred
  provider, default models, compatible instance ID/public HTTPS base/credential
  environment-variable name/discovery declaration, selected bounded retry/cache/
  budget/context/chunking settings, and optional versioned pricing metadata.
  Credentials, arbitrary request endpoints, private-network opt-ins, provider
  wire shapes, and frozen public contracts remain excluded.
- Rewrote the traveling manual as an installation guide covering Quick Start,
  OpenAI API platform/projects/keys, dedicated-project benefits, API billing
  versus ChatGPT subscriptions, Models-read plus Responses-write restricted-key
  needs derived from the actual adapter, Anthropic, DDEV, production PHP-FPM/
  service environments, validation, defaults/model discovery, rotation/removal,
  compatible instances, troubleshooting, and security. Jarvis explicitly never
  uses ChatGPT cookies, sessions, OAuth state, browser storage, or subscription
  credentials.
- Verified the exact developer workflow at
  `/Users/cdaters/Documents/Spitfire/custom-plugins/file-vault-ddev`: create the
  already-ignored `.ddev/config.local.yaml` with
  `web_environment: [GRAV_JARVIS_OPENAI_API_KEY=sk-REPLACE-ME]`, run
  `ddev restart`, and use a presence-only `ddev exec` check. This reaches both
  nginx/PHP-FPM and CLI execution; replacement/removal plus restart rotates or
  removes it. Repository/package guards exclude `.ddev`, `.env*`, and credential
  files. Production injects the same exact variable through a hosting secret
  manager, container/service environment, or protected PHP-FPM pool and reloads
  the web process; `.env.local` is only a protected untracked fallback.
- Added five deterministic PHP provider-setup checks, expanded the Node component
  contract to nine checks, and expanded the signed-in Chrome DDEV regression.
  The full 69-check PHP suite, nine component checks, and eleven black-box checks
  pass, covering missing OpenAI/Anthropic keys, configured/malformed/auth-failed
  fixtures, help and exact environment names, explicit validation, discovery,
  configured/unavailable defaults, provider/model changes, graceful failure,
  no leakage, permissions, CSRF/API token, responsive/theme/accessibility, all
  six proposal actions, and no page save/publish. Repository preflight, YAML,
  JavaScript, Composer, whitespace, package secret guards, ZIP integrity, exact
  packaged 0.3.0-to-0.3.2 upgrade, fresh install, cache clear, public/Admin 200,
  anonymous Jarvis 401, and relevant logs pass. Host PHP is absent; DDEV PHP
  8.3.31 supplied syntax/runtime evidence.
- Boolean-only checks found no OpenAI or Anthropic credential on the host or in
  DDEV. The explicitly opt-in live smoke was therefore skipped; no live request,
  credential exposure, generation, or charge occurred. The deterministic test
  provider supplied configured/success/failure browser proof.
- Packaged `dist/grav-jarvis-0.3.2.zip`, SHA-256
  `f9dd4ad786279682947fa132a507bab7bed360dde82d6f7a2a535e52bb808c06`.
  All prior Jarvis archives remain intact. Release testing restored the DDEV
  fixture to its original Jarvis 0.3.0/config-absent state.
- Jarvis's known limits remain deliberate: no secure Admin credential backend,
  per-user preference store, CLI setup command, streaming/tools, durable
  history/accounting, private compatible endpoint, Gemini/OpenRouter, site-wide
  jobs, MCP workflow, automatic mutation, or structure-preserving chunked
  rewrite. The active suite milestone returns to Caxton 0.2.0; Commander 0.3.13
  concurrency hardening remains paused but recommended before broader agents.

## 2026-08-30 — Caxton 0.2.0 first Admin2 field and clean-room editor UI

- Re-read the canonical recovery, Caxton specification/ADR, roadmap,
  architecture, security, testing, plugin, adapter, and contract sources. Kept
  all fourteen public 0.1.0 PHP contracts byte-identical and preserved unrelated
  Site Workshop/Cache Hearth and Spitfire-theme work.
- Re-inspected the owner-supplied Editor Pro 2.0.10 ZIP and seven downloaded UI
  images from `~/Downloads/editor_pro` as untrusted clean-room behavior
  references. The ZIP SHA-256 still matches
  `15617f2adbeb6012204507dcb8eff93d6accf2d59a4015cc7a977dd70ec6f0a8`.
  Adopted only high-level presentation lessons: compact grouped controls, a
  generous writing surface, inherited light/dark styling, and distinct raw-
  construct cards. No reference source, asset, text, markup convention, private
  API, or build artifact entered the repository or package.
- Released the self-contained `admin-next/fields/caxton.js` Web Component and a
  permission-filtered, idempotent page-blueprint replacement. Only page
  `markdown` fields are replaced; explicit `editor` fields remain unchanged;
  `grav-caxton.use` and `grav-caxton.source` are enforced separately.
- Added the independent user interface. Visual mode displays formatted headings,
  emphasis, links, lists, quotes, and code without Markdown punctuation. Source
  mode displays exact Markdown. HTML, Twig, shortcodes, tables, malformed/unsafe/
  unknown source remain inert, text-only, color-coded protected cards.
- Connected direct visual typing plus safe heading/mark controls to one localized
  top-level source patch. Ambiguous top-level restructure fails closed with a
  Source-mode instruction. Mode switches do not emit changes. Added explicit
  same-source/SHA-256 UTF-16-to-UTF-8-byte conversion with split-code-point
  denial. No page-write route, autosave, network client, hidden copy, preview
  renderer, or alternate persistence store was added.
- Expanded deterministic evidence to seventeen Node checks and actual system-
  Chrome coverage of both reproducible bundles. The proof module is 872,079
  bytes, SHA-256
  `26d56b21cba81314c45d7adc835a58c8ccf0f9f94990ba959c9674688c9c2f03`;
  the Admin2 field is 882,355 bytes (302,914 bytes with gzip -9), SHA-256
  `00e9e6246a2d37dfea471447f9228b7b19ce3bb2c4879ebf5f66ea7a27a9ec3e`.
- Added and passed the signed-in DDEV browser gate: authenticated custom-field
  loading, punctuation-free Visual mode, exact Source/Visual switching, one
  localized visual edit, opaque Twig survival, current unsaved value, reload-
  before-Save non-persistence, ordinary Admin2 Save persistence, inherited
  theme, narrow layout, and clean relevant browser/page/log state.
- Removed only generated `cxbrowser*` accounts/pages left by interrupted test
  runs and discarded their recoverable Flex indexes so Grav can rebuild them;
  no real account/page source was removed. Strengthened test cleanup to restore
  both account and page indexes.
- Passed the frozen PHP/DDEV contract, clean locked npm install and zero-known-
  vulnerability audit, Node and actual-Chrome suites, signed-in Admin2 test,
  bundle reproducibility, ZIP CRC/one-root inspection, DDEV PHP syntax, cache
  clear, and public/Admin/field-asset HTTP 200. Packaged
  `dist/grav-caxton-0.2.0.zip` (626,989 bytes), SHA-256
  `8b5b97e13caf527dd68333556ccab417759b6e87b56774f3099677d09a70bd83`.
  Caxton 0.2.0 is left installed in the canonical DDEV site for owner review.
- Set exact next milestone to Caxton 0.2.1 structural-authoring hardening: safe
  paragraph creation/split/delete, complete link/list/quote flows, accurate
  toolbar state and selection/focus restoration, plus signed-in denied-user,
  Source-permission, read-only, and keyboard regressions. Media/table editing,
  Jarvis, collaboration, jobs, batch, MCP, autosave, and alternate writes remain
  deferred.

## 2026-08-31 — Jarvis 0.3.3 encrypted credential usability and host readiness

- Resumed from canonical Jarvis 0.3.2 and audited the complete plugin, frozen
  contracts, Admin2 page/panel, provider adapters, setup catalog, blueprint,
  reliability/cache/budget/pricing controls, README, DDEV fixture, tests, and
  roadmap before changing behavior. Also inspected Site Safeguard's capability
  groups/graceful downgrade and Grav Commander's permission-filtered header
  Settings navigation. No runtime dependency on either was introduced.
- Confirmed 0.3.2's strengths: environment-only lazy credentials, fixed official
  endpoints, compatible-provider public-HTTPS/SSRF policy, normalized non-
  generating validation/model discovery, configured-model retention, safe
  failures, and useful non-secret settings. The verified gaps were no safe
  beginner key-entry path, no host-readiness explanation, and a settings link
  below rather than in the page header.
- Preserved every frozen 0.1.x contract. Added an internal first-party composite
  resolver with exact priority: direct provider environment variable, encrypted
  local provider record, then missing. A malformed environment credential fails
  closed; removing a valid environment override reveals a retained local record.
  OpenAI-compatible and extension-owned providers retain their existing
  credential ownership and immutable endpoint rules.
- Added a dedicated versioned store at
  `user/data/grav-jarvis/credentials`. Records contain provider/backend/key-
  source/AAD-version metadata, random nonce/IV, authenticated ciphertext and,
  for GCM, its tag—never plaintext. Native Sodium XChaCha20-Poly1305 is preferred;
  OpenSSL AES-256-GCM is the authenticated fallback. No-AEAD hosts remain fully
  environment-capable with Admin key entry disabled. The optional protected
  plaintext compatibility fallback was rejected as an unjustified security and
  maintenance tradeoff.
- Added exclusive/race-safe first-use creation of a separate random 32-byte
  local master key, atomic same-directory credential writes, `0700` directory/
  `0600` file hardening, exact provider path validation, and symlink refusal.
  Advanced operators may set `GRAV_JARVIS_MASTER_KEY=base64:<32 random bytes>`;
  arbitrary passwords, weak derivation, padding, and truncation are rejected.
  Records name their local/external source so adding an external key does not
  invalidate older local records, and unavailable backends/keys never trigger
  downgrade or regeneration.
- Added write-only OpenAI/Anthropic Admin2 key entry, Save & Validate, Replace,
  Remove, safe effective-source/backend and environment-override state, and
  storage-success-versus-remote-validation messaging. Empty input is a no-op,
  not deletion. The only browser occurrence of a secret is the initial
  authenticated request; it is immediately cleared and never returned in
  bootstrap, validation, models, errors, component state, ordinary config, or
  later traffic.
- Added a top-right accessible **Settings** shortcut using the existing Admin2
  base-path convention and separate plugin-config authority, plus a concise
  readiness disclosure with Required, Required for Admin keys, Recommended,
  Fallback, and Optional/Advanced states for PHP, cURL/HTTPS, Sodium,
  OpenSSL-GCM, protected data/master-key storage, environment variables, and
  external-key validity. Narrow layout, inherited light/dark variables,
  keyboard focus, and graceful environment-only downgrade are covered.
- Rewrote the traveling README around the beginner workflow and retained exact
  advanced setup. OpenAI guidance uses the official API platform/projects/keys,
  billing-separation, and key-permissions documentation and records the actual
  adapter needs: Models read for `GET /v1/models` and Responses write for
  `POST /v1/responses`. ChatGPT subscriptions/cookies/session/OAuth/browser
  state are never credentials. Anthropic guidance uses the current official
  authentication/key documentation and records `GET /v1/models` plus
  `POST /v1/messages` only.
- Verified the exact advanced DDEV workflow remains an ignored
  `.ddev/config.local.yaml` containing
  `web_environment: [GRAV_JARVIS_OPENAI_API_KEY=sk-REPLACE-ME]`, followed by
  `ddev restart` and a presence-only `ddev exec` check. Production can inject
  the same direct provider variables—or the strict external Jarvis master key—
  through a secret manager, service/container environment, or protected
  PHP-FPM pool. Environment values retain precedence.
- The deterministic DDEV PHP suite passes 75 checks, including six new grouped
  credential/readiness checks for both encryption backends, save/decrypt/
  replace/remove, tamper/tag failure, wrong/missing/corrupt key, malformed/
  unknown versions, external/local mode, resolution priority/reveal, no-AEAD,
  unwritable path, and symlink denial. Every Jarvis PHP file passes DDEV PHP
  8.3.31 syntax. The isolated Node Admin2 contract, YAML/JavaScript/shell syntax,
  repository preflight, whitespace, and package guards pass.
- The signed-in Chrome DDEV regression passes eleven grouped checks including
  real Settings navigation/readiness rendering, submission-only key handling,
  anonymous credential-write 401, provider/model/failure states, both Admin2
  surfaces, all six proposal actions, replay/stale behavior, responsive/theme/
  keyboard accessibility, no page save/publish, and clean console/page/log
  state. Provider success is deterministically intercepted in the browser; no
  normal test makes a live provider request.
- Added `scripts/test-grav-jarvis-package.sh`. It passes an exact packaged
  0.3.2-to-0.3.3 upgrade with site-config preservation, upgraded public HTTP
  200/anonymous Jarvis 401, fresh 0.3.3 install, and archive denial of DDEV,
  environment, user-data, and master-key files, then restores the fixture.
- Host and DDEV presence-only checks found no OpenAI or Anthropic credential,
  so the opt-in bounded live smoke was skipped. No key was requested, created,
  exposed, provider request made, or charge incurred.
- Packaged `dist/grav-jarvis-0.3.3.zip`, SHA-256
  `4d33947e17447cbec447d90eaf0d34e9106e678c196d44a03cd055c1cada0451`.
  All prior Jarvis packages remain intact. Jarvis changes are isolated from the
  concurrently dirty Caxton, Site Workshop/Cache Hearth, and Spitfire work.
- Known limitation and exact next Jarvis milestone: 0.3.3 migrates a provider
  explicitly when its key is replaced but does not transactionally rotate all
  records. Jarvis 0.3.4 should add rollback-safe local-to-external, external-A-
  to-B, and OpenSSL-to-Sodium whole-store migration/rotation with interruption
  recovery and no plaintext export. Do not start that milestone automatically.

## 2026-08-31 — Caxton 0.2.1 structural authoring and theme hardening

- Resumed from canonical Caxton 0.2.0 and treated the owner's Editor Pro
  screenshots and public Grav documentation as behavioral references only. No
  Editor Pro source, assets, private APIs, or package contents were copied.
- Fixed the reported dark-mode failure by replacing browser-generic canvas
  color assumptions with explicit inherited light/dark palettes for the field,
  toolbar, visual surface, opaque blocks, dialogs, CodeMirror source mode,
  selections, caret, and syntax tokens. Real Chromium contrast assertions cover
  both themes, narrow layout, and runtime theme changes.
- Added an ordered, permission-aware `admin.toolbar` setting with a bounded safe
  allowlist, aliases for familiar configuration names, separator cleanup, and
  source-control removal when the actor lacks `grav-caxton.source`. The default
  toolbar now exposes undo/redo, headings, bold, italic, strikethrough, inline
  code, remove formatting, link, blockquote, bullet/ordered lists, code block,
  and Source mode.
- Added source-faithful visual authoring for GFM strikethrough, links,
  blockquotes, lists, code blocks, and remove-format. Link editing uses an
  accessible in-page dialog with safe URL schemes, keyboard operation, removal,
  cancellation, and focus restoration; it deliberately avoids nested forms and
  native prompts. Underline remains excluded because it would require non-
  portable HTML rather than Markdown.
- Generalized safe visual transactions from mark-only edits to bounded,
  contiguous paragraph/heading ranges. Enter can split and Backspace can join
  safe blocks while preserving source mapping, selection, focus, and undo.
  Opaque, stale, ambiguous, NUL-containing, oversized, protected-crossing, and
  empty-paragraph cases continue to fail closed to Source mode rather than
  silently rewriting Markdown.
- Kept the trust boundary unchanged: Caxton has no upload, alternate save,
  autosave, publish, live-preview, Jarvis, or background route. Admin2's normal
  Save remains the only persistence path, read-only state disables mutations,
  and ordinary Grav permissions continue to govern field/source access.
- Upgraded the direct exact `markdown-it` development dependency to 14.3.1 to
  clear the upstream advisory. The clean dependency audit reports zero known
  vulnerabilities; third-party notices and the engine inventory were updated.
- Validation passed: nineteen deterministic Node adapter tests, performance
  ceiling, real-Chromium field proof, reproducible proof/production builds,
  DDEV PHP 8.3.31 contract and syntax checks, signed-in Admin2 regression,
  repository preflight, YAML/JSON/JavaScript/shell/Markdown/whitespace checks,
  archive CRC/root/secret guards, package installation, cache clear, public and
  Admin HTTP 200 health, exact installed-bundle hashes, and recent-log review.
  Host PHP remains unavailable; all PHP validation ran honestly inside DDEV.
- Reproducible bundle SHA-256 values are
  `5693b30fc1436c15464a3827a953c8e162208f5f7e6b87d97cb2d0d0544f64a9`
  for the proof bundle and
  `d3223b675cd898ced21bb03fb8b5a4083329908e5a55df23ee492f81f0674382`
  for the production field. Packaged `dist/grav-caxton-0.2.1.zip` (633,791
  bytes), SHA-256
  `e4cbc6704eab77e3160c029196ec8c510ece748189b8ee4183a2b2b62e96c5b7`,
  and left that exact release installed at `user/plugins/grav-caxton` in the
  canonical DDEV site for owner review.
- Exact next Caxton milestone: 0.2.2 media and insertion hardening. Add bounded
  page-media insertion/browsing and source-faithful image/reference-link flows
  through the existing Admin2 field and normal Save path, with permission,
  theme, keyboard, opaque-block, and signed-in DDEV coverage. Do not add an
  upload/save route, table/HTML/Twig/shortcode visual editing, Jarvis,
  collaboration, batch, MCP, background jobs, or autosave, and do not start the
  milestone automatically.
