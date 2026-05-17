# Grav Commander Roadmap

This roadmap is directional, not a promise list. Priorities may shift as Grav 2, Admin2, and real site testing evolve.

## Near Term

- Keep the Admin2 UI stable across current Grav 2 builds.
- Improve copy and empty states around backup, restore, and archive operations.
- Continue tightening small workflow issues found through staging-site use.
- Keep backup storage and restore warnings clear for non-developer admins.

## Editor Direction

- Send Grav page Markdown files to Grav's native page editor by default.
- Keep raw editing available for trusted power users.
- Explore a focused embedded code editor for arbitrary text-like files.
- Avoid turning the file manager into a general IDE.

## Backup Direction

- Improve backup profile clarity and validation.
- Make restore workflows more explicit and harder to trigger accidentally.
- Explore optional remote/cloud backup targets after local backup behavior is boring and reliable.
- Add better reporting around large backup failures caused by hosting limits.
- Explore a true tokenized browser-download route for large backups that avoids loading full archives through the Admin2 JavaScript Blob path.

## Code Quality Direction

- Split the large file service into smaller services for filesystem, archive, backup, scheduler, and token responsibilities.
- Add focused tests around path resolution, extension blocking, ZIP extraction safety, backup profile normalization, and restore behavior.
- Keep API compatibility shims documented and remove them when Grav 2/Admin2 route behavior settles.

## Public / GPM Release Direction

- Keep `README.md`, `CHANGELOG.md`, `blueprints.yaml`, and `LICENSE` aligned with Grav release expectations.
- Confirm metadata, permissions, screenshots, and install instructions before a public GPM submission.
- Tag releases consistently with semantic version numbers.
- Test fresh install, update, cache clear, Admin2 page load, backup creation, download, and restore on a clean Grav 2 site before public release.
