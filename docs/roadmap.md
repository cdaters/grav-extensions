# Extension roadmap

Only working extensions live under `plugins/` or `themes/`. Planned extensions
remain here until their first runnable development release, so an empty folder
can never be mistaken for an installable Grav package.

| Order | Working name | Purpose | State |
| ---: | --- | --- | --- |
| 1 | Prism Gallery | Accessible mixed-media galleries and protected local-media delivery | Development release |
| 2 | Site Safeguard | Verified backup, restore, and clean deployment packages | 0.3.11 development release; signed same-origin downloads, external regression coverage, detached Admin restore, and CLI fallback available |
| 3 | Image Foundry | Original-preserving optimization and modern derivatives | 0.2.1 development release; automatic public-HTML replacement available |
| 4 | Meta Pilot | Canonical, robots, social, structured-data, and sitemap controls | 0.2.0 development release; Admin diagnostics, local exports, and public metadata output available |
| 5 | Revision Ledger | Content snapshots, comparisons, retention, and explicit rollback | 0.2.1 development release; page-editor history drawer, automatic checkpoints, Admin2 comparisons, guarded restore, and CLI parity available |
| 6 | Lantern Search | Incremental, relevance-ranked, ACL-aware site search | 0.1.0 development release; incremental index, Admin2 control center, public command palette, facets, fuzzy matching, and CLI parity available |
| 7 | Site Workshop | Safe icons, reusable frontmatter, bounded cache warming, and automation feeds | 0.1.0 development release; Icon Bench available |
| 8 | Flexible Markdown Alerts | Configurable Markdown callouts with per-alert titles, editable types, and independent or Icon Bench-backed SVG icons | 1.0.1 development release |
| 9 | Gatehouse | Admin authentication hardening, CAPTCHA options, throttling, and recovery visibility | Specification queued |
| 10 | Edge Console | Narrow, audited Cloudflare operations using scoped API tokens | Specification queued |
| 11 | Jarvis (`grav-jarvis`) | Shared AI services, Admin2 assistance, safe proposals, and Grav REST/MCP agent composition | 0.2.0 development release; permission-filtered Admin2 assistant and review-first page proposals available |
| 12 | Page Studio | Grav Admin 2 authoring experience with extensible content blocks | Specification queued |

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
7. **0.2.1 — Admin2 hardening (next):** add a full authenticated browser
   regression with a deterministic server provider, non-secret per-user
   provider/model preference, clearer capability/usage presentation, and
   accessibility/responsive refinements. Selection-aware editing and
   structured metadata proposals remain conditional on stable public Admin2
   editor/form events; do not reach into editor internals.
8. **0.3.0 — reliability:** retries, privacy-safe caching, usage/cost reporting,
   Grav-aware chunking, background jobs, budgets, conflicts, Revision Ledger
   checkpoints, and a stable consumer contract.
9. **0.4.0 — agents and site-wide work:** permission-checked API/MCP
   composition, enumerated batch proposals, resumable jobs, Gemini/OpenRouter
   as justified, and optional suite integrations.
10. **1.0.0 — supported platform:** stable compatibility/deprecation promises,
   migrations, complete black-box/security evidence, and operator guidance.

The canonical feature, security, compatibility, testing, and non-goal detail is
the [Jarvis specification](planned/grav-jarvis.md). The runnable package and
traveling operator/consumer guidance now live under `plugins/grav-jarvis`.

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
