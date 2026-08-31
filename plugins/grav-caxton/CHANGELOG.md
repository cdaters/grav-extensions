# Changelog

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
