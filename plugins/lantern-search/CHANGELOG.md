# Changelog

## 0.1.4 — 2026-09-09

- Stack result routes below titles at phone widths so long paths do not squeeze
  titles into narrow columns. Long words wrap within the palette.
- Report the current release version in index status.

## 0.1.3 — 2026-08-30

- Documented the optional content-provider boundary and the File Vault public-metadata adapter, including its visibility, privacy, failure-isolation, and routing contract.
- Clarified that distinct results for provider-owned records require a future multi-document source event rather than catalog-specific logic in Lantern core.
- Preserved searchable public text inside fenced Markdown blocks while removing only the fence markers.

## 0.1.2 — 2026-08-19

- Made the visitor palette follow Grav theme `data-theme` and light/dark classes immediately, with the operating-system preference retained as a fallback.
- Replaced ambiguous text glyphs with a consistently sized SVG icon set and suppressed native browser search decorations that could produce duplicate icons.
- Reworded and spaced the keyboard guidance so each shortcut explains its action.

## 0.1.1 — 2026-08-19

- Fixed an API controller method visibility conflict that could cause Admin2 authentication requests to fail with HTTP 500 while Lantern Search was enabled.

## 0.1.0 — 2026-08-19

- Added incremental public-page indexing with unchanged-record reuse.
- Added ACL, publication, routability, `noindex`, route-prefix, and page-level exclusion controls.
- Added weighted ranking, exact-phrase bonuses, prefix matching, typo tolerance, per-page boosts, excerpts, and facets.
- Added an accessible, responsive, light/dark public command palette.
- Added Admin2 status, rebuild, settings, and query-preview controls.
- Added CLI index/search commands and provider events.
