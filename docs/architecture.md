# Independent Grav Plugin Suite

This suite is a clean-room implementation of useful, publicly documented Grav workflows. Each plugin is site-agnostic, independently installable, compatible with Grav 2 and Admin 2, and licensed separately. Plugins may share conventions, but no plugin may require the whole suite.

## Names and responsibilities

| Plugin | Responsibility | First stable milestone |
| --- | --- | --- |
| **Prism Gallery** | Accessible image, local-video, YouTube, Vimeo, and HTTPS-embed galleries | Quark2 Gallery Modular integration, shortcodes, responsive viewer, deferred embeds |
| **Image Foundry** | Original-preserving image optimization and modern derivatives | Auditable queues, WebP/AVIF policies, quality profiles, safe cache invalidation |
| **Meta Pilot** | Search and social metadata management | Canonicals, robots, Open Graph, social cards, JSON-LD, sitemap diagnostics |
| **Page Studio** | A richer content-authoring experience | Admin 2 editor integration, Markdown/HTML modes, media browser, extensible blocks |
| **Gatehouse** | Admin authentication hardening | CAPTCHA options, throttling, least-privilege recovery visibility |
| **Edge Console** | Narrow Cloudflare operations from Grav | Scoped API tokens, zone diagnostics, cache purge, development-mode controls, audit log |
| **Jarvis** | Shared AI services and guarded agent workflows | Provider abstraction, Admin2 assistant, prompt library, streaming, CLI, proposal/diff/approval contract |
| **Lantern Search** | Fast, relevance-ranked site search | Incremental indexing, field weights, filters, excerpts, keyboard-accessible UI |
| **Site Workshop** | Composable site-building utilities | Safe SVG icons, reusable frontmatter, bounded cache warming, automation feeds |
| **Flexible Markdown Alerts** | Configurable Markdown callouts | Editable alert definitions, one-time titles, site-owned SVGs, optional Icon Bench references |
| **Revision Ledger** | Durable content history | Automatic snapshots, diffs, author/reason metadata, retention, explicit rollback |
| **Site Safeguard** | Backup, restore, and deployment packages | Clean profiles, manifest/checksums, staged validation, maintenance-mode promotion, rollback |

## Shared compatibility contract

1. **No global ownership.** Each plugin owns only its own routes, configuration namespace, cache namespace, Admin 2 entry, and storage directory.
2. **No theme edits.** Theme integrations use Twig paths, events, page blueprints, and optional partials. Theme files remain upgradeable.
3. **Progressive enhancement.** Public output is useful HTML before JavaScript runs. Controls use labels, focus states, keyboard navigation, and reduced-motion preferences.
4. **Conservative content writes.** Plugins must not silently rewrite Markdown, page frontmatter, media originals, or another plugin's data.
5. **Explicit cache invalidation.** Content or derivative changes dispatch normal Grav cache invalidation; plugins do not indiscriminately erase unrelated caches.
6. **Shared records only by event.** Plugins communicate through documented Grav events and small public service interfaces, never by reaching into another plugin's private files.
7. **Security by minimum authority.** Remote integrations use narrowly scoped tokens. Secrets remain in environment-specific config. Logs redact tokens and passwords.
8. **Recoverable destructive actions.** Revisions and backup restoration stage and verify changes before promotion. A restore must not erase the running site inside the same web request.
9. **Site-agnostic defaults.** No Spitfire names, colors, routes, or content live in plugin defaults. A site may override labels and presentation through configuration and CSS variables.
10. **Portable packaging.** Release archives omit `.ddev`, `.DS_Store`, `__MACOSX`, caches, logs, sessions, and host-specific secrets.
11. **Protected asset convention.** Plugins that expose original local files use opaque identifiers and just-in-time, expiring same-site URLs. Public thumbnails and previews may remain content-hashed derivatives. Protection is deterrence and access control—not a claim that browser-visible media cannot be saved.
12. **Black-box boundary evidence.** Security-sensitive and state-changing
    boundaries require external regression tests that enter through the real
    public interface and verify durable results. In-process service success is
    not sufficient proof of browser, HTTP, filesystem, archive, cache, or
    process behavior.

## Integration boundaries

- **Image Foundry → Prism Gallery:** Prism requests ordinary Grav media URLs and never depends on Image Foundry. Themes and media plugins may opt into Image Foundry's public Twig/service interface; the ordinary original URL remains the fallback.
- **Meta Pilot → all public plugins:** Meta Pilot reads the final page and registered structured-data fragments. It does not rewrite another plugin's markup.
- **Lantern Search → content providers:** Plugins can expose indexable records through an event. ACL-protected and unpublished records are excluded at indexing and query time.
- **Revision Ledger → writers:** Page Studio and the future Frontmatter Annex module in Site Workshop can ask Revision Ledger to checkpoint a page before a write. If Revision Ledger is absent, they still work.
- **Flexible Markdown Alerts → Site Workshop:** Alerts may ask the public Icon Bench service to render a configured `pack/icon` reference. Flexible Markdown Alerts keeps bundled and site-owned icon paths and degrades to a text title if the service is absent or disabled; Site Workshop has no dependency on Flexible Markdown Alerts.
- **Site Safeguard → the whole site:** Backup providers can add manifest entries, but cannot execute restore logic. Restore remains solely owned by Site Safeguard.
- **Edge Console → caches:** Edge purge runs only after a successful local operation and must be optional. A Cloudflare failure must not corrupt local state.
- **Jarvis → optional consumers:** Plugins such as Grav Commander may use the
  public `$grav['gravJarvis']` service or documented events for model work. A
  consumer must continue to work when Jarvis is absent, cannot read provider
  secrets, and retains sole authority for its own writes and containment.
- **Jarvis → Grav REST/MCP:** Jarvis proposes and orchestrates bounded AI work;
  Grav's REST API and MCP server remain authoritative for site operations,
  permissions, ETag conflict handling, and API events. No model response can
  approve or directly widen its own mutation scope.
- **Asset delivery → media plugins:** Each plugin can provide its own delivery service, but it follows the same opaque-ID, short-lived-token, same-origin, range-request, and `noindex` response contract. A future shared service can replace local implementations without changing page content.

## Build order

1. Prism Gallery
2. Site Safeguard
3. Image Foundry
4. Meta Pilot
5. Revision Ledger
6. Lantern Search
7. Site Workshop
8. Flexible Markdown Alerts
9. Gatehouse
10. Edge Console
11. Jarvis
12. Page Studio

The order reduces risk and establishes reusable primitives before the editor:
safe packaging, derivative media, metadata, revisions, indexing, and guarded AI
proposals. Jarvis follows the revision and API foundations it needs; Page
Studio remains last because a serious editor is an application platform rather
than a toolbar replacement and may optionally consume Jarvis later.

## Current status

- **File Vault 0.6.0:** working development release with cataloged and unlisted
  downloads, signed delivery, ACL/password/limit controls, and optional
  privacy-conscious activity records.
- **Prism Gallery 0.2.1:** working development release with Quark 2 modular
  integration, shortcodes, mixed media, and opaque just-in-time local-media
  delivery.
- **Site Safeguard 0.3.11:** working development release for checksummed portable
  packages, hostile-archive validation, isolated verified staging, and
  rollback-first full-site restore through a detached Admin-launched CLI worker
  or manual CLI fallback, with fresh-process boot checks and verified
  production-safe directory permissions, signed stateless same-origin package
  delivery, environment-scope-independent resolution, and external download
  regression coverage.
- **Image Foundry 0.2.1:** working development release with original-preserving
  WebP/AVIF sets, source/policy invalidation, protected generated storage,
  opaque immutable delivery, Admin2 operations, CLI parity, an opt-in Twig
  `<picture>` helper, and reversible automatic public-HTML replacement.
- **Meta Pilot 0.2.0:** working development release with normalized canonical,
  description, robots, Open Graph, X/Twitter, and JSON-LD output; XML sitemap
  and robots routes; Admin2 page diagnostics; page-editor overrides; and CLI
  report parity.
- **Revision Ledger 0.2.1:** working development release with protected,
  integrity-checked page snapshots; automatic and named checkpoints; Admin2
  comparisons; retention controls; guarded rollback; CLI parity; and a public
  checkpoint integration seam for other plugins.
- **Lantern Search 0.1.0:** working development release with incremental,
  ACL-aware indexing, relevance controls, a public command palette, and Admin2
  index management.
- **Site Workshop 0.1.0:** working development release with Icon Bench: a
  sanitized SVG registry, original starter pack, custom-pack discovery,
  shortcode/Twig rendering, Admin2 browsing, and CLI inspection.
- **Flexible Markdown Alerts 1.0.1:** working development release with editable
  alert types, per-alert titles, configurable colors and icons, site-owned SVG
  overrides, and optional failure-safe Icon Bench references.
- **Grav Commander 0.3.11:** existing GPM plugin incorporated through Git
  subtree while retaining its standalone repository and history.
- **Spitfire 1.2.0:** working Quark 2 child theme, intentionally site-specific.
- **Jarvis 0.2.1:** provider-neutral service plus hardened permission-filtered Admin2
  assistant and native page context panel. Six internal prompt actions operate
  on bounded title/frontmatter/media/current-unsaved-buffer context. Proposals
  render before/after, use capped one-time actor/route/source/proposal hash
  receipts with explicit rejection/replacement revocation, and can replace only
  the unsaved editor buffer after explicit approval; no Jarvis route saves or
  publishes. Authenticated deterministic browser coverage exercises all six
  actions, lifecycle conflicts, safe provider failures, accessibility,
  responsive layout, and theme inheritance. The 0.1.x OpenAI, Anthropic,
  compatible-provider, bounded HTTP, environment-secret, typed-failure, and
  frozen public contract boundaries remain unchanged.
- **Other later roadmap plugins:** Gatehouse, Edge Console, and Page Studio
  remain named and bounded, not yet represented as finished packages.
