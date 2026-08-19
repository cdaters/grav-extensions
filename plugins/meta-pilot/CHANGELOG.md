# Changelog

## 0.1.2 - 2026-08-18

- Renamed the plugin-specific API permission helper so it no longer overrides
  Grav API's two-argument `requirePermission()` method. This restores Admin2
  authentication, cache actions, configuration loading, and plugin pages.

## 0.1.1 - 2026-08-18

- Corrected the Admin2 API permission-helper visibility so the controller can
  load under Grav 2 without a fatal inheritance error.

## 0.1.0 - 2026-08-18

- Added canonical, description, robots, Open Graph, X/Twitter card, and JSON-LD
  output with page-level fallbacks and overrides.
- Added theme-agnostic final-head normalization that avoids duplicate standard
  metadata while preserving features that Meta Pilot does not own.
- Added XML sitemap and robots.txt routes with protected/noindex exclusions and
  compatibility with standard Grav Sitemap frontmatter.
- Added an Admin 2 diagnostic dashboard for missing, duplicated, and weak page
  metadata plus social-image and indexing visibility.
