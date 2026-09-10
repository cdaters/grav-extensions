# Site Safeguard

Site Safeguard creates portable, self-describing Grav recovery packages,
validates every archived file against SHA-256 metadata, and extracts complete
packages into an isolated directory outside the running site.

Version 0.3 adds a guarded **Restore** action to Admin2 for transfers between
installations such as production and DDEV. The authenticated web request only
queues a uniquely identified operation and launches a detached PHP CLI worker;
it never replaces the code serving the request. The worker requires a verified
stage, creates and boots a verified rollback stage before touching the site,
preserves host-local paths, uses Grav maintenance mode, mirrors the staged site,
verifies every restored file, and boots Grav again in a fresh PHP process.

## Why this exists

Grav 2 can create, list, download, and delete ordinary backups. Site Safeguard
adds the missing portability and recovery evidence:

- explicit package profiles;
- a machine-readable package manifest;
- a per-file SHA-256/size inventory;
- rejection of absolute, parent-traversal, duplicate, oversized, or symlink ZIP
  entries;
- imported-package inspection before retention;
- extraction only into a unique staging directory outside the public site;
- a second checksum pass against the extracted stage; and
- protected, short-lived package download URLs with browser-probe, retry, and
  HTTP range-request support;
- file mode and modification-time preservation; and
- rollback-first full-site restoration with a durable recovery journal.

It is complementary to Grav Commander. Commander remains a trusted operator's
file manager and backup workbench. Site Safeguard owns verified, portable
deployment/recovery packages and staged restore orchestration.

## Requirements

### Minimum

- Grav 2.0+
- PHP 8.3+
- PHP ZIP extension
- Grav API/Admin2 plugin 1.0+
- write access to two directories outside the public Grav root

### Preferred and feature-specific

- for the Admin **Restore** action: a Unix-like host with `/bin/sh`, `nohup`,
  `proc_open()`, and an executable PHP CLI binary
- Zlib for the planned streaming SSA format
- PHP Sodium for the preferred future SSS authenticated-encryption profile
- OpenSSL is optional and may support a separately versioned AES-256-GCM
  compatibility profile after independent tests; it is not a silent substitute
  for Sodium

The Admin2 **Environment readiness** panel checks these capabilities after an
installation or update and distinguishes required, recommended, optional, and
restore-only items. Missing Sodium does not prevent ZIP or unencrypted SSA use.
Secure archives will fail with guidance instead of silently downgrading.

Environment Readiness, Package Library, Isolated Staging, and Recovery Journal
are collapsible. Healthy or quiet sections start collapsed where appropriate;
warnings, failures, active work, and unrecognized staging content remain
visible. The browser remembers the operator's disclosure choices. Readiness
always states its aggregate result in text as well as green, amber, or red.

## Installation

Copy the complete folder to:

```text
user/plugins/site-safeguard
```

Enable it and clear Grav cache:

```bash
bin/grav clearcache
```

Open **Site Safeguard** in Admin2. Before creating a package, review the package
and staging paths in the plugin configuration. Their defaults resolve beside
the Grav directory:

The dashboard's **Plugin settings** button opens storage, limits, preservation,
restore, and PHP CLI configuration directly.

```text
../site-safeguard-packages
../site-safeguard-stage
```

Site Safeguard refuses to use either directory if it resolves inside the public
Grav root.

Full-site restore is disabled by default. Enable **Allow full-site restore**
only after reviewing the PHP CLI path and preserved host-local paths. Enable
**Allow the Admin2 Restore button** separately when the hosting environment
passes the launcher checks. DDEV installations should preserve `.ddev`;
production commonly preserves environment files and runtime folders. The
destination's `user/config/plugins/site-safeguard.yaml` is host-local and
preserved by default, preventing a transferred package from replacing the
destination's package paths and restore safety settings.
Grav's `user/config/security-private.php` is also always preserved so a restore
cannot silently copy one host's nonce/HMAC identity onto another installation.

As of 0.3.12, paths excluded by the destination's global `exclude_paths` or
`profiles.portable_site.exclude_paths` are also preserved during restore. They
cannot be replaced or pruned safely because the rollback package omits them.
Remove an exclusion before creating a verified rollback if you intend to import
that path. A failed rollback preflight stops before site replacement; its journal
retains any created package/stage IDs. It does not attempt an unverified rollback.

Boot checks create missing runtime directories (including `user/accounts`) and
use a temporary nonce identity when the staged site omitted one. Temporary
files/directories are removed before the stage's second integrity check. The
destination's real nonce identity remains preserved.

After saving either restore switch in Admin2, verify that **Enabled** is the
purple selected option. An orange circular marker beside the label means the
value is a saved override of Site Safeguard's disabled-by-default setting; it
does not indicate an error. Admin2 may briefly retain the pre-save form styling,
so reload the settings page or leave it and return before diagnosing a failed
save. The durable check is that **Enabled** remains selected after that fresh
load and the orange override marker is present.

## Package profiles

### Portable site

Includes the complete Grav installation while omitting caches, logs, temporary
files, generated backups, local development metadata, macOS debris,
`node_modules`, and runtime `.env` files. It is the only default profile that
can be staged as a complete site.

### User folder

Includes `user/`: pages/media, configuration, accounts, plugins, themes, and
plugin data. This is a partial recovery package and cannot be staged as a
complete site.

### Pages and media

Includes only `user/pages`. This is useful for content snapshots and cannot be
staged as a complete site.

Profiles are configured under `profiles:` in
`user/config/plugins/site-safeguard.yaml`. Include and exclude paths are always
relative to the Grav root. A profile marked deployable must actually contain a
Grav site; inspection checks for `index.php`, `system/`, and `user/`.

## Create, inspect, transfer, and stage

1. Choose **Portable site** and enter an operator note.
2. Create the package. Source files are hashed while the ZIP is assembled.
3. Select **Inspect**. Site Safeguard reopens the archive, validates its
   structure, and independently hashes every archived file.
4. Download the package through its short-lived protected URL and transfer it
   to another site using a protected channel. The URL remains usable only until
   its configured expiry so a browser safety probe, range request, or interrupted
   transfer can retry without destroying the authorization.
5. On the destination, drop the ZIP onto **Validate another package**.
6. Inspect it again, select **Create stage**, review the in-page scope, and
   select **Confirm stage**. The first click does not call the API.
7. The package is extracted outside the running Grav root and every staged file
   is hashed again. The current site remains untouched.
8. Review the stage and select **Restore**. An in-page panel explains the scope;
   type the exact confirmation phrase and select **Confirm restore**. Opening
   the panel or entering a wrong phrase cannot call the API. Admin2 follows the
   detached operation through its durable recovery journal while Site Safeguard
   creates and boots a rollback stage before maintenance mode begins.

The displayed CLI command remains the recovery fallback when the Admin launcher
is disabled or unavailable.

An imported package that fails structural or checksum validation is deleted
instead of being retained in the package library.

The configured package-retention limit is a hard stop, not an automatic prune.
When the limit is reached, an operator must explicitly decide which obsolete
package to delete before another can be created or imported.

Package and stage deletion use an in-page two-click safeguard. The first click
changes only the selected action to **Confirm delete** (or **Confirm removal**
for an unrecognized staging directory) and displays the exact scope. Select
**Cancel** to disarm it. The dashboard uses explicit authenticated POST action
routes so shared hosts and edge security layers do not need to pass raw HTTP
`DELETE` requests through to Grav. During a rolling update, if a long-running
PHP worker still has the preceding route table, Admin2 automatically retries
through the established package/stage route using the API's authenticated
method-override form. The operator does not need to restart PHP just to finish
the cleanup.

Package staging and full-site restore also use in-page confirmation controls;
Site Safeguard does not depend on native browser `confirm()` or `prompt()`
dialogs for any Admin action. This keeps browser dialog policies, private mode,
and embedded Admin2 rendering from turning an operator control into a silent
no-op.

## CLI

Create a portable package:

```bash
bin/plugin site-safeguard create --profile=portable_site --note="Before production deployment"
```

Inspect a retained package:

```bash
bin/plugin site-safeguard inspect safeguard-example-portable_site-20260818-120000-a1b2c3.zip
```

Create an isolated verified stage:

```bash
bin/plugin site-safeguard stage safeguard-example-portable_site-20260818-120000-a1b2c3.zip
```

Restore the current site from a verified deployable stage:

```bash
bin/plugin site-safeguard restore safeguard-example-portable_site-20260818-120000-a1b2c3-d4e5f6 \
  --confirm="RESTORE THIS SITE"
```

The manual CLI command remains available from a shell or hosting control panel
terminal. Use the same operating-system user that owns the Grav files. The
Admin API does not perform the restore in-process; it can only launch this same
CLI workflow as a detached worker.

CLI commands use bare filenames from the protected package directory. They do
not accept arbitrary filesystem paths.

## What a package contains

All site payload paths are stored beneath `site/`. Package metadata lives under:

```text
_site-safeguard/manifest.json
_site-safeguard/checksums.json
```

The manifest records the generator/schema version, time, source host, Grav/PHP
versions, profile, operator note, counts, byte totals, and warnings. The
checksum document maps every archived file to its SHA-256, size, and source
modification time.

The package's own SHA-256 is displayed separately in Admin2 and cannot be
embedded inside itself.

## Sensitive data warning

A complete site package normally contains account password hashes, plugin data,
site configuration, and API/service credentials stored in YAML. Treat every
package as a secret:

- keep package and stage directories outside the webroot;
- restrict filesystem and Admin permissions;
- transfer packages through an authenticated channel;
- remove packages/stages when their recovery window closes; and
- never commit a package to source control.

Runtime `.env` files are excluded by default because they are
environment-specific. Review destination environment variables and external
storage separately.

## File Vault and other external storage

File Vault's recommended binary storage path is outside the Grav root. Site
Safeguard does not silently reach outside the configured package source and
therefore does not bundle those binaries in a portable-site package. Back up or
transfer that protected directory separately, along with any external object
storage, database, or service dependency.

File Vault metadata under `user/data/file-vault` is included in complete/user
packages. Without the protected binaries it describes, downloads will not be
complete on the destination.

## Security model

- Package/stage roots must resolve outside `GRAV_ROOT`.
- API operations require the narrow `site-safeguard.manage`,
  `site-safeguard.stage`, or `site-safeguard.restore` permission; super
  administrators are accepted.
- The restore route requires `site-safeguard.restore`, both restore switches,
  an exact confirmation phrase, a verified deployable stage, and an available
  detached CLI launcher. It only queues the worker and returns HTTP 202.
- ZIP entry names reject NUL bytes, backslashes, absolute/drive paths, and `..`.
- Duplicate entries and ZIP symlinks are rejected.
- Entry count, package bytes, and expanded bytes are bounded.
- Deep hashing occurs only after structural validation succeeds.
- Stage deletion is containment-checked and cannot target the stage root itself.
- Package downloads use HMAC-signed, short-lived stateless tickets plus
  no-store/no-referrer response headers. A ticket remains valid only until its
  bounded expiry so browser HEAD probes, worker/process changes, and HTTP range
  retries cannot destroy the authorization before the package is saved. The
  signature uses Grav's private per-site nonce key; the ticket contains only the
  package filename, protected-directory locator, issuing host, expiry, version,
  and a random nonce. Its route remains on the issuing browser origin and replay
  through another host is rejected. Invalid tickets never
  expose a protected path; storage and package-resolution failures are written
  only to the Grav log under a safe browser-visible reference code.
- The Admin inspection response reports checksum totals and validation results;
  it does not send the potentially large per-file checksum map to the browser.
- A restore requires an exact confirmation phrase and a non-blocking global
  lock, revalidates and boots the source stage, creates and boots a rollback
  stage, records a journal outside the site, and uses `.upgrading` maintenance
  mode while files change.
- Host-local paths are preserved, and the post-restore site must pass both its
  checksum inventory and a fresh-process Grav boot check. Failure triggers the
  verified rollback stage automatically.
- Isolated stages retain private `0700` directory permissions. During restore,
  non-preserved Grav directories are normalized to `0755` and verified so
  split-process web servers can traverse theme, plugin, media, and Admin paths.
  Intentionally web-deliverable files (CSS, JavaScript, fonts, images, and page
  media) also receive any missing read bits; private configuration/data modes
  remain unchanged.

## Known limitations of 0.3

- No scheduler integration or remote/object-storage provider.
- Restore requires PHP CLI and `proc_open()` for isolated boot checks. The
  Admin launcher additionally requires a Unix-like shell and `nohup`; the
  manual CLI command remains available on other hosts.
- The Admin button still depends on a healthy Grav/Admin installation. A future
  standalone recovery assistant will provide a recovery workflow outside the
  site being replaced.
- Package creation and deep inspection run synchronously and remain subject to
  PHP/web-server execution limits on very large sites. Prefer the CLI for large
  sites.
- Symbolic links are recorded as warnings and omitted. They must be recreated
  explicitly on the destination.
- File ownership, extended ACLs, and every platform-specific permission bit are
  not preserved by the portable ZIP format. File modes are retained; restored
  non-preserved directory modes are intentionally normalized to portable
  web-safe `0755` permissions.
- External protected storage is not bundled automatically.

## Roadmap

The next recovery milestones are deliberately separated from the tested 0.3
restore core:

- **Recovery Assistant:** a small, independently authenticated, single-use
  Kickstart-style application that can inspect a package, test hosting
  prerequisites, restore without depending on the installed site, surface the
  rollback path, and remove/lock itself after completion.
- **Scheduling:** Grav Scheduler integration, overlap locks, readable cron
  previews, success/failure history, notifications, scheduled verification, and
  retention policies such as daily/weekly/monthly generations.
- **Off-site providers:** a provider interface followed by S3-compatible object
  storage, SFTP, and WebDAV; client-side encryption, multipart/resumable transfer,
  remote integrity checks, and remote retention must exist before cloud upload
  is considered complete.
- **External data sets:** explicit companion definitions for File Vault binary
  storage and other site dependencies outside `GRAV_ROOT`.
- **SSA/SSS archive family:** design and benchmark an open, versioned,
  forward-only Site Safeguard Archive (`.ssa`) using streaming Deflate and no
  ZIP central directory or per-entry CRC pre-pass. Preserve ZIP as the
  universally available compatibility format. SSA becomes the default only if
  shared-host benchmarks, recovery tooling, corruption handling, and format
  documentation justify that change.
- **Authenticated secure archives:** build Site Safeguard Secure (`.sss`) on
  the SSA record stream with chunked authenticated encryption using PHP Sodium
  (secretstream XChaCha20-Poly1305), a memory-hard Argon2id passphrase derivation
  profile, explicit version/KDF parameters, and independent test vectors. SSS
  must fail closed on truncation, reordering, or tampering and must never fall
  back to unauthenticated encryption.

The detailed format principles and default-format acceptance gate are recorded
in [Site Safeguard archive-format direction](../../docs/site-safeguard-archive-formats.md).

## Updating

Replace `user/plugins/site-safeguard`, preserve the site override under
`user/config/plugins/site-safeguard.yaml`, and clear Grav cache. Packages and
stages live outside the plugin and are not intentionally removed by an update.

## Uninstallation

Removing the plugin does not remove retained packages or stages. Review and
delete those locations separately when you no longer need recovery material.

## Clean-room note

Site Safeguard is an original Grav 2 implementation based on public Grav APIs,
documented recovery principles, and independently designed package formats. It
does not contain source code from Grav premium products or third-party restore
plugins.

## License

MIT. See [LICENSE](LICENSE).
