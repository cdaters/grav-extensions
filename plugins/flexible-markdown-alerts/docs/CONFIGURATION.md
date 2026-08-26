# Configuration reference

Configure the plugin in **Admin → Plugins → Flexible Markdown Alerts**. A
site-owned YAML override may instead be placed at:

```text
user/config/plugins/flexible-markdown-alerts.yaml
```

Keep site overrides outside the plugin folder so upgrades do not replace them.

## Global settings

| Setting | Default | Purpose |
|---|---:|---|
| `enabled` | `true` | Enables Markdown parsing for this plugin. |
| `include_css` | `true` | Loads the bundled alert layout CSS. |
| `enable_icons` | `true` | Shows configured SVG icons when available. |
| `wrapper_class` | `md-alert md-alert--` | Wrapper classes plus the type suffix. |
| `title_class` | `md-alert-title` | Class on the title paragraph. |
| `body_class` | `md-alert-body` | Class on the alert body. |

The three class settings are advanced theme-integration controls. Their
defaults are recommended unless the theme supplies a complete alternative.

## Alert definition fields

Each item under `alerts` has these fields:

| Field | Purpose |
|---|---|
| `enabled` | Makes the type available without deleting its settings. |
| `type` | Markdown keyword, such as `tip` or `sysop`. |
| `title` | Default visible title when no per-alert title is supplied. |
| `icon` | Bundled/custom icon key, or `none`; never a full filename. |
| `border_color` | Six-digit hexadecimal left-border color. |
| `title_color` | Six-digit hexadecimal title and icon color. |

The plugin supplies editable `note`, `tip`, `important`, `warning`, and
`caution` definitions. They can be disabled, reordered, or restyled. A site
can add any number of additional valid definitions. Type keywords must be
unique; if duplicates are present, the last enabled definition is used.

## Example with an additional type

This full example retains the supplied `tip` type and adds `sysop`:

```yaml
enabled: true
include_css: true
enable_icons: true
wrapper_class: md-alert md-alert--
title_class: md-alert-title
body_class: md-alert-body
alerts:
  -
    enabled: true
    type: tip
    title: Tip
    icon: tip
    border_color: '#347d39'
    title_color: '#347d39'
  -
    enabled: true
    type: sysop
    title: Sysop Notice
    icon: tip
    border_color: '#316dca'
    title_color: '#316dca'
```

The new definition enables both `[!SYSOP]` and
`[!SYSOP|One-time title]` markers.

## Icons

Bundled icon keys are `note`, `tip`, `important`, `warning`, and `caution`.
Use `none` to omit an icon for one definition, or turn off `enable_icons` to
hide all icons.

The value is only a key. For example, `icon: warning` selects
`icon-warning.svg`; do not enter `icon-warning.svg` in the configuration.

Custom and overriding icons belong under:

```text
user/data/flexible-markdown-alerts/icons/icon-KEY.svg
```

The plugin checks that site-owned directory before its bundled icons. Thus a
site-owned `icon-tip.svg` overrides the built-in Tip icon without editing the
plugin and falls back to the bundled icon when removed. Keys accept lowercase
letters, numbers, and hyphens. Missing or invalid files are omitted safely.

An optional Site Workshop Icon Bench reference such as `workshop/search` can
also be placed in `icon`. It is resolved only when that adjacent service is
installed and enabled; failure leaves the alert intact without an icon.

See `docs/ICONS.md` for complete steps, an SVG template, security constraints,
Icon Bench interoperability, and examples for new and existing definitions.

## Colors and theme CSS

Both color fields require a six-digit hexadecimal value such as `#347d39`.
The values are validated before they become the scoped custom properties:

```css
--flex-alert-border-color
--flex-alert-title-color
```

A theme can extend the bundled appearance without disabling it:

```css
.md-alert {
  border-radius: 0.25rem;
  background: color-mix(in srgb, var(--flex-alert-border-color) 8%, transparent);
}
```

If `include_css` is disabled, the theme is responsible for the complete alert
layout. The HTML classes use the configured class fields; each wrapper also
carries the two safe color properties.

## Editor Pro

The compatibility integration uses Editor Pro's existing Markdown-alert
selector and toolbar icon. It inserts standard alert markers. Per-alert titles
and newly added type keywords can be typed directly in Markdown.

## Cache and troubleshooting

Clear Grav's cache after changing plugin configuration or adding an icon.
Only one plugin should register `[!TYPE]` blockquote syntax. If markers are not
recognized, disable other Markdown-alert plugins and confirm that the desired
definition is enabled.
