# Revision Ledger

Revision Ledger is durable content history for Grav 2. It records the page file before an Admin save, gives editors named checkpoints and readable comparisons, and makes rollback a deliberate, guarded action.

## What 0.2.0 includes

- A revision-history button and live count badge directly in the Admin2 page editor toolbar.
- A page-local slide-out history with preview, compare, checkpoint, and guarded restore controls.
- Deep linking between a page editor and that page's full Revision Ledger timeline.
- Automatic pre-save snapshots and an initial snapshot for new pages.
- SHA-256 integrity checks and content deduplication.
- Protected storage outside the public Grav root by default.
- Author, source, reason, route, timestamp, size, and content-hash metadata.
- Admin2 catalog, revision timeline, side-by-side comparison, unified diff, named checkpoints, and guarded restore.
- A pre-restore safety checkpoint before any page is replaced.
- Count- and age-based retention with protection for manual/plugin/safety checkpoints.
- CLI commands for listing, checkpointing, comparing, restoring, and pruning.
- A public Grav service and `onRevisionLedgerCheckpoint` event for other plugins.

## Installation

Copy the `revision-ledger` directory to `user/plugins/revision-ledger`, then clear Grav's cache. The default data directory is `../revision-ledger-data`, resolved from the Grav root, and must be writable by PHP.

Grant trusted roles the appropriate Admin permissions:

- `revision-ledger.read`
- `revision-ledger.manage`
- `revision-ledger.restore`

Restore permission should be limited to site administrators or senior editors.

## Admin workflow

On any Admin2 page editor, use the revision-history button beside the page actions to open that page's history without leaving the editor. Its badge shows the retained revision count. Preview, compare, create a named checkpoint, or restore from the slide-out panel.

Open **Revision Ledger** in the Admin2 sidebar for the full catalog. Select a page to see its timeline. **Compare** shows the retained content next to the current page file. **Restore** requires the exact phrase `RESTORE PAGE` and first records the content being replaced.

Use **Plugin settings** from the dashboard to configure automatic checkpoints, protected storage, and retention.

## CLI

Grav prefixes plugin commands with the plugin slug:

```bash
bin/plugin revision-ledger revisions --route=/about
bin/plugin revision-ledger checkpoint /about --reason="Before campaign refresh"
bin/plugin revision-ledger diff 20260819-120000-a1b2c3d4
bin/plugin revision-ledger restore 20260819-120000-a1b2c3d4 --confirm="RESTORE PAGE"
bin/plugin revision-ledger prune --route=/about
bin/plugin revision-ledger prune --route=/about --execute
```

`prune` is a preview unless `--execute` is supplied.

## Plugin integration

Other Grav plugins can request a checkpoint without depending on Revision Ledger internals:

```php
use RocketTheme\Toolbox\Event\Event;

$event = new Event([
    'route' => '/about',
    'reason' => 'Before an automated metadata rewrite',
    'source' => 'plugin',
]);
$grav->fireEvent('onRevisionLedgerCheckpoint', $event);
$revision = $event['revision'] ?? null;
```

When the plugin is installed, its service is also available as `$grav['revisionLedger']`.

## Security model

- Page content is stored as base64 inside JSON records; this is an encoding, not encryption. Keep the storage directory private.
- Storage is rejected when it resolves inside the public Grav root unless the operator explicitly disables that guard.
- Every revision is verified against its SHA-256 digest before comparison or restore.
- Restore only targets an existing file under `user/pages`; path traversal and arbitrary file recreation are refused.
- Writes use a temporary file and atomic rename.
- Revision files and directories are created with restrictive permissions where supported.

## Recovery limits

Revision Ledger is page history, not a full-site backup system. Use Site Safeguard for whole-site recovery, host moves, plugin/theme rollback, and disaster recovery.

## Requirements

- Grav 2.x
- Admin2 and API plugins for the visual dashboard
- PHP 8.3 or newer

## License

MIT
