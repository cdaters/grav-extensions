# Independent Grav Plugin Suite

This suite is a clean-room implementation of useful, publicly documented Grav workflows. Each plugin is site-agnostic, independently installable, compatible with Grav 2 and Admin 2, and licensed separately. Plugins may share conventions, but no plugin may require the whole suite.

## Names and responsibilities

| Plugin | Responsibility | First stable milestone |
| --- | --- | --- |
| **Prism Gallery** | Accessible image, local-video, YouTube, Vimeo, and HTTPS-embed galleries | Quark2 Gallery Modular integration, shortcodes, responsive viewer, deferred embeds |
| **Image Foundry** | Original-preserving image optimization and modern derivatives | Auditable queues, WebP/AVIF policies, quality profiles, safe cache invalidation |
| **Meta Pilot** | Search and social metadata management | Canonicals, robots, Open Graph, social cards, JSON-LD, sitemap diagnostics |
| **Page Studio** | A richer content-authoring experience | Admin 2 editor integration, Markdown/HTML modes, media browser, extensible blocks |
| **Edge Console** | Narrow Cloudflare operations from Grav | Scoped API tokens, zone diagnostics, cache purge, development-mode controls, audit log |
| **Lantern Search** | Fast, relevance-ranked site search | Incremental indexing, field weights, filters, excerpts, keyboard-accessible UI |
| **Site Workshop** | Focused site-maintenance utilities | Broken-link checks, redirects, maintenance mode, health report, safe batch tools |
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

## Integration boundaries

- **Image Foundry → Prism Gallery:** Prism requests ordinary Grav media URLs and never depends on Image Foundry. Themes and media plugins may opt into Image Foundry's public Twig/service interface; the ordinary original URL remains the fallback.
- **Meta Pilot → all public plugins:** Meta Pilot reads the final page and registered structured-data fragments. It does not rewrite another plugin's markup.
- **Lantern Search → content providers:** Plugins can expose indexable records through an event. ACL-protected and unpublished records are excluded at indexing and query time.
- **Revision Ledger → writers:** Page Studio and Site Workshop can ask Revision Ledger to checkpoint a page before a write. If Revision Ledger is absent, they still work.
- **Site Safeguard → the whole site:** Backup providers can add manifest entries, but cannot execute restore logic. Restore remains solely owned by Site Safeguard.
- **Edge Console → caches:** Edge purge runs only after a successful local operation and must be optional. A Cloudflare failure must not corrupt local state.
- **Asset delivery → media plugins:** Each plugin can provide its own delivery service, but it follows the same opaque-ID, short-lived-token, same-origin, range-request, and `noindex` response contract. A future shared service can replace local implementations without changing page content.

## Build order

1. Prism Gallery
2. Site Safeguard
3. Image Foundry
4. Meta Pilot
5. Revision Ledger
6. Lantern Search
7. Site Workshop
8. Edge Console
9. Page Studio

The order reduces risk and establishes reusable primitives before the editor: safe packaging, derivative media, metadata, revisions, and indexing. Page Studio is last because a serious editor is an application platform rather than a toolbar replacement.

## Current status

- **File Vault 0.6.0:** working development release with cataloged and unlisted
  downloads, signed delivery, ACL/password/limit controls, and optional
  privacy-conscious activity records.
- **Prism Gallery 0.2.1:** working development release with Quark 2 modular
  integration, shortcodes, mixed media, and opaque just-in-time local-media
  delivery.
- **Site Safeguard 0.3.0:** working development release for checksummed portable
  packages, hostile-archive validation, isolated verified staging, and
  rollback-first full-site restore through a detached Admin-launched CLI worker
  or manual CLI fallback, with fresh-process boot checks and verified
  production-safe directory permissions.
- **Image Foundry 0.2.0:** working development release with original-preserving
  WebP/AVIF sets, source/policy invalidation, protected generated storage,
  opaque immutable delivery, Admin2 operations, CLI parity, an opt-in Twig
  `<picture>` helper, and reversible automatic public-HTML replacement.
- **Meta Pilot 0.1.1:** working development release with normalized canonical,
  description, robots, Open Graph, X/Twitter, and JSON-LD output; XML sitemap
  and robots routes; Admin2 page diagnostics; page-editor overrides; and CLI
  report parity.
- **Grav Commander 0.3.11:** existing GPM plugin incorporated through Git
  subtree while retaining its standalone repository and history.
- **Spitfire 1.2.0:** working Quark 2 child theme, intentionally site-specific.
- **Later roadmap plugins:** named and bounded, not yet represented as finished
  packages.
