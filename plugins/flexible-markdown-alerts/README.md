# Flexible Markdown Alerts

Flexible Markdown Alerts is a reusable Grav 2 plugin for styled Markdown
callouts. Alert types are configuration data rather than hard-coded behavior:
sites can edit the supplied definitions, disable or reorder them, and add new
types with their own default title, icon, border color, and title color.

The plugin also supports an optional title on an individual alert without
changing that alert type's configured styling:

```markdown
> [!TIP|Sysop Tip]
> Back up the board before changing presentation packages.
```

## Requirements

- Grav 2.0 or newer
- PHP 8.3 or newer

## Installation

Install the complete folder at:

```text
user/plugins/flexible-markdown-alerts
```

Enable it in Admin or copy `flexible-markdown-alerts.yaml` to
`user/config/plugins/flexible-markdown-alerts.yaml`, then clear Grav's cache.

Only one plugin should register `[!TYPE]` blockquote syntax. Disable another
Markdown-alert plugin before enabling Flexible Markdown Alerts.

## Supplied alert types

Five enabled definitions are included and can be edited in Admin:

| Keyword | Default title | Icon key | Default color |
|---|---|---|---|
| `note` | Note | note | blue |
| `tip` | Tip | tip | green |
| `important` | Important | important | purple |
| `warning` | Warning | warning | amber |
| `caution` | Caution | caution | red |

![The five supplied alert styles](/user/plugins/flexible-markdown-alerts/assets/screenshot.png)

Standard syntax remains compatible:

```markdown
> [!NOTE]
> Useful information readers should notice.

> [!TIP]
> Optional advice or a helpful technique.

> [!IMPORTANT]
> Key information needed for success.

> [!WARNING]
> Urgent information that helps avoid a problem.

> [!CAUTION]
> A serious risk or potentially destructive outcome.
```

The type marker must be on its own quoted line. Every body line must also begin
with `>`.

## Per-alert custom titles

Add a pipe and title inside the marker:

```markdown
> [!TIP|Sysop Tip]
> Helpful content.
```

`Sysop Tip` is displayed while the alert retains the configured `tip` icon,
border color, and title color. Without a pipe, the configured default title is
used.

Custom titles work with every configured type:

```markdown
> [!NOTE|Historical Context]
> Additional background.

> [!IMPORTANT|Development Preview]
> This feature is currently available from source.
```

## Adding an alert type

Open **Plugins → Flexible Markdown Alerts**, expand **Alert types**, and choose
**Add Alert Type**. Configure:

1. **Type keyword** — lowercase letters, numbers, and hyphens, beginning with a
   letter; for example `sysop`.
2. **Default title** — for example `Sysop Notice`.
3. **Icon key** — a bundled key, `none`, or a custom icon key.
4. **Border color** and **Title and icon color**.
5. **Enabled** — disabled definitions remain configured but do not parse.

The new type is immediately available after saving and clearing cache:

```markdown
> [!SYSOP]
> Uses the configured default title.

> [!SYSOP|Operator Note]
> Uses a one-time title with the same SYSOP styling.
```

Type keywords are case-insensitive in Markdown and must be unique. If duplicate
definitions exist, the last enabled definition wins.

## Icons: what the Icon key means

The **Icon key** field is a short name, not an SVG upload field and not a full
filename. The plugin turns a key such as `tip` into the filename
`icon-tip.svg`.

The plugin includes these ready-to-use keys:

- `note`
- `tip`
- `important`
- `warning`
- `caution`
- `none` (show no icon for this alert type)

If the optional **Site Workshop → Icon Bench** is installed and enabled, an
Icon Bench reference such as `workshop/search` can also be used directly. See
**Optional Icon Bench integration** below.

### Change an existing alert's icon

1. Open **Plugins → Flexible Markdown Alerts**.
2. Expand **Alert types**, then expand the alert row you want to change.
3. In **Icon key**, enter another bundled key. For example, set the `note`
   alert to `important` to use the Important icon with Note's title and colors.
4. Enter `none` to remove only that type's icon.
5. Save and clear Grav's cache.

Changing an icon key does not change the alert keyword, title, or colors.

### Add a custom SVG icon

Custom icons belong to the site, not inside the plugin. This prevents a plugin
upgrade from overwriting them.

1. Create this directory if it does not exist:

   ```text
   user/data/flexible-markdown-alerts/icons/
   ```

2. Add a trusted SVG whose filename follows this exact pattern:

   ```text
   icon-KEY.svg
   ```

   For a key named `terminal`, the complete path is:

   ```text
   user/data/flexible-markdown-alerts/icons/icon-terminal.svg
   ```

3. In the alert definition's **Icon key** field, enter only:

   ```text
   terminal
   ```

4. Save the alert definition and clear Grav's cache.

Here is a complete, simple `icon-terminal.svg` example:

```svg
<svg xmlns="http://www.w3.org/2000/svg"
     viewBox="0 0 24 24"
     width="16"
     height="16"
     fill="none"
     stroke="currentColor"
     stroke-width="2"
     stroke-linecap="round"
     stroke-linejoin="round"
     aria-hidden="true">
  <rect x="3" y="4" width="18" height="16" rx="2" />
  <path d="m7 9 3 3-3 3" />
  <path d="M13 15h4" />
</svg>
```

Icon keys accept lowercase letters, numbers, and hyphens. Do not include
`icon-` or `.svg` in the Admin field.

### Override a built-in icon safely

To replace the Tip icon everywhere without editing the plugin, add:

```text
user/data/flexible-markdown-alerts/icons/icon-tip.svg
```

and leave the alert's Icon key as `tip`. The plugin checks the site-owned icon
directory first, then falls back to its bundled icon. Removing the site-owned
file restores the bundled icon after the cache is cleared.

SVG files are embedded into the page, so use only reviewed local SVGs. Avoid
scripts, event-handler attributes, external URLs, embedded bitmap data,
`foreignObject`, and remote fonts or styles. Missing or invalid icon keys fail
safely by rendering the alert title without an icon.

### Optional Site Workshop → Icon Bench integration

Flexible Markdown Alerts does not require Site Workshop. When both plugins are
installed and Icon Bench is enabled, they interoperate through Icon Bench's
existing safe renderer:

1. Open **Site Workshop → Icon Bench**.
2. Search or filter the available icon packs.
3. Note the displayed reference, such as `workshop/search` or
   `my-pack/radio`.
4. Paste that complete reference into the alert's **Icon key** field.
5. Save and clear Grav's cache.

No SVG file needs to be copied into Flexible Markdown Alerts. Icon Bench owns
pack discovery and sanitizes the SVG every time it renders. A one-word icon
key that is not found among Flexible Markdown Alerts' site-owned or bundled
icons may also resolve from Icon Bench's configured default pack.

The integration is deliberately optional in both directions:

- without Site Workshop, Flexible Markdown Alerts continues to use its bundled
  and site-owned icons;
- without Flexible Markdown Alerts, Site Workshop and Icon Bench operate
  normally;
- if an Icon Bench reference becomes unavailable, the alert still renders its
  title and body without an icon.

The global **Enable alert icons** switch hides all icons without changing the
per-type definitions.

## Colors and CSS

Every definition owns a six-digit hexadecimal border color and title/icon
color. The plugin emits safe CSS variables on each rendered alert, so colors
continue to work if the advanced class settings are changed. Invalid runtime
color values fall back safely.

The bundled markup classes default to:

```text
md-alert md-alert--TYPE
md-alert-title
md-alert-body
```

Advanced configuration can change those classes. Disable bundled CSS only when
a theme supplies the complete alert presentation.

## Rich body content

Bodies support normal Markdown. Use a quoted blank line between paragraphs and
keep list or code lines inside the blockquote:

```markdown
> [!TIP|Checklist]
> First paragraph.
>
> - First item
> - Second item
```

Alerts cannot be nested. Use them sparingly and avoid consecutive alerts when
ordinary headings or prose would be clearer.

![An alert body containing lists and code](/user/plugins/flexible-markdown-alerts/assets/screenshot2.png)

## Configuration ownership

Bundled defaults live in `flexible-markdown-alerts.yaml`. Site-specific values
belong in:

```text
user/config/plugins/flexible-markdown-alerts.yaml
```

Do not edit the bundled default merely to configure one installation. This
keeps plugin upgrades distinct from site policy.

## Editor Pro

The included Editor Pro integration retains the ordinary alert toolbar action.
It inserts standard markers. Add `|Custom title` manually when an individual
alert needs a different label. Newly configured type keywords can also be
entered directly in Markdown.

## Compatibility and attribution

The five supplied markers remain compatible with GitHub-style Markdown alerts.
Dynamic alert definitions and the optional `|Custom title` extension are
specific to Flexible Markdown Alerts.

Flexible Markdown Alerts is maintained by **Craig Daters**. It was derived
from Trilby Media's MIT-licensed GitHub Markdown Alerts plugin and retains its
original attribution and compatible rendering model.
The bundled default SVGs are derived from GitHub's MIT-licensed Octicons. See
[`NOTICE.md`](NOTICE.md) and [`LICENSES/`](LICENSES/) for attribution.

## Additional packaged documentation

Grav Admin displays this README but does not resolve links to other local
Markdown files reliably. The plugin folder also contains these guides:

- `docs/SYNTAX.md` — authoring examples and formatting rules
- `docs/ICONS.md` — built-in icons, custom SVGs, and safe overrides
- `docs/CONFIGURATION.md` — every setting, custom types, colors, and theme
  integration
- `docs/MIGRATION.md` — switching from GitHub Markdown Alerts without breaking
  existing content
