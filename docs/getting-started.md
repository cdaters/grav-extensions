# Getting started

## Choose one extension

The repository is not a single Grav plugin. Install only the package you need:

```text
plugins/file-vault       -> user/plugins/file-vault
plugins/prism-gallery    -> user/plugins/prism-gallery
plugins/grav-commander   -> user/plugins/grav-commander
themes/spitfire          -> user/themes/spitfire
```

Read the selected package's README before enabling it. Dependencies and minimum
Grav/PHP versions are intentionally package-specific.

## Install from source

Copy the package folder—not the whole repository—to the matching directory in
your Grav installation. Keep the folder slug unchanged. Clear Grav's cache
after installation or update:

```bash
bin/grav clearcache
```

Then enable/configure it in Admin, or create a site-specific YAML override under
`user/config/plugins/` (plugins) or `user/config/themes/` (themes).

## Install from a generated ZIP

From the repository root, create a package:

```bash
./scripts/package-extension.sh plugin file-vault
```

The resulting archive under `dist/` contains the required top-level folder and
can be extracted into `user/plugins`. Themes use `theme` and `user/themes`.

## Keep source and runtime data separate

Plugin code can be replaced during an upgrade. Site configuration, catalog
data, activity records, protected downloads, page content, and user accounts
must remain outside the plugin folder. File Vault's manual describes its exact
data boundary and backup requirements.
