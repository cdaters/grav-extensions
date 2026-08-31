# Caxton

Caxton is the source-faithful visual and source editor for Grav 2 and Admin2.
Version 0.3.0 preserves the complete 0.1.0 public PHP contract, keeps ordinary
Markdown authoritative, completes the planned 0.2.2 authoring work, and adds
optional selection-aware Jarvis proposals through public Jarvis contracts.

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
  toolbar:
    - undo
    - redo
    - separator
    - heading
    - separator
    - bold
    - italic
    - strikethrough
    - inline_code
    - remove_format
    - separator
    - link
    - blockquote
    - bullet_list
    - ordered_list
    - horizontal_rule
    - code_block
    - media
    - separator
    - jarvis
    - source
jarvis:
  enabled: true
```

The replacement applies only to page fields declared as `type: markdown`, only
for users with `grav-caxton.use`, and never to explicit code-editor fields.
`grav-caxton.source` separately controls whether the Source mode is available.

## Admin2 editing

Caxton uses a compact toolbar above a generous document canvas. Version 0.3.0
ships undo/redo, paragraph and six heading levels, bold, italic, GFM
strikethrough, inline code, clear formatting, links, blockquotes, bullet and
numbered lists, fenced code blocks, and Visual/Source modes. The ordered
`admin.toolbar` list controls which proven tools and separators appear. Unknown
or unsupported values are ignored rather than widening editor authority.

Visual links use a keyboard-accessible in-page dialog, accept optional titles,
and reject unsafe URL schemes. Paragraph Enter/split and Backspace/join,
wrapping one safe block as a quote/list, list-kind conversion, and formatting
all become localized Markdown source patches. Active toolbar states follow the
selection, read-only fields disable mutation tools, and `Ctrl`/`Command`+`K`
opens the link dialog. Visual mode never needs a native browser prompt.

The release also adds page-media browsing, insertion and image editing through
Admin2's public current-page media bridge; reference-link editing with optional
definition creation; horizontal-rule insertion; code-fence language selection;
and improved multi-item list behavior. Caxton does not upload media or save a
page through these tools—the normal Admin2 media and Save workflows remain
authoritative.

Visual mode owns scoped document rhythm instead of inheriting Admin2 reset
styles. Paragraphs have a 1rem trailing margin; headings have 1.65em above and
.55em below; lists, blockquotes, code blocks, horizontal rules, media, and
protected cards have explicit surrounding space; list items retain compact
quarter-rem rhythm. The first and last document blocks do not gain stray outer
space. The same policy and readable contrast are verified in light and dark
themes, and presentation changes emit no content change or dirty state.

The editor has explicit light and dark palettes for the toolbar, visual canvas,
protected cards, link dialog, and Markdown source editor. It does not depend on
the browser's generic `Canvas` color, so switching Admin2 themes preserves real
contrast.

The field owns only the current unsaved form value. It emits Admin2's normal
bubbling `change` event and interoperates with the public editor content events,
but it has no page-write endpoint, autosave, hidden copy, or parallel storage.
Only the ordinary Admin2 Save/Publish action persists a change. Reloading before
Save restores the stored page.

## Optional Jarvis integration

Caxton is complete without Jarvis. When an enabled Jarvis installation and a
usable text provider are available, authorized editors receive one compact
Jarvis toolbar control with Rewrite, Proofread, Shorten, Expand, Explain,
Summarize, and Custom Prompt actions. Provider and model choices come from
Jarvis; configure providers and environment-only credentials in Jarvis rather
than duplicating them in Caxton.

Actions use the selected text first, otherwise the current safe semantic block,
plus bounded surrounding context. Visual and Source mode share the same source
mapping. Twig, shortcodes, raw HTML, unknown syntax, reference definitions, and
other protected spans cannot be replacement targets. Code blocks allow
read-only Explain/Summarize and explicit Custom Prompt only.

Every result is shown as Original and Proposed text. Reject leaves the buffer
unchanged. Accept consumes a one-time, 15-minute, user/page/source/range-bound
receipt and changes only the current unsaved buffer; it never saves or
publishes. An accepted proposal is one-step undoable/redoable while the buffer
still matches that proposal. Provider/model, usage, and estimated cost are
shown when Jarvis reports them. Missing, disabled, misconfigured, credential-
less, rate-limited, budget-blocked, or failed Jarvis paths hide or fail only the
optional control and never break ordinary Caxton editing.

Backend requests require `grav-caxton.use`, the relevant page read/update
authority, `grav-jarvis.use`, and `grav-jarvis.approve` for Accept. Provider
output is rendered as text and size-bounded; raw HTML, Twig, shortcode syntax,
reference definitions, and unsafe link/media schemes are rejected before a
receipt is issued. It cannot bypass normal Admin2 Save/Publish authority.

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
- The public 0.1.0 PHP edit contract can replace only a recognized plain
  heading or single-line plain paragraph and requires the expected source
  SHA-256. The private 0.3.0 browser adapter additionally localizes the proven
  toolbar and contiguous safe-paragraph structure transactions above.
- The edited block is canonicalized; every byte outside its source span remains
  unchanged.
- Frontmatter, media, tables, HTML, Twig, shortcodes, ambiguous Markdown,
  malformed constructs, and unknown syntax remain inert and preserved.
- Underline and automatic typography replacement are intentionally absent:
  underline requires HTML rather than portable Markdown, and invisible text
  substitution needs a separate explicit source policy. Existing underline
  HTML remains an exact protected card.
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

The signed-in browser runner temporarily registers Jarvis's deterministic
offline fixture provider. It makes no external provider request and proves
preview, Reject, Accept, Source-mode selection, one-step undo/redo, and no
automatic page save.

When host PHP is unavailable the contract script uses the repository's
configured DDEV fixture. The editor tests require Node.js and a supported
system Chrome/Chromium; the signed-in browser test also uses the disposable
DDEV fixture. See `docs/caxton-editor-engine.md` for the exact safe subset,
dependency/license/size inventory, offset contract, evidence, and limitations.
See `docs/planned/grav-caxton.md` and Decision 0005 for the complete architecture,
roadmap, compatibility policy, and recovery instructions.
