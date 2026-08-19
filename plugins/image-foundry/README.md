# Image Foundry

Image Foundry builds responsive WebP and AVIF variants without altering an
original image. Generated files live outside the public Grav root by default,
are recorded in a checksummed catalog, and are delivered through opaque
immutable URLs.

No image is sent to an external service. Image Foundry uses the PHP GD library
installed on the same server as Grav.

## Requirements

- Grav 2
- Admin 2 and API plugin for the dashboard
- PHP 8.3 with GD
- GD WebP and/or AVIF support for the corresponding output format

## Admin and CLI

Use **Image Foundry** in Admin to scan configured source roots, generate stale
derivatives, inspect the generated footprint, and purge generated data.
The dashboard's **Plugin settings** button opens source-root, format, quality,
and automatic-HTML configuration directly.
Equivalent CLI commands:

```sh
bin/plugin image-foundry scan
bin/plugin image-foundry build --all
bin/plugin image-foundry build --source="user/pages/01.home/photo.jpg"
bin/plugin image-foundry purge --confirm="PURGE GENERATED DERIVATIVES"
```

Purging removes only Image Foundry's catalog and generated derivatives.
Originals are never deleted or overwritten.

## First run

1. Install the `image-foundry` folder at `user/plugins/image-foundry`.
2. Confirm that `../image-foundry-data` is writable by PHP. It deliberately
   resolves outside the public Grav root.
3. Open **Image Foundry** in Admin and choose **Scan sources**.
4. Review the catalog and server capabilities, then choose **Build stale**.
5. Enable **Automatically serve optimized images** in the plugin settings, or
   opt individual templates into responsive output as shown below.

The generated store is rebuildable data, so it does not need to travel in a
normal site-content backup. After moving a site to another host, install the
plugin and run a scan/build there.

## Twig integration

Build derivatives first, then opt a template into responsive output:

```twig
{{ foundry_picture(
  'user/pages/01.home/photo.jpg',
  page.media['photo.jpg'].url,
  'Descriptive alternative text',
  '(min-width: 60rem) 50vw, 100vw',
  'feature-photo'
)|raw }}
```

The first argument is the source path relative to the Grav root. The second is
the ordinary public URL used as the fallback. If the installed plugin has no
matching current catalog entry, the helper emits a normal `<img>` using that
fallback. A theme that calls this function should declare Image Foundry as an
optional or required integration; an installation where the plugin is entirely
absent will not have the Twig function.

Image Foundry never rewrites Markdown output and does not require a specific
theme or gallery plugin.

## Automatic front-end replacement

Automatic replacement is disabled by default. When enabled, Image Foundry
processes only complete public HTML responses and wraps eligible local `<img>`
elements in responsive `<picture>` markup. The original `<img>` and all of its
attributes remain intact as the fallback. An image is replaced only when its
source URL maps to a current, built catalog entry beneath an allowed source
root. A small `display: contents` compatibility rule keeps the original image's
flex/grid/layout behavior intact after the semantic wrapper is added.

Image Foundry leaves the following unchanged:

- Admin and API output;
- images already inside `<picture>`;
- remote, data, blob, stale, uncataloged, or generated-cache images;
- CSS background images; and
- any image with `data-foundry-ignore` or the `image-foundry-ignore` class.

Use `data-foundry-sizes="(min-width: 60rem) 50vw, 100vw"` on an `<img>` to
override the automatic `sizes` policy for that image. Automatic replacement is
request-time markup selection only: it never builds derivatives during a page
request. Scan and build after adding or changing originals.

## Configuration

The shipped defaults scan `user/pages` and `user/themes`, accept JPEG, PNG,
WebP, and AVIF sources, and generate widths of 480, 960, 1440, 1920, plus the
native width. A source is never enlarged. Quality, formats, roots, size limits,
and widths can be changed through the plugin configuration.

Animated GIF is excluded on purpose: processing one with GD would silently
flatten it to a still image. Image Foundry also declines sources above the
configured byte or decoded-pixel ceiling rather than attempting an unsafe
allocation.

Changing an original or any derivative policy marks only the affected source
stale. A build replaces generated files for that source; unrelated Grav caches
and media are not erased.

## Security model

- The generated-data directory must resolve outside `GRAV_ROOT`.
- Public URLs contain a content/policy-derived opaque ID, not a filesystem path.
- Catalog lookups and containment checks run again when an asset is delivered.
- Successful responses are content typed, `nosniff`, same-site, `noindex`, and
  immutable because changing content produces a different opaque ID.
- Scan/build/purge API routes require `image-foundry.manage` (or super-admin).
- Purge requires a confirmation phrase on the CLI and never targets originals.

Opaque delivery discourages durable hotlinks and hides storage layout. It does
not make a browser-visible image impossible to save.

## Troubleshooting

- **No WebP or AVIF badge:** the server's GD build lacks the relevant encoder.
- **Storage error:** configure an absolute or Grav-relative directory outside
  the public root and make it writable by the PHP user.
- **An image is absent:** confirm its extension/root and check byte/pixel limits.
- **A template still shows the original:** scan and build the source. For the
  Twig helper, check that its first argument exactly matches the catalog path.
  For automatic mode, confirm the rendered `src` is a direct same-site URL
  beneath a configured source root rather than a Grav-generated crop/cache URL.
