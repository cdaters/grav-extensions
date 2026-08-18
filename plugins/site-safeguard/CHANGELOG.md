# Changelog

## 0.1.0 — 2026-08-18

- Create independently portable full-site, user-folder, and pages/media ZIP packages.
- Record package metadata and a per-file SHA-256 manifest.
- Exclude caches, logs, backups, development metadata, macOS debris, and environment secrets by default.
- Import packages through a guarded Admin2 upload surface.
- Inspect ZIP structure, entry count, expanded size, traversal attempts, duplicate entries, metadata, and every file checksum.
- Extract valid deployable packages only into a unique directory outside the running Grav root.
- Re-verify staged files after extraction.
- Provide one-time, short-lived package download links.
- Serialize token consumption and keep token state outside the backup source.
- Enforce explicit retention, compressed-size, expanded-size, and entry-count limits without auto-pruning packages.
- Provide Admin2 and CLI create/inspect/stage workflows.
- Reserve live promotion permission and UI state without implementing live replacement.
