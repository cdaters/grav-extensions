# Changelog

## 0.2.2 - 2026-09-09

- Preserve every page in reports and sitemaps when folders at different depths
  share a slug. Grav Collection iterator keys are not unique page identities.
- Extend real DDEV HTTP regression coverage with top-level and nested pages
  sharing a slug, including both indexable and noindex sitemap behavior.

## 0.2.1 - 2026-09-09

- Keep empty Markdown pages empty during metadata reporting instead of trying
  to render Twig inside an API request before the renderer is initialized.
- Honor top-level page `description` after explicit Meta Pilot and standard
  metadata descriptions, preserving summaries used by custom themes.
- Honor `site.metadata.robots` as the site-wide fallback and apply the same
  effective directive to sitemap exclusion. Explicit page overrides still win.
- Add repeatable DDEV HTTP coverage for authenticated reports, anonymous and
  permission denial, head normalization, description precedence, site/page
  indexing policies, protected-page exclusion, and cache refresh.

## 0.2.0 - 2026-08-19

- Added complete local report exports in CSV and JSON formats from the Admin2
  metadata manifest.
- Added direct navigation from the Meta Pilot dashboard to its plugin settings.
- Made the dashboard follow Admin2 light and dark mode using explicit theme
  markers with a computed-color fallback for hosts that expose no theme class.

## 0.1.3 - 2026-08-18

- Initialize Grav's page tree before generating Admin API reports. Grav skips
  the public pages processor for authenticated API requests, which previously
  left Meta Pilot's dashboard at zero with an unexpected-error message.

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
