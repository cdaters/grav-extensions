# Site Safeguard

Site Safeguard creates portable, self-describing Grav recovery packages,
validates every archived file against SHA-256 metadata, and extracts complete
packages into an isolated directory outside the running site.

Version 0.1 is intentionally a **prepare-and-prove** release. It does not replace
the live webroot. A future promotion workflow will be added only after
maintenance mode, an immediately usable rollback copy, process separation, and
post-promotion health checks are implemented and tested.

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
- protected, one-time package download URLs.

It is complementary to Grav Commander. Commander remains a trusted operator's
file manager and backup workbench. Site Safeguard owns verified, portable
deployment/recovery packages and staged restore orchestration.

## Requirements

- Grav 2.0+
- PHP 8.3+
- PHP ZIP extension
- Grav API/Admin2 plugin 1.0+
- write access to two directories outside the public Grav root

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

```text
../site-safeguard-packages
../site-safeguard-stage
```

Site Safeguard refuses to use either directory if it resolves inside the public
Grav root.

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
4. Download the package through its short-lived, one-time URL and transfer it to
   another site using a protected channel.
5. On the destination, drop the ZIP onto **Validate another package**.
6. Inspect it again, then select **Create stage**.
7. The package is extracted outside the running Grav root and every staged file
   is hashed again. The current site remains untouched.

An imported package that fails structural or checksum validation is deleted
instead of being retained in the package library.

The configured package-retention limit is a hard stop, not an automatic prune.
When the limit is reached, an operator must explicitly decide which obsolete
package to delete before another can be created or imported.

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
- API operations require `site-safeguard.manage` or `site-safeguard.stage`;
  super administrators are accepted.
- The `site-safeguard.promote` permission is reserved but has no executable
  operation in version 0.1.
- ZIP entry names reject NUL bytes, backslashes, absolute/drive paths, and `..`.
- Duplicate entries and ZIP symlinks are rejected.
- Entry count, package bytes, and expanded bytes are bounded.
- Deep hashing occurs only after structural validation succeeds.
- Stage deletion is containment-checked and cannot target the stage root itself.
- Package downloads use 48-character random, short-lived, single-use tokens and
  no-store/no-referrer response headers. Token read-modify-write operations are
  serialized with a filesystem lock, and token data stays beside the protected
  packages rather than inside the site backup source.
- The Admin inspection response reports checksum totals and validation results;
  it does not send the potentially large per-file checksum map to the browser.

## Known limitations of 0.1

- No live promotion or rollback action.
- No scheduler integration or remote/object-storage provider.
- Package creation and deep inspection run synchronously and remain subject to
  PHP/web-server execution limits on very large sites. Prefer the CLI for large
  sites.
- Symbolic links are recorded as warnings and omitted. They must be recreated
  explicitly on the destination.
- File ownership, extended ACLs, and every platform-specific permission bit are
  not preserved by the portable ZIP format.
- External protected storage is not bundled automatically.

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
