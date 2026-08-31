# Changelog

## 0.2.1 - 2026-08-31

- Replace browser `Canvas` colors with explicit light/dark Admin2-aware editor,
  toolbar, protected-card, popover, and CodeMirror palettes with regression-
  checked contrast.
- Add an ordered, bounded toolbar setting with safe allowlisted items and visual
  separators; malformed and unknown configured items fail closed.
- Add in-page link editing with optional titles, safe URL validation, removal,
  `Ctrl/Command+K`, and no native browser prompt.
- Add source-localized blockquote, bullet-list, numbered-list, code-block,
  remove-format, and GFM strikethrough tools. Underline remains excluded because
  Grav Markdown has no source-faithful underline syntax.
- Add paragraph split/create and join/delete keyboard transactions across only
  contiguous safe source spans, with undo and selection/focus restoration.
- Add active/disabled toolbar states and read-only behavior without changing
  ordinary Admin2 Save/Publish authority.
- Extend deterministic Chrome and signed-in DDEV coverage for configuration,
  unsafe links, structure, keyboard actions, settings, responsive layout, and
  real light/dark/source contrast.

## 0.2.0 - 2026-08-30

- Add the first real Admin2 `caxton` page field and permission-filtered,
  idempotent replacement of page Markdown fields.
- Add a compact theme-aware toolbar and visual document canvas that hides
  Markdown punctuation while preserving an explicit exact Source mode.
- Connect direct visual typing and proven formatting to localized Markdown
  patches; keep unsupported top-level restructuring in Source mode.
- Render HTML, Twig, shortcodes, tables, malformed source, and unknown syntax as
  inert color-coded protected cards without executing or rewriting them.
- Add explicit SHA-256-bound UTF-16-to-UTF-8-byte coordinate conversion.
- Preserve Admin2's unsaved value/change contract and ordinary Save/Publish as
  the only persistence path; add no HTTP write route, autosave, or parallel
  storage.
- Add deterministic component, actual-Chrome, and signed-in DDEV regressions for
  hidden Markdown punctuation, mode fidelity, localized edits, opaque survival,
  reload non-persistence, ordinary Save, theme, responsive layout, and logs.

## 0.1.1 - 2026-08-30

- Preserve every 0.1.0 public PHP contract byte-for-byte.
- Add private ProseMirror and CodeMirror adapters over authoritative Markdown.
- Add safe semantic nodes, inert opaque cards, localized edit serialization,
  source/visual selection mapping, stale identity, and content-only dirty state.
- Add deterministic hostile/complex/large-source fixtures and actual system-
  Chrome engine coverage.
- Add exact dependency locks, third-party notices, bundle inventory, and a
  reproducible internal proof asset that is not loaded by Admin2.
- Keep the Admin2 field, persistence, Jarvis, collaboration, jobs, and MCP out
  of this release.

## 0.1.0 - 2026-08-30

- Add the runnable Grav 2 plugin package and `$grav['gravCaxton']` service.
- Add public source-document, block, diagnostic, edit, service, and extension
  contracts.
- Add a deterministic, bounded source parser with exact no-edit serialization.
- Add localized plain-heading and plain-paragraph edits with stale-source
  protection.
- Add an extension registry and `onCaxtonExtensionRegister` hook.
- Preserve unsupported, ambiguous, executable, and malformed constructs as
  inert source blocks.
