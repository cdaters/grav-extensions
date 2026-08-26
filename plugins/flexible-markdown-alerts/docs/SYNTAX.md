# Alert authoring syntax

Flexible Markdown Alerts turns a quoted marker and its quoted body into a
styled callout. The marker must be on its own line.

## Use a configured default title

```markdown
> [!TIP]
> Back up the site before changing a plugin configuration.
```

This uses the `tip` definition's default title, icon, and colors.

## Give one alert a custom title

```markdown
> [!TIP|Sysop Tip]
> Back up the board before changing presentation packages.
```

Only the visible title changes. The alert keeps the `tip` definition's icon,
border color, and title color. The custom title belongs to this one alert and
does not change the configured default.

## Use another configured type

If an administrator adds a type with the keyword `sysop`, authors can use:

```markdown
> [!SYSOP]
> Uses the configured default title.

> [!SYSOP|Operator Note]
> Uses a one-time title with the same SYSOP styling.
```

Type keywords are case-insensitive in Markdown. The configured keyword must
begin with a letter and may contain lowercase letters, numbers, and hyphens.

## Multiple paragraphs and lists

Keep every line inside the blockquote. A quoted blank line separates body
paragraphs:

```markdown
> [!IMPORTANT|Before upgrading]
> Make a verified backup first.
>
> - Save the site files.
> - Export or preserve any external data.
> - Test the restore procedure.
```

Normal inline Markdown, links, emphasis, lists, and fenced code supported by
the site's Markdown configuration can be used in the body.

## Common mistakes

Do not put body text on the marker line:

```markdown
> [!TIP] This does not create an alert.
```

Do not omit `>` from body lines. The first unquoted line ends the alert.

If a marker renders as an ordinary blockquote, confirm that the type exists,
is enabled, and is spelled correctly. Also confirm that another alert plugin
is not enabled at the same time.
