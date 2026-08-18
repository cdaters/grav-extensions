# Changelog

## 0.6.0 - 2026-08-18

- Added unlisted protected assets for one-off page downloads that must remain outside every public catalog and category collection.
- Extended `[file-download]` to resolve enabled listed or unlisted items through the same signed-link, ACL, password, limit, analytics, and activity pipeline.
- Added an **Unlisted assets** Admin2 drawer with drag-and-drop uploads and a per-item **Listed** visibility control.
- Added a ready-to-copy inline shortcode and Copy action to every item metadata panel.
- Added catalog visibility to secure URL creation and advanced the catalog format to schema 4.

## 0.5.1 - 2026-08-16

- Made the distributed plugin site-agnostic by replacing Spitfire-specific Admin and public defaults with File Vault terminology.
- Kept branding and introductory content in site configuration, preserving existing customized installations during upgrades.
- Made shortcode examples derive from the current vault instead of hard-coding a catalog item.
- Normalized text, date, number, and select controls to the same 42-pixel Admin2 height in light and dark themes.
- Allowed the Admin hero actions to wrap cleanly on narrower content widths.

## 0.5.0 - 2026-08-16

- Added a category-aware drag-and-drop upload target, multi-file picker, sequential batch queue, per-file status, and partial-failure handling.
- Added opt-in successful-download activity logging with authenticated usernames and source type.
- Added configurable IP collection modes (none, pseudonymous hash, or full), retention, optional user-agent capture, and explicit trusted-proxy handling.
- Added an Admin2 activity viewer with retained-event totals and a clear-log action.
- Kept passwords and atomic download limits independent; only successful, countable downloads create activity events.
- Documented privacy, retention, proxy, and deployment considerations.

## 0.4.0 - 2026-08-15

- Added an Admin2 upload allowlist editor with server-side extension validation.
- Added secure external URL entries that use File Vault's signed-link, ACL, password, and download-limit gate before redirecting.
- Added optional per-item download passwords stored as one-way hashes.
- Added optional per-item download limits with atomic claims, automatic public delisting, and reset-to-republish behavior.
- Added password, remaining-download, source, and exhausted status to the Admin2 catalog and metadata editor.
- Corrected public filter labels and controls to a shared row and exact height in both themes.
- Corrected Admin2 checkbox sizing and alignment in the Public view settings panel.

## 0.3.0 - 2026-08-15

- Added Admin2 public-view settings for hero content, metric visibility, search text, initial category, and initial list/grid display.
- Added category counts and explicit labels/chevrons to make public filtering easier to recognize.
- Added reusable `[file-vault]` and `[file-download]` Shortcode Core integrations with ACL-safe rendering.
- Normalized Admin/public terminology around File Vault, Downloads, archive files, and categories.
- Corrected public and Admin search-field alignment and strengthened extension-badge file icons.
- Anchored modal actions on shorter viewports and continued inheriting the active Admin2 accent in both themes.

## 0.2.0 - 2026-08-15

- Added first-class catalog categories with create, edit, sort, reassign, and safe-delete APIs.
- Added filing-cabinet navigation to the Admin2 manager and category-aware uploads.
- Made the manager inherit Admin2 light/dark surfaces and primary accent tokens.
- Kept global Home and Downloads navigation visible on the modular homepage.

## 0.1.0 - 2026-08-15

- Initial Grav 2 and Admin2 implementation.
- Protected external storage and signed download delivery.
- Admin catalog, uploads, metadata editing, delete controls, and count reset.
- Public list/grid layouts, search, filters, sorting, badges, ACL visibility, and checksums.
