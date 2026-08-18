# Development workflow

Treat this repository as the source of truth and a Grav/DDEV site as the
integration environment. Do not maintain unrelated hand-edited copies in both
places.

## Iterate safely

1. Start from a clean repository branch.
2. Copy or symlink one extension into a disposable Grav site.
3. Keep the site's config and runtime data outside the extension folder.
4. Make source changes here, then refresh the integration copy.
5. Clear Grav cache and run the package-specific smoke tests.
6. Run repository verification and build/install the release ZIP before tagging.

A symlink is convenient for a local DDEV project when the host and container
both resolve it correctly. A copied integration folder is more representative
of production and should always be used for final upgrade/package testing.

## Avoid source drift

Before copying an integration experiment back, compare it:

```bash
diff -ru \
  --exclude='.DS_Store' \
  plugins/file-vault \
  /path/to/grav/user/plugins/file-vault
```

Bring back only intentional source changes. Never copy `user/data`, protected
storage, site configuration, accounts, logs, caches, or generated assets into
the repository.

Grav Commander is synchronized through Git subtree; follow the commands in the
root README rather than copying its directory from a site.
