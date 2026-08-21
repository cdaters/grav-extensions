# Site Workshop

Site Workshop is a clean-room, modular operations plugin for Grav 2. It ships
small tools that remain useful independently:

- **Icon Bench** — a safe SVG library with an Admin2 browser, original bundled
  icons, custom icon packs, Twig output, and optional Shortcode Core output.
- **Frontmatter Annex** — reusable YAML frontmatter blocks stored outside page
  folders, with explicit and predictable precedence.

The remaining modules are deliberately visible as roadmap items rather than
half-working controls:

- **Cache Hearth** — bounded cache warming with budgets, exclusions, and
  progress.
- **Feed Relay** — purpose-built RSS and JSON feeds for automation services.

## Installation

Copy `site-workshop` to `user/plugins/site-workshop`, clear Grav's cache, and
grant trusted Admin roles `site-workshop.read` and `site-workshop.manage`.
Admin2 and the API plugin provide the visual icon browser. Shortcode Core is
optional and is needed only for `[workshop-icon]` markup.

## Using Frontmatter Annex

Open **Site Workshop → Frontmatter Annex**, create a kebab-case name such as
`shared-seo`, and enter a YAML mapping:

```yaml
metadata:
  robots: index, follow
meta_pilot:
  schema_type: WebPage
cache_enable: true
```

Attach it to a page by adding this to the page's frontmatter:

```yaml
site_workshop:
  annexes:
    - shared-seo
  precedence: page
```

`page` means values written directly in the page win. `annex` means the annex
wins. When several annexes are listed, later annexes override earlier ones;
the chosen page/annex precedence is then applied. Maps merge recursively, while
lists and scalar values replace the earlier value. A missing or invalid annex
is skipped and logged rather than breaking the public page.

The `site_workshop` control block is reserved and cannot be supplied by an
annex. Annexes affect the runtime page header only; they do not rewrite page
Markdown or hide the page's actual frontmatter from its editor.

## Using Icon Bench

Open **Site Workshop** in Admin2 to search every discovered pack, preview the
sanitized output, and copy a ready-to-use shortcode or Twig expression.

Twig:

```twig
{{ workshop_icon('workshop/search') }}
{{ workshop_icon('workshop/shield', {class: 'feature-icon', size: '2rem', title: 'Protected'}) }}
```

Shortcode Core:

```text
[workshop-icon icon="workshop/search" /]
[workshop-icon icon="shield" pack="workshop" class="feature-icon" size="2rem" title="Protected" /]
```

Icons use `currentColor`, so normal CSS `color` rules control their appearance.
The `title` option turns decorative output into a labeled image; output without
a title is hidden from assistive technology.

## Custom icon packs

By default Site Workshop checks these locations:

```text
theme://images/icons/<pack-name>/<icon-name>.svg
user://data/site-workshop/icons/<pack-name>/<icon-name>.svg
```

Pack and icon names must use lowercase letters, numbers, and hyphens. Each
immediate subdirectory is treated as one pack. Add or change pack locations in
**Plugins → Site Workshop**, then select **Refresh packs** in the Icon Bench.

## SVG safety model

SVG is executable markup, not merely an image format. Site Workshop therefore:

- resolves files with real paths and requires them to stay inside a configured
  pack directory;
- rejects oversized files, doctypes, and entities;
- parses with network access disabled;
- retains only a small drawing-element and attribute allowlist;
- removes scripts, foreign objects, events, styles, links, embedded data, and
  `url()` references;
- sanitizes every render rather than trusting a previously indexed result.

The sanitizer is intentionally conservative. Complex SVG artwork may need to
be simplified before it can be used as an interface icon.

## CLI

```bash
bin/plugin site-workshop icons
bin/plugin site-workshop icons search
bin/plugin site-workshop icons --pack=workshop --limit=20
```

## Requirements

- Grav 2.x and PHP 8.3 or newer
- PHP DOM extension (required for safe SVG parsing)
- Admin2 and API plugins for the visual control center
- Shortcode Core only when shortcode output is wanted

Frontmatter Annex uses Grav's bundled Symfony YAML parser and needs no optional
plugin beyond Site Workshop itself.

## Clean-room design

Site Workshop is an original implementation. Public Grav documentation and
publicly visible workflow concepts informed the product direction; proprietary
plugin code, icon artwork, and bundled assets were not copied. The bundled
Workshop Essentials pack was authored for this plugin and is released under
the same MIT license.

## License

MIT
