# Extension roadmap

Only working extensions live under `plugins/` or `themes/`. Planned extensions
remain here until their first runnable development release, so an empty folder
can never be mistaken for an installable Grav package.

| Order | Working name | Purpose | State |
| ---: | --- | --- | --- |
| 1 | Prism Gallery | Accessible mixed-media galleries and protected local-media delivery | Development release |
| 2 | Site Safeguard | Verified backup, restore, and clean deployment packages | 0.3.11 development release; signed same-origin downloads, external regression coverage, detached Admin restore, and CLI fallback available |
| 3 | Image Foundry | Original-preserving optimization and modern derivatives | 0.2.1 development release; automatic public-HTML replacement available |
| 4 | Meta Pilot | Canonical, robots, social, structured-data, and sitemap controls | 0.2.1 development release; empty-page API safety, theme summaries, site robots policy, and HTTP regressions |
| 5 | Revision Ledger | Content snapshots, comparisons, retention, and explicit rollback | 0.2.1 development release; page-editor history drawer, automatic checkpoints, Admin2 comparisons, guarded restore, and CLI parity available |
| 6 | Lantern Search | Incremental, relevance-ranked, ACL-aware site search | 0.1.4 development release; incremental index, Admin2 control center, public command palette, facets, fuzzy matching, and CLI parity available |
| 7 | Site Workshop | Safe icons, reusable frontmatter, bounded cache warming, and automation feeds | 0.1.0 development release; Icon Bench available |
| 8 | Flexible Markdown Alerts | Configurable Markdown callouts with per-alert titles, editable types, and independent or Icon Bench-backed SVG icons | 1.0.1 development release |
| 9 | Gatehouse | Admin authentication hardening, CAPTCHA options, throttling, and recovery visibility | Specification queued |
| 10 | Edge Console | Narrow, audited Cloudflare operations using scoped API tokens | Specification queued |
| 11 | Jarvis (`grav-jarvis`) | Shared AI services, Admin2 assistance, safe proposals, and Grav REST/MCP agent composition | 0.3.3 encrypted credential-usability/readiness release; 0.3.1 optional-consumer milestone proven through Grav Commander 0.3.12 |
| 12 | Caxton (`grav-caxton`; formerly Page Studio) | Source-faithful Grav 2/Admin2 visual and block editing without proprietary storage | 0.3.0 visual-rhythm/authoring and optional Jarvis proposals implemented; 0.3.1 accessibility/proposal hardening next |

Existing products that are not part of that build sequence remain first-class:

- **File Vault** — protected catalogs, unlisted one-off downloads, signed links,
  ACLs, passwords, limits, and privacy-conscious activity records.
- **Grav Commander** — dual-pane file management and backup operations, while
  retaining its standalone GPM repository and release lifecycle.
- **Spitfire** — update-safe Quark 2 child theme for SpitfireBBS.com.

Names are original working names and can be revisited before first stable
releases. Implementations must be clean-room work based on public behavior and
documentation, not copied premium source code.

## Jarvis roadmap

Jarvis is the suite's provider-neutral AI infrastructure and guarded workflow
layer for Grav 2. It is deliberately not a clone of Grav AI Pro. Grav's native
REST API and MCP server retain site-operation authority; Jarvis owns provider
access, prompt/context handling, proposals, approvals, reliability, and a
public PHP service for optional consumers such as Grav Commander.

The version sequence is:

1. **0.1.0 — contract foundation (implemented):** public interfaces and
   immutable request/result/usage values, provider registry and registration
   event, `$grav['gravJarvis']`, deterministic fake provider, typed failures,
   optional-consumer fallback, and environment-aware secret redaction. No live
   provider is registered and enabling the plugin performs no network request.
2. **0.1.1 — provider boundary (implemented):** additive optional validation
   and model-discovery contracts, provider-neutral DTOs, provider-scoped
   environment credentials, sanitized HTTP contracts, deterministic fixtures,
   and conformance/redaction tests. The 0.1.0 interfaces are unchanged.
3. **0.1.2 — first live provider (implemented):** bounded production HTTP with
   HTTPS/base-path allowlisting, public-DNS validation and pinning, TLS,
   disabled redirects/proxies, time/size limits, and safe diagnostics; official
   OpenAI `GET /models` validation/discovery and Responses completion; and
   deterministic transport/adapter failure and redaction fixtures. The frozen
   shared contracts contain no OpenAI response shape.
4. **0.1.3 — compatible-provider proof (implemented):** a separate generic
   OpenAI-compatible adapter with an operator-approved public HTTPS base URI,
   environment-only instance credentials, declarative capability truth, and a
   deterministic matrix for full, partial, and incompatible implementations.
   It is not a mode of the official OpenAI adapter, accepts no per-request
   endpoint, and has no private/local-network opt-in.
5. **0.1.4 — second first-party wire family (implemented):** isolated official
   Anthropic Models/Messages mapping, environment-only authentication,
   non-generating validation/discovery, ordered text and usage normalization,
   deterministic vendor-specific failure/redaction fixtures, and an opt-in
   bounded live-smoke path. Frozen shared interfaces remain byte-identical.
6. **0.2.0 — first Admin2 usability slice (implemented):** permission-filtered Jarvis
   and page-editor entry points; provider/model selection and status; prompt,
   current-page context, and Rewrite/Proofread/Shorten/Expand/Summarize/Custom
   actions; response/diff preview; explicit Accept/Reject into the unsaved
   editor buffer; source/permission rechecks; and graceful absence/failure
   states with no automatic save.
7. **0.2.1 — Admin2 hardening (implemented):** full authenticated browser
   regression with a deterministic server provider; all-six-action and receipt-
   lifecycle coverage; categorized retryable failures; clearer capability/
   usage presentation; and accessibility, keyboard, responsive, and inherited-
   theme refinements. Optional provider/model preferences were not persisted:
   hardening required no new user-data store. Selection-aware editing and
   structured metadata proposals remain conditional on stable public Admin2
   editor/form events; Jarvis does not reach into editor internals.
8. **0.3.0 — reliability (implemented):** bounded transient retries, disabled-
   by-default privacy-scoped caching, nullable normalized usage, versioned
   fixed-point cost estimates, opt-in operation budgets, deterministic Grav/
   Markdown chunk provenance, and summarize-only bounded synthesis. Admin2
   shows compact reliability data without weakening review receipts or unsaved-
   buffer acceptance. No job/history/accounting database or telemetry was added.
9. **0.3.1 — first optional consumer integration (implemented without a Jarvis
   package change):** Grav Commander 0.3.12 discovers only
   `$grav['gravJarvis']` and public `Contracts` interfaces for bounded Explain,
   Summarize, Review, Improve / Rewrite, and Custom Prompt actions on one
   eligible current file. Commander owns permissions, sensitivity/context
   filtering, hash/version-bound preview receipts, and unsaved-buffer Apply;
   Jarvis owns validation/discovery, providers, reliability, cost/budget, and
   Markdown chunking. Commander remains usable through absent/disabled/
   misconfigured/provider/capability/budget failures. No Jarvis 0.3.1 ZIP was
   manufactured because no Jarvis source or public contract changed.
10. **0.3.2 — provider setup and operator experience (implemented):** Admin2
    exposes provider enablement, exact credential-environment names, separate
    credential and validation states, official setup guidance, configured
    defaults, live-discovered models, and safe normalized failures. Validated
    non-secret defaults, compatible-instance profiles, selected reliability/
    cache/budget/chunking controls, and pricing metadata use ordinary Grav
    configuration; credential values remain server environment only. The
    traveling manual now covers OpenAI API-project keys versus ChatGPT
    subscriptions, Anthropic, exact local DDEV and production PHP workflows,
    rotation, model choice, validation, and troubleshooting. No frozen public
    contract or provider wire boundary changed.
11. **0.3.3 — encrypted credential usability and host readiness
    (implemented):** write-only OpenAI/Anthropic Save & Validate, environment-
    first multi-source resolution, versioned user-data ciphertext, Sodium-
    preferred/OpenSSL-GCM-fallback authenticated encryption, auto-managed or
    strict external random master keys, safe source/override reporting, a
    Site-Safeguard-quality readiness panel, and a Commander-style top Settings
    shortcut. No plaintext fallback, frozen-contract change, endpoint widening,
    or secret-bearing plugin configuration was added.
12. **0.3.4 — explicit master-key/backend migration and rotation (recommended
    next Jarvis milestone):** transactionally re-encrypt all stored records,
    support rollback-safe local/external master-key rotation, and add recovery
    export/import metadata without exporting credential plaintext.
13. **Commander 0.3.13 — ordinary Save concurrency hardening (recommended
    next):** add an expected version/content token to the existing file-write
    boundary so all saves, not only Jarvis proposal Apply, reject an externally
    changed source before overwrite.
14. **0.4.0 — agents and site-wide work:** permission-checked API/MCP
   composition, enumerated batch proposals, resumable jobs, Gemini/OpenRouter
   as justified, and optional suite integrations.
15. **1.0.0 — supported platform:** stable compatibility/deprecation promises,
   migrations, complete black-box/security evidence, and operator guidance.

The canonical feature, security, compatibility, testing, and non-goal detail is
the [Jarvis specification](planned/grav-jarvis.md). The runnable package and
traveling operator/consumer guidance now live under `plugins/grav-jarvis`.

## Caxton roadmap

Caxton is the reconciled identity for the former Page Studio editor plan. Its
source-backed model makes ordinary Grav Markdown authoritative while safe
constructs receive semantic visual editing and ambiguous constructs remain
byte-preserved. It is independent of Jarvis and of the supplied clean-room
Editor Pro behavior reference.

1. **0.1.0 — source-fidelity contract foundation (implemented):** runnable
   `grav-caxton` package, public document/block/diagnostic/edit and service
   contracts, bounded exact no-edit serialization, SHA-256-guarded localized
   plain-heading/plain-paragraph edits, opaque fallback, extension registry,
   `onCaxtonExtensionRegister`, and `$grav['gravCaxton']`. No Admin2 editor,
   client engine, endpoint, or Jarvis integration ships in this release.
2. **0.1.1 — editor-engine proof (implemented):** pinned audited
   ProseMirror core and CodeMirror 6 dependencies behind Caxton-owned adapters;
   proved safe semantic nodes, inert opaque cards, localized edits,
   conservative selection mapping, and source/visual switches without false
   dirtying or normalization in deterministic Node and actual Chrome harnesses.
   The dependency/license/size inventory and reproducible internal ES module
   are recorded; no Admin2 page field or persistence route exists.
3. **0.2.0 — first Grav-aware Admin2 field (implemented):** self-contained,
   permission-filtered `caxton` field over the private adapters; compact grouped
   toolbar; formatted Visual mode without Markdown punctuation; exact Source
   mode; inert protected cards; same-source UTF-16/byte conversion; current
   unsaved value/change contract; normal Save/Publish only; and signed-in DDEV
   proof for no-edit fidelity, localized edit, reload non-persistence, ordinary
   Save, theme, responsive layout, and relevant logs.
4. **0.2.1 — structural-authoring and theme hardening (implemented):** explicit
   light/dark visual and source palettes; bounded ordered toolbar configuration;
   safe in-page link/title/removal, list, quote, code-block, clear-format, and
   GFM-strikethrough flows; localized paragraph split/join transactions;
   active/disabled state; selection/focus restoration; read-only and keyboard
   regressions; and no broader persistence authority.
5. **0.3.0 — visual rhythm, authoring, and optional Jarvis proposals
   (implemented):** this coherent additive release absorbs the committed 0.2.2
   authoring requirements and the first optional Jarvis boundary. It integrates
   the current page-media inventory through public Admin2 data/events; adds safe
   media insertion/editing, horizontal rules, code-fence language selection,
   and multi-item list operations; adds explicit paragraph/heading/list/quote/
   code-block vertical rhythm that is independent of Admin2's CSS reset and
   cannot dirty or rewrite source; preserve exact unresolved media and prove
   upload/selection permissions without introducing a Caxton upload or save
   route. It also uses only public Jarvis services for seven selection/block
   actions, provider/model discovery, text-only preview, one-time hash-bound
   Accept into the unsaved buffer, stale/replay protection, usage/cost feedback,
   and deterministic present/absent/failure tests. Caxton has no Jarvis package
   dependency, credentials, provider HTTP, autosave, publish, job, batch, or MCP
   authority.
6. **0.4.x — richer Grav-aware authoring:** configuration-aware Markdown, media,
   links, lists, quotes, tables, fenced code, HTML/Twig/shortcode inert views,
   extension client modules, insertion palette, and page-form integration.
7. **0.3.1 — accessibility and proposal hardening (exact next milestone):**
   dialog focus trap/return and announcements; keyboard-only Jarvis control;
   high contrast/reduced motion, RTL, IME, touch, zoom, and narrow-layout proof;
   direct API permission/expiry/protected-boundary coverage; and large-document
   proposal mapping without expanding authority.
8. **0.3.x — further polished authoring:** focus/split modes, safe reorder,
   large-document profiling, and evidence-driven adapters.
9. **1.0.0 — supported editor platform:** public-extension compatibility and
   deprecation policy, complete fallback/migration guidance, and release-quality
   accessibility/security/browser/large-document evidence.

The canonical guarantees, engine evaluation, security model, compatibility,
tests, and non-goals are in the [Caxton specification](planned/grav-caxton.md).

## Site Safeguard recovery roadmap

Site Safeguard 0.3 provides the immediate production-to-DDEV and DDEV-to-
production transfer path: portable packages, repeated validation, verified
staging, rollback-first restore launched by Admin2 or CLI, live recovery-journal
progress, and automatic rollback when the restored site cannot boot.

The recovery work that follows is intentionally incremental:

1. **Archive engineering** — retain ZIP permanently as the compatibility
   format; specify and benchmark the streaming `.ssa` format under constrained
   PHP hosting; specify `.sss` authenticated encryption using Sodium
   secretstream and a versioned Argon2id KDF profile. SSA is not the default
   until benchmarks, test vectors, corruption behavior, and at least two
   independent readers justify it.
2. **Recovery Console** — an independently authenticated, disposable
   `safeguard-recovery.php` entry point that can recover a site even when Grav
   or the installed plugin cannot start, then locks or removes itself.
3. **Scheduling** — Grav Scheduler/cron integration, overlap protection,
   verification jobs, retention generations, notifications, and history.
4. **Off-site storage** — an encrypted provider contract followed by
   S3-compatible storage, SFTP, and WebDAV with resumable transfer, remote
   integrity verification, and remote retention.
5. **External data sets** — explicit companion backup definitions for File
   Vault binaries and other protected data stored outside the Grav root.

## Image Foundry roadmap

Image Foundry 0.2 builds on the safe derivative foundation with reversible,
opt-in automatic replacement of eligible public `<img>` markup. Bounded local
GD processing, source hashes, responsive WebP/AVIF sets, opaque delivery,
Admin2 operations, CLI parity, and the explicit Twig helper remain available.

Later milestones can add background/Scheduler queues, additional image engines,
visual before/after comparisons, Grav media-event adapters, CSS-background
integration, and generated Grav crop/cache correlation. Originals remain
authoritative throughout.
