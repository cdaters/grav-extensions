# Grav Commander

Grav Commander is an Admin2-first file manager, archive toolkit, and guarded Backup Center for Grav 2.

It is intentionally cautious. Grav Commander is meant to help trusted administrators handle common file, ZIP, and backup work from inside Admin2 without turning Grav into an unchecked hosting control panel.

## Status / Alpha Notice

Grav Commander is alpha software for Grav 2 and Admin2. Use it locally or on staging first, review the configured roots and permissions, and keep independent server backups for production sites.

## Features

- Admin2 sidebar page with Files and Backups sections.
- Configured root browser for pages, themes, plugins, config, data, and logs.
- Text file viewing and editing with extension allow/block lists.
- Upload, download, rename, copy, move, delete, and folder creation actions.
- ZIP creation and guarded ZIP extraction.
- Backup profiles with friendly and expert editing modes.
- Manual site, file, and folder backups.
- Safety backups before destructive file operations when enabled.
- Backup notes, manifests, health checks, details modal, download, delete, and guarded restore.
- Scheduled backup definitions mirrored into Grav scheduler custom jobs.
- CLI backup command for cron, SSH, or scheduled workflows.

## Requirements

- Grav 2.0 or newer.
- Grav API plugin 1.0 or newer.
- Admin2 / admin-next.
- PHP 8.3 or newer.
- PHP `zip` extension for archive, backup, and restore tools.
- Server cron running `bin/grav scheduler` if scheduled backups should run automatically.

## Installation

Install the plugin into:

```text
user/plugins/grav-commander
```

The plugin folder should contain at least:

```text
grav-commander.php
blueprints.yaml
grav-commander.yaml
permissions.yaml
admin-next/pages/grav-commander.js
cli/BackupCommand.php
classes/
```

Enable the plugin through Admin2 or in `user/config/plugins/grav-commander.yaml`:

```yaml
enabled: true
```

Clear the Grav cache after installing or updating:

```bash
bin/grav clearcache
```

Open Admin2 and look for **Grav Commander** in the sidebar.

## Configuration

Default configuration lives in `grav-commander.yaml`. Site-specific overrides belong in:

```text
user/config/plugins/grav-commander.yaml
```

Important settings include:

```yaml
admin:
  show_sidebar: true

max_upload_size: 10485760
max_edit_size: 1048576
allow_php_editing: false
allow_recursive_delete: false
auto_backup_on_write: true

backup:
  enabled: true
  path: ../gcmdr_backups
  max_backups: 25
  allow_site_restore: false
```

The default backup path is `../gcmdr_backups`, resolved relative to the Grav root. On many hosts this places backups beside the public site directory rather than inside it. For production, prefer an absolute or relative path outside the public web tree when your host allows it.

Grav Commander creates the backup folder when needed and writes basic `.htaccess` and `index.html` protection files, but outside-root storage is still preferred.

## Permissions

The plugin declares these permissions in `permissions.yaml`:

```yaml
grav-commander:
  browse: true
  write: true
  backup: true
  restore: true
```

Super admin users should have access automatically. For non-super users, grant only the capabilities they need:

- `browse`: list, view, and download allowed files.
- `write`: create, edit, upload, rename, copy, move, delete, zip, and extract inside writable roots.
- `backup`: create, list, download, configure, run, and delete backups.
- `restore`: restore file/folder backups and, only if enabled, full-site backups.

## File Manager Behavior

Configured roots are defined under `roots` in `grav-commander.yaml`. Each root has a label, path, and writable flag. Paths are resolved from the Grav root unless they are absolute.

Files are handled in three broad modes:

- Editable: allowed by `editable_extensions`, not blocked, inside a writable root, writable on disk, and below `max_edit_size`.
- View/read-only: allowed by `viewable_extensions`, not blocked, and text-like.
- Binary/read-only: selectable and downloadable, but not opened in the editor.

Executable and server-side script extensions are blocked by default:

```yaml
blocked_extensions:
  - php
  - phtml
  - phar
  - sh
  - bash
  - zsh
  - exe
```

Do not remove risky extensions from `blocked_extensions` on production sites unless every Admin user with access is fully trusted.

Markdown files under the Pages root can be opened in Grav's page editor. Raw text editing remains available for power users.

## Archive Tools

Archive tools currently focus on ZIP files:

- Create a ZIP from a selected file or folder.
- Upload a `.zip` file and extract it under the selected root.
- Extract to the ZIP's current folder or another path under the same root.
- Keep no-overwrite extraction by default.
- Optionally allow overwrite through plugin configuration.
- Guard against absolute paths and parent-directory traversal in ZIP entries.
- Optionally skip common macOS ZIP clutter such as `__MACOSX`, `.DS_Store`, and `._filename`.

Relevant defaults:

```yaml
archive:
  enabled: true
  allow_create: true
  allow_extract: true
  allow_overwrite: false
  backup_before_extract: true
  max_extract_files: 5000
  max_extract_bytes: 209715200
  skip_macos_junk: true
```

## Backup Center

The Backup Center provides profile-driven site backups and safety backups for file operations.

Default profiles:

- `full_site`: the Grav root, excluding cache/log/temp/backup folders.
- `user_folder`: the `user/` folder.
- `pages_media`: `user/pages` only.
- `config_data`: `user/config`, `user/accounts`, and `user/data`.

Backups include `backup-info.json`, and site backups also include a manifest. Backup notes and metadata can be reviewed from the backup row info button.

Backup file names use a configurable token template:

```yaml
backup:
  archive_name_template: gcmdr-[HOST]-[PROFILE]-[DATE]-[TIME_TZ]
  add_random_if_inside_site_root: true
```

The `.zip` extension is added automatically. If backups are configured inside the site root and `[RANDOM]` is not in the template, Grav Commander can add a random suffix as a guardrail.

Full-site restore is disabled by default:

```yaml
backup:
  allow_site_restore: false
```

Leave full-site restore disabled unless you are intentionally testing or recovering a site.

## Scheduled Backups

Schedules are edited in the Backup Center and saved to the plugin configuration. When schedules are saved, Grav Commander mirrors managed jobs into:

```text
user/config/scheduler.yaml
```

Managed job IDs use the `grav-commander-backup-` prefix.

Example cron expressions:

```text
0 * * * *      hourly at minute 0
0 3 * * *      daily at 3:00 AM
0 3 * * 1      weekly Monday at 3:00 AM
0 3 1 * *      monthly on the 1st at 3:00 AM
```

The server must still run Grav's scheduler from system cron, for example:

```bash
* * * * * cd /path/to/grav && bin/grav scheduler 1>> /dev/null 2>&1
```

Without that host-level cron entry, schedules can be saved but will not run automatically.

## CLI Usage

Create a backup with a configured profile:

```bash
bin/plugin grav-commander backup --profile=pages_media --reason=manual-cli --note="Before content edits"
```

Options:

```text
--profile, -p   Backup profile key. Default: full_site
--reason, -r    Reason stored in metadata. Default: cli
--note          Optional note stored in metadata.
```

Scheduled backup jobs use the same command internally.

## Security Notes

- Treat file management, archive extraction, backup download, and restore as trusted-admin features.
- Keep `blocked_extensions` conservative.
- Keep backups outside the public site root when possible.
- Use dedicated permissions for non-super users.
- Keep full-site restore disabled unless actively needed.
- Test restores on staging before relying on production recovery.
- Backup download links use short-lived, one-time tokens requested by an authenticated Admin2 session, then stream through a token-only browser download route.
- Basic `.htaccess` protection helps Apache, but Nginx, Caddy, and other servers need server-level rules if backups are exposed under the web root.

## Known Limitations

- Grav 2 and Admin2 are still moving targets.
- The editor is intentionally simple and is not yet a full code editor.
- ZIP support is currently limited to ZIP archives.
- Long-running large backups depend on PHP and hosting limits.
- Scheduler jobs require host cron; saving a schedule alone is not enough.
- Full-site restore is intentionally guarded and should be considered a recovery tool, not a deployment system.
  Large backup downloads stream through a tokenized browser route so the browser can handle the ZIP directly without loading the full archive into Admin2 JavaScript memory.

## Roadmap Link

See [ROADMAP.md](ROADMAP.md) for directional plans.

## Contributing / Feedback

Issues, testing notes, and focused pull requests are welcome at:

```text
https://github.com/cdaters/grav-plugin-grav-commander/issues
```

Grav Commander is maintained by Craig Daters. PixelWizard may appear in community context, but project metadata uses the professional author name.

## License

MIT. See [LICENSE](LICENSE).
