# Icon guide

## The short version

The **Icon key** field contains a name such as `tip` or `terminal`. It does not
contain an SVG filename or SVG markup.

The plugin converts the key into `icon-KEY.svg` and searches in this order:

1. `user/data/flexible-markdown-alerts/icons/icon-KEY.svg`
2. `user/plugins/flexible-markdown-alerts/assets/icons/icon-KEY.svg`

The first path is site-owned and safe from plugin upgrades. The second contains
the plugin's bundled fallback icons.

When Site Workshop's optional Icon Bench is installed and enabled, a
`pack/icon` reference can be rendered through that service after the local
lookups. Neither plugin requires the other.

## Use a bundled icon

Ready-to-use keys are:

| Icon key | Intended default |
|---|---|
| `note` | Note |
| `tip` | Tip |
| `important` | Important |
| `warning` | Warning |
| `caution` | Caution |
| `none` | No icon |

Any alert type can use any bundled icon. To give the Note alert the Warning
icon, open its row in Admin and change only **Icon key** from `note` to
`warning`. Its keyword, default title, and colors remain unchanged.

## Add a custom icon for a new alert

Suppose the new alert keyword is `sysop` and its icon should look like a
terminal.

1. Create `user/data/flexible-markdown-alerts/icons/` if needed.
2. Save the reviewed SVG as:

   ```text
   user/data/flexible-markdown-alerts/icons/icon-terminal.svg
   ```

3. In **Plugins → Flexible Markdown Alerts → Alert types**, add a row with:

   | Field | Value |
   |---|---|
   | Enabled | Yes |
   | Type keyword | `sysop` |
   | Default title | `Sysop Notice` |
   | Icon key | `terminal` |
   | Border color | your six-digit hex color |
   | Title and icon color | your six-digit hex color |

4. Save, clear Grav's cache, and author the alert:

   ```markdown
   > [!SYSOP]
   > This uses the configured title and terminal icon.

   > [!SYSOP|Operator Note]
   > This changes only the title for this one alert.
   ```

## Override a bundled icon

To replace every use of the built-in Tip icon, save a reviewed replacement at:

```text
user/data/flexible-markdown-alerts/icons/icon-tip.svg
```

Keep `tip` in the Icon key field. The site-owned file wins automatically.
Delete it and clear cache to return to the bundled Tip icon.

## SVG template

This small terminal-style example inherits the configured title/icon color
through `currentColor`:

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

Recommended characteristics:

- an `<svg>` root with a `viewBox`;
- `width="16"` and `height="16"` for consistent default sizing;
- `fill="currentColor"` or `stroke="currentColor"` so the configured color
  applies;
- `aria-hidden="true"`, because the visible title already names the alert;
- simple local vector elements such as `path`, `rect`, `circle`, and `line`.

## Security rules

SVG is markup embedded directly into the rendered page. Only an administrator
or trusted developer should add or replace these files. Review custom SVGs and
remove scripts, event-handler attributes, external URLs, remote styles or
fonts, embedded bitmap data, animation, and `foreignObject` content.

The plugin intentionally does not accept arbitrary SVG markup in YAML or from
ordinary page authors. The Admin field chooses a reviewed file by key.

## Optional Site Workshop Icon Bench

Icon Bench already discovers named SVG packs and sanitizes their output. If it
is installed next to Flexible Markdown Alerts:

1. Open **Site Workshop → Icon Bench**.
2. Find an icon and read its displayed reference, for example
   `workshop/search`.
3. Paste `workshop/search` into the Flexible Markdown Alerts **Icon key** field.
4. Save and clear Grav's cache.

Flexible Markdown Alerts asks the existing Icon Bench service to render that
reference. It does not read Icon Bench's private files, duplicate its catalog,
or require a Site Workshop class at installation time.

If Site Workshop is not installed, Icon Bench is disabled, the pack changes,
or the reference is missing, the alert still renders its title and body without
an icon. Bundled keys and the site-owned icon directory are unaffected. Site
Workshop likewise contains no dependency on Flexible Markdown Alerts.

## Troubleshooting

If an icon does not appear:

1. Confirm the filename is exactly `icon-KEY.svg`.
2. Enter only `KEY` in Admin—without `icon-` or `.svg`.
3. Use lowercase letters, numbers, or hyphens in the key.
4. Confirm the definition and global **Enable alert icons** setting are enabled.
5. Clear Grav's cache.
6. Check that the SVG has a valid `<svg>` root and visible vector elements.
