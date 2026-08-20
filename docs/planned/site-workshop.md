# Site Workshop

Site Workshop is the suite's home for small, composable site-building tools
that do not justify separate plugins. It became a runnable development release
on 2026-08-20, with each module independently switchable and narrowly scoped.

## 0.1.0 — Icon Bench

Icon Bench is the first production-ready module. It provides an original
starter SVG pack, sanitized custom-pack discovery, an Admin2 browser, Twig and
Shortcode Core output, and CLI inventory. SVGs are parsed with network access
disabled and reduced to a conservative drawing allowlist on every render.

## Planned modules

1. **Frontmatter Annex** — load reusable frontmatter fragments from external
   files with an explicit, inspectable precedence model and cycle protection.
2. **Cache Hearth** — warm selected public routes through bounded jobs with
   concurrency limits, URL budgets, exclusions, progress, and cancellation.
3. **Feed Relay** — publish purpose-built RSS and JSON feeds for automation
   services without exposing private or non-routable content.

Site Workshop does not become a file manager, backup system, revision store,
search engine, redirect manager, or authentication layer. Those responsibilities
remain with Grav Commander, Site Safeguard, Revision Ledger, Lantern Search,
Grav/core routing, and Gatehouse respectively.
