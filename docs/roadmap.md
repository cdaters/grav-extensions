# Extension roadmap

Only working extensions live under `plugins/` or `themes/`. Planned extensions
remain here until their first runnable development release, so an empty folder
can never be mistaken for an installable Grav package.

| Order | Working name | Purpose | State |
| ---: | --- | --- | --- |
| 1 | Prism Gallery | Accessible mixed-media galleries and protected local-media delivery | Development release |
| 2 | Site Safeguard | Verified backup, restore, and clean deployment packages | 0.2.2 development release; guarded CLI restore available |
| 3 | Image Foundry | Original-preserving optimization and modern derivatives | 0.1.0 development release |
| 4 | Meta Pilot | Canonical, robots, social, structured-data, and sitemap controls | Specification queued |
| 5 | Revision Ledger | Content snapshots, comparisons, retention, and explicit rollback | Specification queued |
| 6 | Lantern Search | Incremental, relevance-ranked, ACL-aware site search | Specification queued |
| 7 | Site Workshop | Focused maintenance, link checking, redirects, and health tools | Specification queued |
| 8 | Edge Console | Narrow, audited Cloudflare operations using scoped API tokens | Specification queued |
| 9 | Page Studio | Grav Admin 2 authoring experience with extensible content blocks | Specification queued |

Existing products that are not part of that build sequence remain first-class:

- **File Vault** — protected catalogs, unlisted one-off downloads, signed links,
  ACLs, passwords, limits, and privacy-conscious activity records.
- **Grav Commander** — dual-pane file management and backup operations, while
  retaining its standalone GPM repository and release lifecycle.
- **Spitfire** — update-safe Quark 2 child theme for SpitfireBBS.com.

Names are original working names and can be revisited before first stable
releases. Implementations must be clean-room work based on public behavior and
documentation, not copied premium source code.

## Site Safeguard recovery roadmap

Site Safeguard 0.2 provides the immediate production-to-DDEV and DDEV-to-
production transfer path: portable packages, repeated validation, verified
staging, rollback-first CLI restore, and automatic rollback when the restored
site cannot boot.

The recovery work that follows is intentionally incremental:

1. **Recovery Assistant** — an independently authenticated, disposable,
   Kickstart-style entry point that can recover a site even when Grav or the
   installed plugin cannot start.
2. **Scheduling** — Grav Scheduler/cron integration, overlap protection,
   verification jobs, retention generations, notifications, and history.
3. **Off-site storage** — an encrypted provider contract followed by
   S3-compatible storage, SFTP, and WebDAV with resumable transfer, remote
   integrity verification, and remote retention.
4. **External data sets** — explicit companion backup definitions for File
   Vault binaries and other protected data stored outside the Grav root.

## Image Foundry roadmap

Image Foundry 0.1 provides the safe derivative foundation: bounded local GD
processing, source hashes, responsive WebP/AVIF sets, opaque delivery, Admin2
operations, CLI parity, and an opt-in Twig `<picture>` helper.

Later milestones can add background/Scheduler queues, additional image engines,
visual before/after comparisons, Grav media-event adapters, and a reversible
replacement workflow. Originals remain authoritative throughout.
