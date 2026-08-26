# Migration from GitHub Markdown Alerts

Flexible Markdown Alerts preserves the five standard markers, their familiar
default titles, bundled icon shapes, and default colors. Existing content that
uses `[!NOTE]`, `[!TIP]`, `[!IMPORTANT]`, `[!WARNING]`, or `[!CAUTION]` can
therefore remain unchanged.

## Safe migration sequence

1. Back up the Grav site.
2. Install `flexible-markdown-alerts` under `user/plugins/`.
3. Disable `github-markdown-alerts`.
4. Enable `flexible-markdown-alerts`.
5. Clear Grav's cache.
6. Check representative pages containing all alert types.

Do not enable both plugins at once. They listen for the same blockquote marker,
so only one should own the syntax.

## Configuration mapping

| Previous setting | Flexible Markdown Alerts setting |
|---|---|
| `include_css` | `include_css` |
| `enable_octicons` | `enable_icons` |
| fixed type colors | editable colors in each `alerts` row |
| fixed translated titles | editable default title in each `alerts` row |
| `wrapper_class` | `wrapper_class` |
| `title_class` | `title_class` |
| `body_class` | `body_class` |

Any theme CSS targeting `.md-alert`, `.md-alert--note`, `.md-alert--tip`, and
the other supplied classes remains compatible with the default class settings.

After migration, a page may opt into a one-time title without creating a new
type:

```markdown
> [!TIP|Sysop Tip]
> This keeps the configured TIP styling.
```
