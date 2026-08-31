# Caxton editor-engine and 0.3.0 Admin2 field

This is the dependency, architecture, guarantee, and limitation record for the
Caxton ProseMirror/CodeMirror adapters and the 0.3.0 Admin2 field. The adapters
remain private and are not a stable JavaScript extension API.

## Boundary and source authority

The 0.1.0 public PHP contracts remain frozen byte-for-byte. Version 0.1.1 added
private browser adapters and the reproducibly built internal proof module at
`plugins/grav-caxton/admin-next/proof/caxton-editor.js`. Version 0.2.0 wrapped
the same private source authority in the self-contained
`plugins/grav-caxton/admin-next/fields/caxton.js` Admin2 Web Component.
Version 0.2.1 adds only private field/adapter behavior and configuration; every
public 0.1.0 PHP contract remains byte-identical.

The ordinary Markdown string remains authoritative. The browser session keeps
the baseline/current source and SHA-256 identity; ProseMirror and CodeMirror
are replaceable views over that string. Merely constructing, mounting,
focusing, selecting, or switching modes does not mutate source or mark it
dirty. An intentional visual edit serializes only its safe top-level span and
then reparses the complete current buffer. Opaque source is never serialized
from editor state.

Browser offsets are JavaScript UTF-16 source units because that is the native
selection contract of ProseMirror, CodeMirror, and the DOM. The frozen PHP
contract uses byte offsets. Version 0.2.0 adds an explicit coordinate map that
converts only complete Unicode boundaries against one SHA-256-identified source
string; split surrogate pairs and split UTF-8 code points return no mapping.

Version 0.2.1 can additionally replace one contiguous range containing only
safe paragraphs/headings and their trivia for split/join keyboard transactions.
It reparses the complete current buffer afterward. Any range containing opaque
source fails closed; opaque source is never serialized from editor state.

## Proven safe and opaque constructs

The private visual schema proves headings (ATX and Setext), paragraphs,
strong/emphasis/GFM-strikethrough/inline-code/link marks, blockquotes,
horizontal rules, ordered,
unordered and task lists, fenced code, and inert local/HTTPS media references.
Localized tests exercise text, mark, link, quote, list, paragraph-structure,
and fenced-code edits.
An intentional edit may normalize only that selected safe block.

Frontmatter, raw HTML, Twig, Grav shortcodes, tables, unsafe URLs, mixed source,
malformed input, unknown extension syntax, excessive nesting, and parser-
ambiguous blocks render as non-editable opaque cards made only from text DOM.
They are focusable notes and retain exact source spans. Code fences shield
HTML-, Twig-, shortcode-, and Markdown-looking body text. Media is an inert
semantic label: opening a page performs no media request. Prototype keys,
control characters, unsafe schemes, NUL input, over-2-MiB input, over-20,000
source spans, and over-128 nesting fail closed or degrade to opaque source.

Selection mapping is exact only where a one-to-one source-unit mapping is
provable. It returns no mapping for opaque content, unsafe boundaries,
Markdown escapes, or character references. That conservative failure is a
contract, not permission to guess.

## Dependency decision and inventory

All direct versions are exact in `package.json` and the full dependency graph
is frozen by `package-lock.json`. `npm audit` reported zero known
vulnerabilities on 2026-08-31. All bundled runtime packages are MIT except
`entities` (BSD-2-Clause); complete packaged notices are in
`plugins/grav-caxton/THIRD-PARTY-NOTICES.md`.

Approximate unpacked sizes below are registry metadata observed during the
0.1.1 audit, not promises about future versions. Every runtime row ships in the
proof bundle. “Adapter-replaceable” means the capability is required for this
proof but the package is not a persistence or public-contract dependency.

| Exact direct package | Purpose | License | Approx. unpacked | Browser | Posture |
| --- | --- | --- | ---: | --- | --- |
| `prosemirror-model` 1.25.11 | Schema/document model | MIT | 518 KiB | Yes | Essential; adapter-replaceable |
| `prosemirror-state` 1.4.4 | Editor transactions/selection | MIT | 180 KiB | Yes | Essential; adapter-replaceable |
| `prosemirror-view` 1.42.3 | Safe structured DOM editor | MIT | 882 KiB | Yes | Essential; adapter-replaceable |
| `prosemirror-transform` 1.12.0 | Transaction transforms | MIT | 318 KiB | Yes | Essential ProseMirror foundation |
| `prosemirror-commands` 1.7.2 | Base editing commands | MIT | 130 KiB | Yes | Essential proof behavior |
| `prosemirror-history` 1.5.0 | Visual undo/redo | MIT | 67 KiB | Yes | Essential proof behavior |
| `prosemirror-keymap` 1.2.3 | Keyboard command binding | MIT | 26 KiB | Yes | Essential proof behavior |
| `prosemirror-schema-list` 1.5.1 | List transactions | MIT | 53 KiB | Yes | Essential for proven lists |
| `prosemirror-markdown` 1.13.6 | Safe one-block parse/serialize bridge | MIT | 160 KiB | Yes | Replaceable; never whole-document authority |
| `markdown-it` 14.3.1 | GFM-strikethrough safe-block bridge | MIT | 769 KiB | Yes | Direct pinned parser; bounded one-block use |
| `@codemirror/state` 6.7.1 | Exact source state/changes/selections | MIT | 426 KiB | Yes | Essential; adapter-replaceable |
| `@codemirror/view` 6.43.9 | Viewport source editor/focus | MIT | 1.20 MiB | Yes | Essential; adapter-replaceable |
| `@codemirror/commands` 6.11.0 | Source history/keymaps | MIT | 241 KiB | Yes | Essential proof behavior |
| `@codemirror/lang-markdown` 6.5.2 | Markdown source language support | MIT | 71 KiB | Yes | Replaceable language layer |
| `@codemirror/language` 6.12.4 | Language infrastructure | MIT | 303 KiB | Yes | Required by Markdown layer |
| `@lezer/markdown` 1.7.2 | GFM source spans/tree | MIT | 444 KiB | Yes | Essential proof grammar; replaceable adapter |
| `esbuild` 0.28.2 | Reproducible minified ES module | MIT | 144 KiB plus platform binary | No | Build-only; replaceable |
| `playwright-core` 1.62.1 | Drive installed Chrome/Chromium | Apache-2.0 | 12.8 MiB | No | Test-only; replaceable |

The bundle also contains the pinned transitive CodeMirror/Lezer language
packages, ProseMirror helpers, `markdown-it` 14.3.1, and their small parsing/DOM
utilities enumerated in the packaged notices. No framework, sanitizer, hosted
service, collaboration layer, Jarvis provider/credential code, or reference-
plugin asset is present. The 0.3.0 field calls only Caxton's authenticated
proposal routes; provider networking remains inside Jarvis.

No diff, source-map, DOM-sanitizer, UI-framework, or AST-utility dependency was
added. Localized source patches make a diff package unnecessary; source maps
are intentionally disabled; opaque rendering uses DOM text nodes rather than
sanitizing executable markup; and Lezer plus the bounded one-block Markdown
bridge provide the required syntax understanding.

The final minified 0.3.0 field bundle is 921,750 bytes (312,395 bytes with
gzip -9), and the retained proof bundle is 879,152 bytes. Their SHA-256 values
are recorded in `CURRENT-STATE.md`; their dependency and source graph is
reproducibly built from the exact lock. Admin2 requests the field
bundle only when it resolves a `caxton` field; further code splitting remains a
later performance decision.

## Accessibility and focus evidence

Both engines retain their native keyboard primitives. The proof installs
ProseMirror base/history/list keymaps and CodeMirror default/history keymaps,
uses semantic multiline textbox roles/labels, exposes source read-only state,
and gives every opaque note `role="note"`, an accessible construct label, and a
zero tab index. Actual Chrome proves focus can enter both editor surfaces and
an opaque note, and that read-only reconfiguration does not require replacing
the DOM with an inaccessible custom control.

Version 0.2.1 adds a labelled configurable toolbar, labelled Visual/Source
switch, active/disabled state, an in-page link dialog, live source-fidelity/
dirty/protected-block status, responsive wrapping, explicit light/dark palettes,
and native editor focus/keymaps. Production-level
screen-reader announcements, selection feedback, skip/focus-return behavior,
high contrast/reduced motion, IME, RTL, zoom, touch, and a browser/assistive-
technology matrix remain 0.3.x work. Opaque notes do not yet have a control for
moving focus to their exact Source-mode range.

## Deterministic evidence

`./scripts/test-grav-caxton-editor.sh` performs a clean locked install, runs
the Node component/security/performance suite, rebuilds the package asset,
drives the real module through system Chrome with Playwright Core, and confirms
that two builds have the same SHA-256. The fixtures cover LF/CRLF/no-final-
newline, Unicode, whitespace, nested marks/lists, non-1 ordered lists, task
lists, fences, media, raw HTML/script/on-handler payloads, Twig, shortcodes,
tables, malformed syntax, unsafe URLs, prototype keys, and adjacent opaque
boundaries.

One representative Node 26.7.0 run on the development host observed:

| Fixture | Size / spans | Parse observation |
| --- | ---: | ---: |
| Ordinary page | 42 units / 4 spans | 6.9 ms |
| Many safe blocks | 82,780 units / 10,000 spans | 128.0 ms |
| Many opaque blocks | 36,890 units / 4,000 spans | 21.4 ms |
| Large fenced code | 300,012 units / 2 spans; 20,000 lines | 13.8 ms |
| Combined long document | 419,682 units / 14,002 spans | 138.9 ms |

These timings are diagnostic rather than a support promise and naturally vary
by host/run. The suite enforces only a generous ten-second pathological ceiling.
The signed-in 0.3.0 gate proves authenticated field loading, hidden Markdown
punctuation in Visual mode, exact no-edit switches, one localized visual edit,
opaque Twig survival, reload-before-Save non-persistence, ordinary Save, safe
links, strikethrough, split/undo/join keyboard behavior, quotes/lists, settings,
real dark visual/source contrast, narrow layout, and clean Caxton browser/log
state. IME,
screen-reader, RTL, and the broader accessibility matrix remain later work.

## 0.3.0 authoring, rhythm, and Jarvis boundary

Version 0.3.0 preserves the same authoritative source string and frozen public
PHP contracts while adding page-media/reference editing, horizontal rules,
code-language selection, multi-item list handling, and explicit visual rhythm.
All document spacing is scoped below `.cx-visual .ProseMirror`: paragraphs use
1rem trailing space; headings 1.65em above/.55em below; lists, quotes, pre/code,
rules, media, and opaque cards own their surrounding rhythm; list items stay
compact; and first/last blocks shed only outer margins. Explicit light/dark
variables survive Admin2 reset styles without touching source, selection, dirty
state, or change events. Signed-in Chrome verifies the computed mixed-block
gaps in both themes.

The optional Jarvis control imports only public Jarvis contracts. Visual and
Source selections map through the frozen source identity to UTF-8 byte offsets;
proposals bind a bounded safe target/context to user, route, source, range, and
hash-only one-time receipt. Original/Proposed output is text-only. Reject does
not change content; Accept returns a patch only for the unsaved buffer and is
one-step undoable while the buffer matches. Opaque constructs are excluded,
code replacement is denied except for an explicit Custom Prompt, and normal
Admin2 Save/Publish remains the only persistence path. A deterministic offline
provider proves the actual signed-in route/UI lifecycle without network spend.

## Explicit 0.3.0 limitations

- The toolbar covers only proven symmetric source operations. Tables, live
  preview, extension client loading, media upload, and rich Grav construct
  dialogs remain later.
- Paragraph split and join can localize only contiguous safe paragraph/heading
  ranges. Empty visual paragraphs, operations crossing an opaque block, and
  ambiguous multi-block restructuring are rejected or remain a Source-mode
  task rather than risking a whole-document rewrite.
- Underline is not offered because portable Markdown has no underline syntax;
  automatic typography replacement is deferred because it silently changes
  source. Existing HTML underline remains opaque and exact.
- Safe browser grammar is deliberately conservative. Tables, HTML, Twig,
  shortcodes, unknown syntax, escapes/entities for selection mapping, and any
  construct without symmetric evidence remain opaque or fail mapping.
- Performance observations do not claim 100,000-line support or visual
  virtualization. Accessibility evidence is limited to semantic labels,
  textbox roles, focusable opaque notes, read-only behavior, and actual browser
  focus in the isolated proof.
- There is no Caxton page-write route, autosave, hidden page copy, provider
  client, credential path, Commander/Revision Ledger coupling, collaboration,
  background job, batch, MCP, or autonomous-write integration. Optional Jarvis
  HTTP routes can only propose/review an unsaved-buffer patch through Jarvis's
  public service.

## Admin2 0.3.0 boundary

The field implements Admin2's injected custom-element tag plus `field`, `value`,
and bubbling `change` contract. `onApiBlueprintResolved` replaces only page
`markdown` fields, only when enabled, and only for a user authorized for
`grav-caxton.use`; explicit `editor` fields remain unchanged and Source mode
requires `grav-caxton.source`. Replacement is idempotent.

The field owns only the current unsaved string. Mode switches never emit a
change. Intentional source/visual edits emit the canonical string, and Admin2's
ordinary Save/Publish remains the sole persistence path. The plugin adds no
HTTP route. The configurable toolbar, generous visual canvas, punctuation-free
formatted text, mode switch, explicit theme palettes, in-page link dialog, and
distinct protected cards are independent clean-room UI decisions informed by
the public Editor Pro documentation and user-provided screenshots only. No
reference source, asset, label set, markup, private API, or persisted editor
document entered Caxton. Version 0.3.0's scoped rhythm, media bridge, and
optional proposal dialogs extend that field contract without changing page
persistence authority.
