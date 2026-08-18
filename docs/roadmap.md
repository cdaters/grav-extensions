# Extension roadmap

Only working extensions live under `plugins/` or `themes/`. Planned extensions
remain here until their first runnable development release, so an empty folder
can never be mistaken for an installable Grav package.

| Order | Working name | Purpose | State |
| ---: | --- | --- | --- |
| 1 | Prism Gallery | Accessible mixed-media galleries and protected local-media delivery | Development release |
| 2 | Site Safeguard | Verified backup, restore, and clean deployment packages | Specification queued |
| 3 | Image Foundry | Original-preserving optimization and modern derivatives | Specification queued |
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
