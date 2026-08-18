# Changelog

## 0.2.0 — 2026-08-18

- Add CLI-only, rollback-first full-site restore from verified deployable stages.
- Automatically create, stage, checksum, and fresh-process boot a rollback package before maintenance mode begins.
- Preserve configurable host-local paths such as `.ddev`, environment files, the destination Site Safeguard configuration, runtime caches/logs, and external File Vault storage.
- Mirror the staged site, remove stale non-preserved entries, and verify restored file content before completion.
- Record file permission modes and modification times and restore executable Grav CLI commands correctly.
- Add isolated pre-restore and post-restore Grav boot health checks with automatic rollback on failure.
- Persist protected restore journals and serialize restore operations with a non-blocking lock.
- Narrow runtime cache exclusions to the Grav root so Composer packages such as `vendor/symfony/cache` remain portable.
- Document the standalone Recovery Assistant, scheduler, encrypted off-site storage, and external-data-set roadmap.

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
