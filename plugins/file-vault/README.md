# File Vault

File Vault is an independent, site-agnostic Grav 2 plugin for protected download libraries. It combines an Admin2 catalog manager with a responsive public download library, expiring signed links, access controls, checksums, and download counters. Branding, introductory copy, categories, and catalog content belong to each Grav site rather than the plugin.

## Highlights

- Stores binaries in a configurable location outside the public web root.
- Delivers files through HMAC-signed, expiring URLs with byte-range support.
- Gates external http/https destinations behind the same signed-link workflow before issuing a no-referrer redirect.
- Batch-uploads files through a visible drag-and-drop queue or multi-file picker and edits metadata from a dedicated, theme-aware Admin2 page.
- Defines the upload extension allowlist directly from the custom Admin2 manager.
- Provides managed category drawers with create, rename, reorder, description, reassignment, and safe deletion workflows.
- Supports descriptions, versions, categories, tags, release dates, custom served names, featured state, ordering, and per-file ACL strings.
- Provides list and grid layouts with labeled search and category controls, sorting, responsive design, and light/dark theme support.
- Lets administrators choose the public default category and display, edit the hero text, toggle its metrics, and customize the search prompt.
- Embeds a complete filtered browser or an individual download in any Shortcode Core-enabled page.
- Keeps one-off page assets protected but unlisted so they never appear in public catalogs or category collections.
- Tracks download totals without a database and uses file locks for safe counter updates.
- Optionally records successful download activity with configurable IP handling, short retention, authenticated usernames, and an Admin2 viewer/purge workflow.
- Supports per-item password protection and atomic download limits that automatically delist exhausted entries.
- Publishes SHA-256 checksums and supports `NEW`, `HOT`, and `FEATURED` badges.

## Requirements

- Grav 2.0+
- PHP 8.3+
- Grav API/Admin2 plugin 1.0+
- Shortcode Core (optional; required only for page embeds)

## Installation

Copy the `file-vault` directory to `user/plugins/file-vault`. Copy `file-vault.yaml` to `user/config/plugins/file-vault.yaml` when you want site-specific overrides.

The default protected storage path is `../file-vault-files`, resolved from the Grav root. Create that directory and ensure the web-server user can read and write it. In production, keep this directory outside the public document root.

Create a page named `file-vault.md` (for example `user/pages/02.downloads/file-vault.md`). Its filename selects the bundled public template.

After installation, clear Grav cache, open **File Vault** in Admin2, review
**Vault settings**, create the desired categories, and upload a small test file
before importing a large archive. Confirm the public route and a complete
download before enabling activity records or strict limits.

## Public view settings

Open **File Vault → Public view settings** in Admin2 to edit the public eyebrow, heading, introduction, visible metrics, initial category, initial list/grid display, and search placeholder. Choosing a focused initial category keeps a large archive from presenting every file at once; visitors can still select another category from the labeled **Browse category** control.

The same settings are available under `public:` in `user/config/plugins/file-vault.yaml`.

## Embedding downloads

When the Grav **Shortcode Core** plugin is installed and enabled, File Vault registers two original shortcodes. They work in ordinary and modular page content wherever Shortcode Core processes Markdown.

Embed a filtered browser:

```text
[file-vault category="manuals" view="grid" controls="true" title="Product manuals" limit="6" /]
```

Supported attributes are `category` (category id or name), `view` (`list` or `grid`), `controls`, `title`, `limit`, `hero`, `stats`, and `access`.

Embed one file as a card or button:

```text
[file-download id="product-manual" layout="card" /]
[file-download filename="manual.pdf" label="Download the manual" /]
```

Supported attributes are `id` or `filename`, `layout` (`button` or `card`), `label`, and `access`. The optional `access` attribute controls whether the shortcode renders for the current user; it never bypasses the ACL stored on a file. Protected files still withhold their signed download URL from unauthorized visitors.

### Unlisted one-off downloads

Select **Unlisted assets** in the File Vault manager before uploading a file, or uncheck **Listed** on any existing item. The item remains enabled and receives the same expiring links, passwords, ACL checks, download limits, counters, and activity handling as a catalog download, but it is omitted from the public Downloads page and every `[file-vault]` collection.

Each metadata panel displays a ready-to-copy `[file-download id="…" /]` shortcode. Paste it into the page that should offer the file. Do not also place the binary in that page's media folder: page media lives under the public web root and can be linked directly, bypassing File Vault.

## Catalog data

Metadata and managed categories live in `user/data/file-vault/catalog.json`; download statistics live in `user/data/file-vault/stats.json`. If activity recording is enabled, retained events live in `user/data/file-vault/activity.json`. The signing key is generated at first use in the same protected data folder. Back up these files with the site. File Vault automatically reads older catalog formats; version 0.6 writes catalog schema 4 and activity schema 1.

## Permissions

Grant `file-vault.manage` to Admin2 users who may upload files and edit the catalog. `api.super` and `admin.super` users are accepted as super administrators.

For an individual protected file, set its ACL field to a Grav permission such as `site.login`. The catalog can show the locked entry to guests while withholding its download link.

## Passwords and download limits

Every stored file or URL entry can have its own optional download password and download limit. Passwords are stored only as one-way hashes. A value of `0` makes the download limit unlimited. When a limited item reaches its count, File Vault rejects existing tokens and removes the item from the public catalog. Resetting its count in Admin2 makes it available again immediately.

The count check and increment happen under the same statistics-file lock, preventing simultaneous successful requests from exceeding the configured limit.

## Secure URL downloads

Choose **Add URL** in the File Vault manager to catalog an external `http` or `https` destination instead of uploading a binary. Visitors receive a normal expiring File Vault URL; ACL, password, and download-limit checks occur before File Vault redirects them with a `no-referrer` policy.

The destination is omitted from public catalog data, but it cannot remain secret after a successful redirect: the visitor's browser must receive and navigate to that URL. Use stored-file delivery when the source location itself must never be disclosed.

## Upload allowlist

Open **File Vault → Vault settings** to edit the comma-separated list of extensions accepted by uploads. The browser picker and drop zone use this list for guidance and the server independently enforces it. Select a category drawer, then drop one or more files into its upload target; each file is processed independently, so one invalid file does not block the rest of a batch. The **Upload files** button opens the same multi-file queue. This setting does not apply to secure URL entries, which instead require an `http` or `https` destination.

## Download activity and privacy

Activity recording is disabled by default. Configure it under **File Vault → Vault settings**, then review events from **File Vault → Activity**. File Vault records successful, countable downloads only; wrong passwords, expired links, range continuations, and HEAD checks are not events. Each event includes the item, timestamp, source type, and an authenticated Grav username when available.

IP storage can be disabled, pseudonymously hashed, or stored in full. Hashing is the default if recording is enabled. Browser user agents and proxy headers are both off by default. The configured retention window is applied whenever a new event is written, the log is capped at 5,000 events, and administrators can clear it immediately. Only trust `X-Forwarded-For` when a controlled reverse proxy replaces that header.

Hashed IP addresses may still qualify as personal data. Before enabling collection, document its purpose and lawful basis, update the public privacy notice, restrict Admin access, and choose the shortest useful retention period. This plugin provides technical controls, not legal advice.

## Security notes

- Uploaded names are reduced to safe basenames and checked against the configured extension allowlist.
- Stored paths cannot contain directory traversal segments.
- Tokens contain only a catalog id and expiry timestamp; the original storage path is never exposed.
- Download responses set `nosniff`, `no-store`, and `no-referrer` headers.
- Password-protected downloads never expose password hashes in Admin or public API responses.
- Secure URL entries allow only `http` and `https` destinations and use no-referrer redirects.
- The Admin2 delete action distinguishes removing a catalog entry from permanently deleting its stored binary.
- Activity logging is opt-in, never blocks a download if its log write fails, and omits user-agent and raw IP data by default.

## Updating

Back up the Grav site, `user/data/file-vault`, and the configured protected
storage directory. Replace only `user/plugins/file-vault`, preserve
`user/config/plugins/file-vault.yaml`, and clear Grav cache. File Vault reads
older catalog formats and writes the current schema when data changes, but a
backup remains the rollback boundary.

Never restore an old plugin directory by deleting current catalog data. Source,
configuration, metadata/statistics, activity, and protected binaries are
separate layers.

## Uninstallation

Disabling or removing the plugin does not intentionally erase the protected
storage or `user/data/file-vault`. Preserve those paths until you have confirmed
that no page shortcode or public route still depends on them. Delete retained
data only as a separate, explicit site-owner operation.

## Troubleshooting

- File Vault is absent from Admin2: verify the API/Admin2 dependency, plugin
  status, permissions, and cache.
- Upload is rejected: compare the server-enforced extension allowlist and byte
  limit with PHP/web-server upload limits.
- A shortcode prints literally: install and enable Shortcode Core.
- A signed link returns 403: reload for a fresh token, then verify item status,
  ACL, password, limit, and the server clock.
- A protected file cannot stream: verify the configured storage path and web
  server user's read permission; keep the path outside the public document root.
- Activity shows a proxy address: trust `X-Forwarded-For` only when a controlled
  reverse proxy replaces that header and the web server cannot be reached around
  it.

File Vault is an original implementation inspired by the general workflow of download-management software. It contains no code from Grav Downloads Pro and does not require the Grav Premium License Manager.
