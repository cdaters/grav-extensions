# Caxton

Caxton is the source-faithful editor foundation for Grav 2 and Admin2. Version
0.1.0 is a contract release: it provides the bounded source model, exact
no-edit serializer, localized safe-block edits, extension registry, and Grav
service on which the visual and source editor adapters will be built.

It does **not** replace the Admin2 editor yet. It contains no ProseMirror,
CodeMirror, Admin2 field, network endpoint, live preview, or Jarvis integration.

## Install

Install this directory as `user/plugins/grav-caxton`, then clear the Grav cache.
The default configuration is safe to run unchanged:

```yaml
enabled: true
limits:
  max_source_bytes: 2097152
```

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
```

When host PHP is unavailable the script uses the repository's configured DDEV
fixture. See `docs/planned/grav-caxton.md` and Decision 0005 for the complete
architecture, security boundaries, roadmap, compatibility policy, and recovery
instructions.
