# Changelog

## 0.2.1 — 2026-08-18

- Added opaque, just-in-time authorization for full-resolution local page media.
- Added expiring signed media URLs, inline range delivery, same-origin resource policy, and search-engine exclusion headers.
- Removed raw full-resolution page-media paths from protected gallery triggers.
- Added versioned assets so cached pages reliably receive the viewer behavior update.
- Made cache-safe global asset registration the default; the lightweight viewer now remains available when Grav serves cached modular content.

## 0.1.0 — 2026-08-18

- Added Quark2 Gallery Modular integration without theme edits.
- Added `prism-gallery` and `prism` shortcodes with optional migration aliases.
- Added images, local video, YouTube, Vimeo, and HTTPS iframe support.
- Added deferred remote embeds, responsive layout, captions, zoom, swipe, and keyboard navigation.
- Added light/dark theme variables, focus management, reduced-motion support, and Admin configuration.
