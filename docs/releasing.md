# Release procedure

Each extension versions and ships independently.

## Prepare

1. Confirm the working tree contains no unrelated site or runtime files.
2. Update `version:` in the package `blueprints.yaml`.
3. Update the package `CHANGELOG.md` and README.
4. Run `./scripts/verify-extensions.sh`.
5. Test install and upgrade behavior in Grav.

## Build

```bash
./scripts/package-extension.sh plugin file-vault
```

Use `plugin` or `theme` and the package slug. The script prints a SHA-256 digest
and writes the ZIP under `dist/`.

Inspect the archive before publication:

```bash
unzip -l dist/file-vault-0.6.0.zip
```

The archive must have one top-level directory matching the extension slug and
must not contain runtime data, protected files, secrets, `.ddev`, macOS metadata,
or repository internals.

## Publish

Tag releases with the package and version to prevent collisions in a monorepo,
for example `file-vault-v0.6.0` or `prism-gallery-v0.2.1`.

Grav Commander is the exception: it retains its standalone repository, ordinary
version tags, and GPM lifecycle. Split/push Commander changes using the subtree
commands in the root README, then release from the standalone repository.

Do not tag or publish planned specifications as working packages.
