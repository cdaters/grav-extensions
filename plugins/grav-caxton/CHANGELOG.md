# Changelog

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
