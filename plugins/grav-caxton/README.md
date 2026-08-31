# Caxton

Caxton is the source-faithful visual and source editor for Grav 2 and Admin2.
Version 0.2.0 preserves the complete 0.1.0 public PHP contract, keeps ordinary
Markdown authoritative, and adds the first real Admin2 page field.

Visual mode presents formatted headings, emphasis, links, lists, quotes, and
code without showing their Markdown punctuation. Source mode deliberately
shows the exact Markdown. HTML, Twig, shortcodes, tables, malformed source, and
unknown constructs remain visible as inert protected cards until Caxton can
prove a symmetric editor for them.

## Install

Install this directory as `user/plugins/grav-caxton`, then clear the Grav cache.
The default configuration is safe to run unchanged:

```yaml
enabled: true
limits:
  max_source_bytes: 2097152
admin:
  replace_markdown_fields: true
```

The replacement applies only to page fields declared as `type: markdown`, only
for users with `grav-caxton.use`, and never to explicit code-editor fields.
`grav-caxton.source` separately controls whether the Source mode is available.

## Admin2 editing

Caxton uses a compact grouped toolbar above a generous document canvas. The
0.2.0 toolbar intentionally exposes only the proven safe slice: undo/redo,
paragraph/heading levels, bold, italic, inline code, and Visual/Source modes.
Typing and formatting in Visual mode applies one localized Markdown patch;
adding or removing top-level document structure remains a Source-mode task in
this release.

The field owns only the current unsaved form value. It emits Admin2's normal
bubbling `change` event and interoperates with the public editor content events,
but it has no page-write endpoint, autosave, hidden copy, or parallel storage.
Only the ordinary Admin2 Save/Publish action persists a change. Reloading before
Save restores the stored page.

## Public service

When enabled, the plugin registers a lazy service as `$grav['gravCaxton']`.
Consumers must treat Caxton as optional and check both service presence and the
public interface:

```php
use Grav\Plugin\GravCaxton\Contracts\CaxtonServiceInterface;

$caxton = isset($grav['gravCaxton']) ? $grav['gravCaxton'] : null;
if ($caxton instanceof CaxtonServiceInterface) {
    $document = $caxton->parse($source);
}
```

Never import Caxton implementation classes from another plugin. A disabled,
missing, or failed Caxton service must leave the consumer's ordinary behavior
available.

## Source guarantees

- Parsing and serializing without an edit returns the exact input bytes.
- A 0.1.0 visual-contract edit can replace only a recognized plain heading or
  single-line plain paragraph and requires the expected source SHA-256.
- The edited block is canonicalized; every byte outside its source span remains
  unchanged.
- Frontmatter, code fences, lists, blockquotes, media, tables, HTML, Twig,
  shortcodes, ambiguous Markdown, malformed constructs, and unknown syntax are
  preserved but are not visually editable in this release.
- Source is capped at 2 MiB by default and NUL input is rejected. Source text is
  never included in exception or registration-log messages.

## Extension registration

Trusted installed plugins may listen for `onCaxtonExtensionRegister`, read the
`registry` event value, and register an implementation of
`CaxtonExtensionInterface`. IDs must be namespaced, such as
`vendor/shortcode-alert`. Duplicate or malformed IDs fail deterministically.

The 0.1.0 registry describes server-side capability only. Client modules,
parsers, serializers, and node views are later contracts; registering an
extension grants no page-read, page-write, rendering, network, or executable
content authority.

## Development

Run the deterministic contract suite with:

```bash
./scripts/test-grav-caxton-contract.sh
./scripts/test-grav-caxton-editor.sh
./scripts/test-grav-caxton-admin-browser.sh
```

When host PHP is unavailable the contract script uses the repository's
configured DDEV fixture. The editor tests require Node.js and a supported
system Chrome/Chromium; the signed-in browser test also uses the disposable
DDEV fixture. See `docs/caxton-editor-engine.md` for the exact safe subset,
dependency/license/size inventory, offset contract, evidence, and limitations.
See `docs/planned/grav-caxton.md` and Decision 0005 for the complete architecture,
roadmap, compatibility policy, and recovery instructions.
