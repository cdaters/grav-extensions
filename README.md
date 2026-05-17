# Grav Commander

**Grav Commander** is an Admin2-first Commander-style file manager and backup playground for Grav 2.

It is intentionally opinionated and guarded. This is not meant to be a reckless cPanel clone inside your CMS. It exposes configured Grav roots, blocks risky executable extensions by default, and creates safety backups before destructive operations when `auto_backup_on_write` is enabled.

## Status

Prototype / alpha. Drop it into a local or staging Grav 2 site first.

## Requirements

- Grav 2.0+
- Grav API plugin 1.0+
- Admin2 / admin-next
- PHP 8.3+
- PHP `zip` extension for backup, restore, and ZIP archive tools
- Server cron calling `bin/grav scheduler` if you want scheduled backups to run automatically

## Install

1. Unzip this folder into:

   ```bash
   user/plugins/grav-commander
   ```

2. Confirm the folder contains:

   ```text
   user/plugins/grav-commander/grav-commander.php
   user/plugins/grav-commander/blueprints.yaml
   user/plugins/grav-commander/admin-next/pages/grav-commander.js
   user/plugins/grav-commander/cli/BackupCommand.php
   ```

3. Enable it in `user/config/plugins/grav-commander.yaml` or through Admin2:

   ```yaml
   enabled: true
   ```

4. Give your admin user the permissions listed below if they are not a super admin.

5. Open Admin2 and look for **Grav Commander** in the sidebar.


## Backup storage location

The default backup path is now outside the Grav root when your host permits it:

```yaml
backup:
  path: ../gcmdr_backups
```

For production, prefer a folder outside the public site tree. On a cPanel-style account where the Grav site is under `/home/retrorealm/public_html`, a safer absolute path would be:

```yaml
backup:
  path: /home/retrorealm/gcmdr_backups
```

You may also use a relative path from the Grav root:

```yaml
backup:
  path: ../gcmdr_backups
```

Grav Commander will try to create the folder and adds basic `.htaccess` / `index.html` protection, but outside-the-site-root storage is still the better default for real sites.


## Backup archive naming

Version 0.3.6 added a tokenized archive name template, rebuilt for Grav Commander. Version 0.3.8 added short-lived token-based backup download links and auto-expiring Admin2 notices. Version 0.3.10 adds collapsible Backup Profile / Schedule editors plus a corrected Open in Grav Editor action for page Markdown files. The `.zip` extension is added automatically.

Default:

```yaml
backup:
  archive_name_template: gcmdr-[HOST]-[PROFILE]-[DATE]-[TIME_TZ]
  add_random_if_inside_site_root: true
```

Example result:

```text
gcmdr-retrorealm-org-full-site-20260517-001555GMT-0700.zip
```

Supported tokens:

```text
[PREFIX]        gcmdr
[HOST]          current host name, filename-safe
[SITE]          site title, filename-safe
[SITENAME]      alias of [SITE]
[PROFILE]       backup profile key
[PROFILE_LABEL] backup profile label
[SCOPE]         file or site
[TYPE]          alias of [SCOPE]
[REASON]        manual, scheduled, pre-delete, etc.
[ROOT]          file-manager root key for file backups
[PATH]          source path for file backups
[DATE]          YYYYMMDD
[YEAR]          YYYY
[MONTH]         MM
[DAY]           DD
[WEEK]          ISO week number
[WEEKDAY]       English day name, filename-safe
[TIME]          HHMMSS
[TIME_TZ]       HHMMSSGMT±0000
[TZ]            timezone name, filename-safe
[GMT_OFFSET]    plus0000 / minus0700 style offset
[VERSION]       Grav Commander version
[RANDOM]        16-character random hex string
```

If backups are stored inside the Grav/site root and `[RANDOM]` is not already present, Grav Commander can append a random suffix automatically as a security guardrail.

## Permissions

The API endpoints check these permissions:

```yaml
access:
  admin:
    login: true
  grav-commander:
    browse: true
    write: true
    backup: true
    restore: true
```

Super admin users should inherit access automatically. For non-super users, add only the capabilities they need.


## File viewing, editing, and downloads

Grav Commander separates file behavior into three buckets:

- **Editable**: listed in `editable_extensions`, allowed by `blocked_extensions`, under the edit size limit, writable on disk, and inside a writable root. These can be opened and saved.
- **View/read-only**: listed in `viewable_extensions`, allowed by `blocked_extensions`, and text-like. These can be opened for preview and downloaded, but not saved.
- **Binary/read-only**: everything else. These can be selected, downloaded, moved/copied/deleted if permissions allow, and backed up.

Open the plugin configuration from **Admin2 → Plugins → Grav Commander**, or from the **Settings** button in the Grav Commander page. The important file controls are:

```yaml
editable_extensions:
  - md
  - yaml
  - twig
  - css
  - js

viewable_extensions:
  - md
  - yaml
  - log
  - rev
  - csv

blocked_extensions:
  - php
  - sh
  - exe
```

Do not remove risky executable/script extensions from `blocked_extensions` on a production site unless you truly trust every Admin user.

## Archive tools

Version 0.3.2 added first-pass ZIP handling for common Grav housekeeping jobs:

- Upload a `.zip` file into a writable root, then select it and choose **Extract ZIP**
- Select a file or folder and choose **Zip** to create a standard ZIP archive beside it
- Extract into the ZIP's current folder or another folder under the selected root
- Optional overwrite mode, locked behind plugin configuration
- Traversal guardrails against absolute paths and `../` entries
- Optional skipping of macOS ZIP clutter such as `__MACOSX`, `.DS_Store`, and `._filename`

Relevant configuration:

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

The default no-overwrite behavior is intentional. For plugin updates or page bundles that replace existing files, enable overwrite only when you trust the ZIP and have a backup.

## Backup Center

Grav Commander includes a Grav-native Backup Center:

- Backup profiles with a friendly form editor
- Optional Expert JSON mode for raw profile editing
- Site backup manifests
- Backup notes
- Backup health checks
- Manual file/folder backups
- Auto safety backups before destructive file operations
- Download, delete, and guarded restore
- Scheduled backup definitions tied to backup profiles
- CLI command for scheduled or SSH-driven backups

Default profiles:

- `full_site`: everything under the Grav root, minus cache/log/temp/backup folders
- `user_folder`: everything under `user/`
- `pages_media`: `user/pages` only
- `config_data`: `user/config`, `user/accounts`, and `user/data`

Profiles are edited in **Backup Center → Edit profiles**. They are saved to:

```text
user/config/plugins/grav-commander.yaml
```

Profile keys may contain letters, numbers, hyphens, and underscores. Include and exclude paths are relative to the Grav root. Use `.` only when the profile should include the whole site.

### Scheduled backups

Schedules are edited in the **Scheduled backups** section of the Backup Center. Each schedule selects a profile and a cron expression.

Examples:

```text
0 * * * *      hourly at minute 0
0 3 * * *      daily at 3:00 AM
0 3 * * 1      weekly on Monday at 3:00 AM
0 3 1 * *      monthly on the 1st at 3:00 AM
```

When schedules are saved, Grav Commander writes managed custom jobs to:

```text
user/config/scheduler.yaml
```

Those jobs call the plugin CLI command:

```bash
bin/plugin grav-commander backup --profile=pages_media --reason=scheduled-daily_backup
```

Important: the host must still run Grav's scheduler from system cron, for example:

```bash
* * * * * cd /path/to/grav && bin/grav scheduler 1>> /dev/null 2>&1
```

Without that server cron job, schedules will be saved but will not fire automatically.

### Manual CLI backup

You can run a profile backup over SSH:

```bash
bin/plugin grav-commander backup --profile=pages_media --reason=manual-cli --note="Before editing content"
```

## Backup storage

Backups are stored by default in:

```text
user/data/grav-commander/backups
```

A `.htaccess` and `index.html` are written into that folder as a basic web-access guard.

Full-site restore is disabled by default:

```yaml
backup:
  allow_site_restore: false
```

File/folder restores are intended for staging and careful production use. Full-site restore is a sledgehammer. Keep it locked until you truly need it.

## Security notes

- PHP and executable-style file editing/uploading is blocked by default.
- Configured roots prevent path traversal outside allowed areas.
- Backup files are stored outside public pages by default.
- Full-site restore is locked behind explicit configuration.
- Scheduled jobs are written only under a managed `grav-commander-backup-` prefix.
- Test on staging before trusting this with production.

## Routes

The plugin registers API routes under:

```text
/api/v1/grav-commander
```

Main endpoints include:

- `GET /status`
- `GET /roots`
- `GET /list`
- `GET /read`
- `PATCH /write`
- `POST /upload`
- `POST /archive/zip`
- `POST /archive/extract`
- `POST /backup/file`
- `POST /backup/site`
- `GET /backups`
- `GET /backup/download?name=backup-name.zip`
- `GET /backup/profiles`
- `POST /backup/profiles`
- `GET /backup/schedules`
- `POST /backup/schedules`
- `POST /backup/schedules/{key}/run`
- `POST /restore`


## Notes for 0.3.8

Grav Commander now separates the file manager and backup tools into two tabs: **Files** and **Backups**. Backup profiles and schedules are still stored in `user/config/plugins/grav-commander.yaml`, but normal editing is handled through form fields. The raw JSON editor is intended as an expert/debugging escape hatch only.

For safer backup storage, point `backup.path` outside the public site root where your host allows it. Examples:

```yaml
backup:
  path: ../gcmdr_backups
```

or an absolute server path:

```yaml
backup:
  path: /home/CPANELUSER/gcmdr_backups
```

The backup health panel will warn when the backup folder is still inside the Grav/site root, and the Backup Storage card can now offer a one-click suggested outside-root path when available. Existing backups remain in their original folder if you change the path; new backups go to the new location.


### Temporary download links

Backup downloads use short-lived one-time tokens. The Admin2 UI asks the authenticated API for a token, then opens a direct download URL so the browser can handle the ZIP as a normal attachment. Tokens expire quickly and are consumed after use.


## Notes for 0.3.8

This release tightens the Admin2 notice area, prevents post-operation notices from sticking around after long backup jobs, and leaves long-running work to the central overlay instead of showing multiple competing "Working…" indicators. Large backup downloads still stream through PHP because the archive folder is intentionally kept outside the public web root; that is safer, but the browser may take a moment to display the save dialog for larger ZIP files.


## Notes for 0.3.10

The Open in Grav Editor action uses Admin2's `/pages/edit/...` route and converts on-disk page folders like `05.typography_quark2` to the route segment `typography_quark2`.


- Backup Profiles and Scheduled Backups now use expandable/collapsible item cards, similar in spirit to Grav list fields.
- Schedule cards include scheduler status cards plus a generated Grav scheduler job preview so it is clearer what will be mirrored into `user/config/scheduler.yaml`.
- Scheduler output logs are auto-derived from the schedule key when the default Grav Commander log pattern is used.
- Markdown files under the Pages root now offer an **Open in Grav Editor** action. Raw editing remains available.
- The longer-term editor path is to use Grav's page editor for real page files and an embedded code editor, likely CodeMirror, for arbitrary text-ish files.
