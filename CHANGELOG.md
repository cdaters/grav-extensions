# Changelog

## 0.3.10
- Fixed the Admin2 page editor launch URL for Grav page Markdown files. It now targets `/pages/edit/...` and strips numeric folder prefixes from page-route segments.
- Hardened Open/Edit Raw button handling so it behaves like double-clicking a file instead of risking route navigation weirdness.

## 0.3.10

- Reworks Backup Profiles into expandable/collapsible item cards with Add, Expand all, Collapse all, Delete, and Save controls.
- Reworks Scheduled Backups into expandable/collapsible item cards with summary rows, generated Grav scheduler job previews, scheduler status cards, and cleaner inline editing.
- Adds an Open in Grav Editor action for Markdown files that live under the Pages root, while keeping raw text editing available for power users.
- Auto-generates default scheduler output log paths from the schedule key to avoid stale daily/weekly log names.
- Documents the next editor path: Grav page files should open in Grav's page editor; arbitrary text-ish files will move toward an embedded CodeMirror-style editor.

## 0.3.8

- Tightens notice spacing beneath the Admin2-style tab bar.
- Fixes success/error notices that could stick around after long-running backup operations.
- Reduces duplicate "Working…" indicators so long operations use the central overlay instead of also showing small header notices.
- Clarifies backup download status messaging for larger archives.


- Adds temporary token-based backup download links so backup ZIP downloads can start as normal browser downloads instead of being pulled into a JavaScript blob first.
- Success/error notices now auto-expire and have better spacing below the Admin2-style tab bar.
- Bumps internal backup metadata and status version reporting to 0.3.8.

## 0.3.6

- Adds a query-string backup download endpoint (`GET /backup/download?name=...`) to avoid filename/route edge cases.
- Changes download responses to authenticated direct chunk streaming to avoid 500 errors and memory spikes with larger ZIPs.
- Adds a one-click “Use suggested path” action for moving backup storage outside the site root.
- Makes the Backup Storage card visually green/warning/error based on writable and inside-root status.
- Updates the default backup storage path to `../gcmdr_backups` for safer new installs where the host allows it.
- Keeps Admin2-style Files / Backups tabs and theme-aware confirm modals from the previous test build.
- Keeps plugin metadata author as Craig Daters, with no PixelWizard author reference.

## 0.3.3

- Moves file browsing and backup controls into separate Grav Commander tabs.
- Fixes backup profile/schedule save routes by adding route aliases used by the Admin2 component.
- Adds a larger visible busy/progress panel for long-running backup operations.
- Adds backup storage health details, including a warning when backups are inside the Grav/site root.
- Allows configured backup paths to live outside the Grav root, including absolute paths or relative paths such as `../grav-commander-backups`.

## 0.3.2

- Added ZIP creation and extraction actions.
- Added archive safety controls and extraction guardrails.
