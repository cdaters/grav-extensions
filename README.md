# Grav Extensions

This repository is the shared home for Craig Daters' independent Grav plugins
and themes. Every extension remains independently installable and packageable;
the repository simply keeps development, compatibility rules, and release tools
in one place.

## Included extensions

| Extension | Type | Status | Install directory |
| --- | --- | --- | --- |
| File Vault | Plugin | Development release | `user/plugins/file-vault` |
| Prism Gallery | Plugin | Development release | `user/plugins/prism-gallery` |
| Grav Commander | Plugin | GPM package; standalone history retained | `user/plugins/grav-commander` |
| Spitfire | Quark 2 child theme | Site theme | `user/themes/spitfire` |

The future suite is deliberately maintained as a roadmap until each extension
has working code and tests. See [docs/roadmap.md](docs/roadmap.md) and
[docs/architecture.md](docs/architecture.md).

## Repository layout

```text
plugins/             independently installable Grav plugins
themes/              independently installable Grav themes
docs/                suite architecture, roadmap, and release notes
scripts/             validation and packaging helpers
dist/                generated ZIP files (ignored by Git)
```

Site content, site configuration, runtime data, protected downloads, caches,
logs, and credentials do not belong in this repository.

## Working with Grav Commander

Grav Commander remains available from its existing standalone repository and
the Grav Package Manager. It is incorporated here with Git subtree history,
rather than copied as an unrelated snapshot.

The `commander` remote points to the standalone repository. To bring its `main`
branch into this repository:

```bash
git subtree pull --prefix=plugins/grav-commander commander main
```

To publish Commander-only changes back to that repository:

```bash
git subtree push --prefix=plugins/grav-commander commander main
```

Review and test the split before pushing a release. Commander keeps its own
version, changelog, tags, and GPM identity.

## Validate and package

Validate repository structure and PHP syntax (when PHP is available):

```bash
./scripts/verify-extensions.sh
```

Create an installable ZIP containing the required top-level extension folder:

```bash
./scripts/package-extension.sh plugin file-vault
./scripts/package-extension.sh plugin prism-gallery
./scripts/package-extension.sh plugin grav-commander
./scripts/package-extension.sh theme spitfire
```

Generated archives are written to `dist/` and are intentionally ignored.

## Licensing

Each extension contains its own license. Do not assume that a license at the
repository root overrides an extension's package license.
