# Changelog

## 1.0.1 — 2026-08-25

- Changed the displayed author and Composer package owner to Craig Daters.
- Fixed screenshot URLs in the Grav Admin README view.
- Added a step-by-step custom SVG icon guide and clearer Admin help.
- Moved custom icon ownership to `user/data/flexible-markdown-alerts/icons`.
- Added safe site-owned overrides for bundled icons without plugin edits.
- Added optional, failure-safe Site Workshop Icon Bench `pack/icon`
  interoperability without introducing a dependency in either direction.

## 1.0.0 — 2026-08-25

- Created the reusable, site-agnostic Flexible Markdown Alerts plugin.
- Preserved standard `[!TYPE]` alert syntax.
- Added optional per-instance titles with `[!TYPE|Custom title]`.
- Made alert definitions configurable and extensible.
- Shipped editable Note, Tip, Important, Warning, and Caution definitions.
- Added per-type enablement, keyword, default title, icon, border color, and
  title/icon color controls.
- Added safe support for additional alert types and trusted local SVG icons.
- Retained configurable CSS classes, bundled styling, and Editor Pro support.
- Added separate syntax, configuration, and migration guides.
