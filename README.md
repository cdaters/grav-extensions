# Grav Extensions

This repository is the shared home for Craig Daters' independent Grav plugins
and themes. Every extension remains independently installable and packageable;
the repository simply keeps development, compatibility rules, and release tools
in one place.

## Repository and Obsidian vault

The repository root is also a portable Obsidian vault. Obsidian users can open
this folder as a vault and begin at
[Grav Extensions Workshop](%F0%9F%A7%B0%20Grav%20Extensions%20Workshop.md).
No community plugin is required to read or maintain the documentation.

GitHub and filesystem readers can begin with the
[documentation index](docs/index.md).

## Included extensions

| Extension | Type | Status | Install directory |
| --- | --- | --- | --- |
| File Vault | Plugin | Development release | `user/plugins/file-vault` |
| Prism Gallery | Plugin | Development release | `user/plugins/prism-gallery` |
| Site Safeguard | Plugin | 0.3.1 development release | `user/plugins/site-safeguard` |
| Image Foundry | Plugin | 0.2.1 development release | `user/plugins/image-foundry` |
| Meta Pilot | Plugin | 0.2.0 development release | `user/plugins/meta-pilot` |
| Revision Ledger | Plugin | 0.2.1 development release | `user/plugins/revision-ledger` |
| Lantern Search | Plugin | 0.1.0 development release | `user/plugins/lantern-search` |
| Site Workshop | Plugin | 0.1.0 development release; Icon Bench available | `user/plugins/site-workshop` |
| Grav Commander | Plugin | GPM package; standalone history retained | `user/plugins/grav-commander` |
| Spitfire | Quark 2 child theme | Site theme | `user/themes/spitfire` |

The future suite is deliberately maintained as a roadmap until each extension
has working code and tests. See [docs/roadmap.md](docs/roadmap.md) and
[docs/architecture.md](docs/architecture.md).

Every installable package also carries its own end-user README so the guidance
travels with release ZIPs:

- [File Vault manual](plugins/file-vault/README.md)
- [Prism Gallery manual](plugins/prism-gallery/README.md)
- [Site Safeguard manual](plugins/site-safeguard/README.md)
- [Image Foundry manual](plugins/image-foundry/README.md)
- [Meta Pilot manual](plugins/meta-pilot/README.md)
- [Revision Ledger manual](plugins/revision-ledger/README.md)
- [Lantern Search manual](plugins/lantern-search/README.md)
- [Site Workshop manual](plugins/site-workshop/README.md)
- [Grav Commander manual](plugins/grav-commander/README.md)
- [Spitfire child-theme manual](themes/spitfire/README.md)

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
./scripts/package-extension.sh plugin site-safeguard
./scripts/package-extension.sh plugin image-foundry
./scripts/package-extension.sh plugin meta-pilot
./scripts/package-extension.sh plugin revision-ledger
./scripts/package-extension.sh plugin lantern-search
./scripts/package-extension.sh plugin site-workshop
./scripts/package-extension.sh plugin grav-commander
./scripts/package-extension.sh theme spitfire
```

Generated archives are written to `dist/` and are intentionally ignored.

The complete checklist is in [docs/releasing.md](docs/releasing.md).

## Licensing

Each extension contains its own license. Do not assume that a license at the
repository root overrides an extension's package license.
