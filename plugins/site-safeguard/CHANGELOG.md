# Changelog

## 0.3.1 — 2026-08-19

- Added direct navigation from the Site Safeguard dashboard to plugin settings.
- Made the recovery dashboard reliably follow Admin2 light and dark mode,
  including installations that expose their theme through computed colors.

## 0.3.0 — 2026-08-19

- Add a guarded **Restore** action to Admin2 while retaining the manual CLI
  recovery path.
- Queue each Admin restore as a uniquely identified operation and launch the
  existing rollback-first restore command as a detached PHP CLI worker instead
  of replacing the running site inside an HTTP request.
- Require separate `site-safeguard.restore` permission, independently disabled
  Admin-launch setting, exact typed confirmation, and a final browser warning.
- Detect unsupported launch environments and keep the CLI command visible as
  the fallback.
- Poll a protected recovery journal and display queued, validation, rollback,
  maintenance, copy, verification, completion, failure, and automatic-rollback
  progress in Admin2, including bounded worker output on failure.
- Add a live environment-readiness panel distinguishing current minimum,
  recommended, optional, and restore-only host capabilities, including clear
  Sodium/Zlib/OpenSSL guidance for the future archive formats.
- Reserve `.ssa` and `.sss` as documented, benchmark-gated future streaming and
  authenticated-encryption formats while keeping ZIP as the compatibility
  baseline.

## 0.2.3 — 2026-08-19

- Keep isolated stage directories private while normalizing restored,
  non-preserved Grav directories to web-traversable `0755` permissions.
- Verify restored directory traversal permissions and fail into the automatic
  rollback path if a hosting platform refuses the normalization.
- Record the number of normalized directories in restore and rollback journals.

## 0.2.2 — 2026-08-18

- Display the embedded operator note directly in every recovery-package card
  and in the expanded inspection results, including an explicit empty state.

## 0.2.1 — 2026-08-18

- Supply the required resource location when returning HTTP 201 responses so
  package creation, import, and staging work with the released Grav API plugin.

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
