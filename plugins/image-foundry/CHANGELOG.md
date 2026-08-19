# Changelog

## 0.2.0 - 2026-08-19

- Added opt-in automatic replacement of eligible public-page `<img>` elements
  with responsive AVIF/WebP `<picture>` markup.
- Preserve the original `<img>` element and every one of its attributes as the
  browser fallback; originals remain unchanged and authoritative.
- Skip Admin/API output, existing `<picture>` markup, remote/data/blob images,
  stale or uncataloged sources, and images marked `data-foundry-ignore` or
  `class="image-foundry-ignore"`.
- Added per-image `data-foundry-sizes` overrides and a configurable automatic
  default `sizes` value.
- Added a layout-neutral wrapper style so automatic `<picture>` elements do not
  become unexpected flex or grid items.

## 0.1.1 - 2026-08-18

- Fixed an Admin2 API controller method-name collision that caused the plugin
  page definition request to fail with HTTP 500.

## 0.1.0 - 2026-08-18

- Added original-preserving WebP and AVIF derivative generation.
- Added source scanning, stale detection, protected storage, and opaque delivery URLs.
- Added an Admin2 dashboard and scan/build/purge CLI commands.
- Added the opt-in `foundry_picture()` Twig helper with an original-image fallback.
