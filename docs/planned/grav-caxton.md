# Caxton

**Status:** architecture accepted; Page Studio reconciled into Caxton; 0.1.0
source-fidelity contracts, 0.1.1 editor-engine proof, and 0.2.0 first Admin2
field implemented; 0.2.1 structural-authoring hardening next

- **Product name:** Caxton
- **Plugin slug:** `grav-caxton`
- **Namespace:** `Grav\Plugin\GravCaxton`
- **Service:** `$grav['gravCaxton']`
**Decision:** [0005 — source-backed editor architecture](../decisions/0005-grav-caxton-source-fidelity-editor.md)

## Purpose

Caxton is the suite's modern, site-agnostic Grav 2/Admin2 editing environment.
It combines a first-class source editor with semantic visual editing and an
extension model for Grav-aware content. It replaces the planned **Page Studio**
identity; there is no separate Page Studio product or competing editor plan.

Caxton is inspired by the problems addressed by modern block editors and Grav
Editor Pro, but it is an independent implementation. It does not copy premium
source, assets, text, private architecture, or markup conventions. The supplied
Editor Pro ZIP is a read-only behavior specimen only.

The product thesis is:

> **Blocks without block lock-in.**

Caxton may use block semantics in memory, but normal pages remain human-readable
Grav Markdown. Uninstalling Caxton leaves ordinary source. A Caxton-only stored
construct is allowed only when a feature cannot be expressed in normal Grav
source, and then requires an explicit portable fallback and documentation.

## Why Caxton exists

Ordinary Markdown textareas preserve source but provide little structural help.
Generic WYSIWYG editors make authoring approachable but tend to convert through
HTML or a normalized tree, damaging Grav-specific syntax and meaningful source
formatting. Gutenberg-style systems make blocks first-class storage, which is
powerful but would make Grav pages depend on an editor-specific schema.

Caxton keeps source authoritative and applies visual edits as localized source
patches. It treats safe Markdown as editable semantic blocks and everything
ambiguous as an opaque, byte-preserved block. The editor becomes more capable
without claiming ownership of the content format.

## Clean-room reference findings

The repository owner supplied `~/Downloads/editor-pro.zip`. It was inspected
in place and in a temporary read-only directory outside the repository;
SHA-256:
`15617f2adbeb6012204507dcb8eff93d6accf2d59a4015cc7a977dd70ec6f0a8`.
No reference code or asset is stored in `grav-extensions`.

Useful behavioral lessons:

- a Grav editor needs visual, source, media, table, code, HTML, Twig, shortcode,
  keyboard, theme, directionality, and plugin-extension behavior;
- opaque visual blocks are preferable to destructive interpretation;
- image/link syntax and filenames with spaces demand a real grammar and golden
  tests rather than broad regular-expression rewriting;
- nested lists/marks/shortcodes and whitespace-sensitive shortcode content are
  recurring round-trip hazards;
- code fences must shield Markdown-, HTML-, Twig-, and shortcode-looking text;
- opening a dialog, toggling a mode, mounting an editor, or receiving an
  external value must not falsely dirty or rewrite a page;
- Admin2 owns layout, theme, navigation, dialogs, permissions, and Save; and
- explicit content replacement is a different authority from ordinary value
  synchronization, especially if collaboration is introduced later.

Rejected choices include copying the reference package, copying its bundled
libraries/assets, exposing a compatible private global API, reproducing its
regular-expression transformations, or using its implementation as Caxton's
technical baseline.

## Source-of-truth architecture

```text
                 ordinary Grav page content
                            |
                    SourceDocument
          original bytes + hash + ordered source spans
               /            |             \
       safe semantic     opaque/raw      diagnostics
          spans            spans         and bounds
          |                 |                 |
    ProseMirror adapter   inert view      source-only
          \                 |                 /
           bounded source patches + stale hash
                            |
                  current unsaved buffer
                            |
                    normal Grav Save
```

The foundational model contains:

- `SourceDocument`: original source, SHA-256, newline style, byte length,
  ordered blocks, and parse diagnostics;
- `SourceBlock`: stable identifier, type, byte offset/length, exact source,
  preservation class, editability, and optional semantic metadata;
- parser: bounded segmentation/classification with fail-safe opaque fallback;
- serializer: concatenates exact stored spans and verifies coverage;
- patch service: replaces one explicitly editable span against an expected
  document hash, then reparses the result; and
- extension registry: namespaced descriptors supplied by trusted installed
  plugins through `onCaxtonExtensionRegister`.

No-edit serialization must be byte-identical. A safe intentional edit may
canonicalize only the edited block. Opaque blocks cannot be visually mutated.
Source mode can edit them explicitly because the user is editing the source
itself.

## Construct guarantees

| Construct | Initial visual policy | Persistence guarantee |
| --- | --- | --- |
| YAML frontmatter boundary | Source-only/non-editable in standalone documents; Admin2 form remains owner | Byte-preserved |
| Headings | Safe semantic block | Edited block may use canonical ATX form; untouched bytes preserved |
| Paragraphs | Safe only when no ambiguous embedded construct exists | Localized canonical edit; untouched bytes preserved |
| Emphasis/strong/links | Semantic inline marks after grammar proof | Delimiter/style preserved until edited; localized normalization after edit |
| Ordered/unordered/task lists | Opaque in public PHP 0.1.0; structured in private browser 0.1.1 proof after nested/non-1/task fixtures | Exact until an intentional whole-list edit; only edited list normalizes |
| Blockquotes | Opaque in public PHP 0.1.0; structured in private browser 0.1.1 proof | Exact until intentional block edit support is exposed |
| Horizontal rules | Recognized atomic source block | Byte-preserved; later replacement explicit |
| Fenced code | Private 0.1.1 semantic block; code text never reparsed as Markdown | Exact until explicit code edit; original fence/info/newline retained in proof |
| Inline code | Private 0.1.1 safe mark where grammar is unambiguous | Exact until edited; edited paragraph may normalize locally |
| Tables | Recognized opaque table block initially | Byte-preserved; visual table editing waits for symmetric serializer |
| Images/media references | Private 0.1.1 inert semantic label; preview URI resolution remains separate | Original Markdown target/title/spacing preserved until explicit edit |
| Raw HTML | Escaped/inert opaque block | Byte-preserved; never executed by the editor |
| Twig | Escaped/inert opaque block | Byte-preserved; never evaluated merely for display |
| Grav shortcodes | Opaque block/inline span unless an extension supplies a proven adapter | Byte-preserved, including whitespace and nesting |
| Unknown constructs | Opaque preservation block | Byte-preserved |
| Mixed Markdown + HTML/Twig/shortcode | Opaque mixed block unless safely partitioned | Byte-preserved |
| Semantically meaningful whitespace | Preserved as source/trivia spans | Byte-preserved outside an explicit edited span |

Caxton reports its guarantee honestly as `byte-preserved`, `localized-edit`,
`opaque`, or `source-only`. It never labels a whole document lossless merely
because rendered HTML appears similar.

## Editor engine evaluation

### Selected

**ProseMirror core** is the planned visual engine. It offers a constrained
schema, immutable transactions, selection mapping, custom node views, history,
and mature extension primitives while remaining independent of React/Vue and
any hosted service. Caxton will use the lowest practical layer and own its
toolbar, block registry, source mapping, and persistence contract.

**CodeMirror 6** is the planned source engine. It is designed for code/source
editing, renders large documents by viewport, supports Markdown language
packages and extension compartments, and can be pinned LTR for source while
the surrounding authoring UI respects locale direction.

### Evaluated but not selected as the core

| Candidate | Strengths | Why not the initial core |
| --- | --- | --- |
| TipTap | Productive ProseMirror wrapper, strong extension/node-view ecosystem | Extra abstraction/vendor surface; current Markdown layer is evolving and does not solve byte preservation |
| Milkdown | Markdown-first, headless, plugin-driven, ProseMirror + remark | Remark/AST serialization normalizes source; Caxton would still need its own span model beneath it |
| Lexical | Lean, extensible, framework-capable editor state | Markdown/Grav parsing and serialization remain application work; less direct fit for embedded code/source nodes |
| CodeMirror only | Excellent source fidelity/performance | Cannot provide the intended semantic visual composition alone |
| ProseMirror only | Mature structured editing | Not a first-class raw source editor and cannot preserve arbitrary Markdown bytes by itself |

The engines are replaceable adapters. Persisted pages contain no ProseMirror,
CodeMirror, TipTap, Milkdown, or Lexical document JSON.

## Block and extension model

Core semantic types are expected to grow in evidence-backed order:

- paragraph, heading, thematic break;
- quote and list families;
- image/media;
- fenced code and inline code;
- table;
- raw HTML, Twig, shortcode; and
- opaque/raw-preservation.

Other plugins register namespaced descriptors and, in later milestones,
paired parser/serializer and visual adapters. Registration must include:

- stable extension and construct IDs;
- version and capabilities;
- whether the construct is inline/block/atomic/contains content;
- whether source parsing and serialization are symmetric;
- whether the view is editable, preview-only, or opaque;
- bounded client asset identity served through Grav; and
- migration/fallback behavior if the extension disappears.

Caxton core does not import another plugin's private classes. Missing extension
support must preserve source as opaque rather than dropping or flattening it.

## Visual and source modes

Source mode is a first-class editor, not a troubleshooting modal. It provides
Markdown-aware editing, search, keymaps, accessible labels, explicit line
endings, parse diagnostics, and bounded large-document behavior.

Visual mode presents only safe semantic regions structurally. Opaque regions
are keyboard-focusable source cards with their type/limitation and a direct
route to source editing. Switching modes:

- does not mutate content by itself;
- maintains an expected source hash and maps selection where practical;
- preserves undo boundaries deliberately;
- warns when the target selection lies in source-only/opaque content; and
- falls back to source mode if parsing exceeds a safety bound or the document
  cannot be represented safely.

Split view is deferred until single-mode selection/source synchronization is
proven. Drag/reorder is allowed only for complete independently movable spans
and must retain their exact bytes.

## Admin2 integration

Caxton ships a self-contained `admin-next/fields/caxton.js` bundle using
Admin2's documented custom field contract. It receives `field` and `value`,
emits a bubbling `change` event with the current unsaved content, uses
`window.__GRAV_FIELD_TAG`, and follows Admin2's API token/dialog/navigation/
i18n/theme interfaces.

`onApiBlueprintResolved` replaces only page `type: markdown` content fields
when Caxton is enabled and the user has the correct permissions/preference.
Explicit code-editor fields remain untouched. Field replacement must be
idempotent and scoped to page blueprints.

Version 0.2.0 provides a compact grouped toolbar, punctuation-free formatted
Visual mode, exact Source mode, and distinct inert protected cards. The mature
editor may additionally include an insertion palette/slash command,
contextual block controls, safe reorder, media insertion, source/visual toggle,
focus/full-screen mode, responsive layout, and light/dark/RTL support. Normal
Grav Save/Publish remains the only page persistence action. Caxton never owns a
parallel page-save route.

## Media and Grav constructs

Media resolution is a view concern. The source model retains the exact media
reference; a permission-checked Admin2/API resolver may return a display URL
and metadata without rewriting the source. Remote protocols, `data:` URLs,
paths, captions, titles, and filenames with spaces receive dedicated tests.

Twig and shortcodes are displayed inertly. Caxton must not render them through
the live site merely because the editor opened. A future preview service must
be opt-in, separately authorized, sandboxed/sanitized, bounded, and unable to
write content or expose server context.

## Optional Jarvis integration

Jarvis is never a dependency or central layout element. Without Jarvis, Caxton
retains all parsing, visual/source editing, blocks, media, accessibility, and
normal Save behavior.

A future 0.4.x integration may provide Rewrite, Proofread, Shorten, Expand,
Explain, Summarize, and Custom Prompt for the current semantic selection/block.
Caxton will:

- resolve only public Jarvis interfaces and `$grav['gravJarvis']`;
- send the smallest bounded selection plus explicitly disclosed neighboring
  context;
- retain provider/model-independent source selection and permissions;
- show before/after or diff preview with usage/cost/retry/cache information;
- require explicit Accept/Reject and a one-time source/selection-bound receipt;
- apply only to the current unsaved buffer; and
- remain usable through absent/disabled/no-provider/missing-credential/provider-
  failure/budget-rejection states.

Caxton will not duplicate provider credentials, adapters, validation,
discovery, retry, caching, budget, cost, chunking, or background jobs.

## Ecosystem interoperability

- **Revision Ledger:** normal Grav Save events remain the integration boundary;
  Caxton may optionally request a public checkpoint before an explicit future
  non-page operation, but has no hard dependency.
- **Grav Commander:** a later public intent may open one contained Markdown
  file in Caxton or hand it an unsaved source buffer. Commander retains path and
  write authority; Caxton retains editor/source-patch authority.
- **Flexible Markdown Alerts and other syntax plugins:** future Caxton
  extensions can provide safe node/insert adapters. Their existing Editor Pro
  compatibility code is not reused.
- **Jarvis:** optional public-contract selection/block proposals only.

## Security model

- Maximum source bytes, blocks, nesting, diagnostics, patch bytes, and parsing
  time are bounded. Oversized/pathological documents remain editable in a
  degraded source textarea or fail safely without byte loss.
- Source blocks are rendered with text nodes/escaped content. Raw HTML, Twig,
  shortcode output, SVG, and pasted HTML are never trusted editor chrome.
- URL and media previews accept only explicit schemes and Grav-owned routes;
  no arbitrary authenticated fetch/proxy is created.
- Client merges use own-property maps/allowlists and reject prototype keys.
- Extensions are trusted installed code but do not gain page write, Jarvis,
  filesystem, or credential authority through registration.
- API endpoints enforce `grav-caxton.use` plus effective page read/update
  permissions as appropriate. Browser route hints are not authorization.
- Every patch binds the expected source hash and exact source span. Stale,
  overlapping, ambiguous, or out-of-range patches fail closed while the
  unsaved buffer remains visible.
- Caxton stores no hidden copy of page content, prompts, or proposals in 0.x.
  Diagnostics avoid content bodies and secrets.

## Accessibility and interaction expectations

- Complete keyboard access for toolbar, block navigation, source mode, dialogs,
  and mode switching; no drag-only operation.
- Native semantic controls, visible focus, programmatic labels, status/error
  announcements, high-contrast/theme variables, reduced-motion support, and
  correct RTL UI with LTR code/source where appropriate.
- IME composition, screen-reader editing, zoom, narrow layouts, touch targets,
  and selection behavior are browser release gates.
- No native blocking `alert()`, `confirm()`, or `prompt()` in Admin2.

## Performance goals

- No-edit parse/serialize is linear in source bytes with bounded lookahead.
- Initial 0.1.0 hard source bound: 2 MiB; configurable visual-mode thresholds
  arrive only with evidence.
- Source mode uses CodeMirror viewport rendering; visual mode may virtualize
  block views only after selection/accessibility behavior is proven.
- Editor engines and rare node adapters are lazy-loaded. Admin2's per-plugin
  field bundle cache/ETag contract is respected.
- A 100,000-line/source stress fixture and pathological delimiter corpus become
  mandatory before claiming large-document support.

## Compatibility philosophy

- Target Grav 2 and current Admin2/API only during 0.x.
- Use public Grav/API/Admin2 events and services. Do not patch Admin2, themes,
  or page files.
- Preserve ordinary Markdown under different Grav Markdown configurations;
  visual support is capability/configuration aware.
- Public PHP contracts are additive after 0.1.0; engine adapters remain private
  until their lifecycle is proven.
- Pages must remain usable without Caxton. Unknown syntax survives as source.
- Classic Admin/Grav 1.7, proprietary Editor Pro plugin APIs, and exact UI
  compatibility are not promised.

## Testing strategy

Deterministic tests begin before the Admin field:

- source parser/serializer fixtures and byte-identical no-edit golden files;
- LF/CRLF/no-final-newline, Unicode, frontmatter, nested lists, fenced code,
  raw HTML, Twig, shortcodes, media, tables, mixed and unknown syntax;
- one-block edits proving all untouched prefix/suffix bytes remain identical;
- stale hash, invalid block, opaque edit, overlapping/out-of-bounds edit,
  malformed source, NUL, excessive bytes/block count/depth, and deterministic
  diagnostics;
- service/registry/event/absence behavior without Admin2 or Jarvis;
- later visual/source transitions, selection mapping, undo, no false dirty
  state, keyboard/IME/screen-reader/RTL/theme/responsive behavior;
- XSS/pasted HTML/Twig/shortcode/media/prototype-pollution fixtures;
- Admin2 permission denial, unsaved-only behavior, no page mutation request,
  package fresh install/upgrade, public/Admin health, console and log checks;
  and
- optional Jarvis tests that are fully deterministic and still run with Jarvis
  absent.

Under Decision 0003, the first field release must include a signed-in browser
regression that opens a real page, switches modes without mutation, edits one
safe block, confirms opaque bytes survive, reloads before Save to prove no
persistence, exercises permission denial, and inspects console/logs.

## Versioned roadmap

### 0.1.0 — source-fidelity contract foundation (implemented)

- runnable package metadata, README/changelog/license/defaults/permissions;
- public source document/block/edit DTOs and `CaxtonServiceInterface`;
- source-backed bounded parser/serializer and stale-safe one-block replacement;
- extension descriptor registry plus `onCaxtonExtensionRegister`;
- `$grav['gravCaxton']` service registration;
- deterministic difficult Grav Markdown corpus;
- byte-identical no-edit and localized-edit tests;
- explicit guarantee/limitation reporting; and
- no Admin field, editor-engine bundle, Jarvis, media preview, or page-write API.

The shipped contract deliberately recognizes only plain headings and plain
single-line paragraphs as visually editable. Frontmatter, trivia, thematic
breaks, lists, blockquotes, fenced code, media, tables/pipes, HTML, Twig,
shortcodes, ambiguous Markdown, malformed constructs, and unknown syntax retain
their exact source and remain non-visual. The deterministic suite also freezes
LF/CRLF/CR/mixed line-ending, no-final-newline, Unicode, contiguous-coverage,
NUL/size-bound, stale-hash, localized-edit, registry, absence, and failure-log
behavior.

### 0.1.1 — visual/source engine proof (implemented)

- pinned/audited ProseMirror core and CodeMirror 6 dependencies;
- private adapters over the shared source-backed document contract;
- paragraph/heading/thematic-break visual proof plus opaque cards;
- source/visual mode switching without normalization or false dirty state;
- selection mapping and deterministic component tests;
- bundle/license/size inventory; and
- still no automatic page-field replacement or page persistence route.

The release preserves all 0.1.0 public PHP contracts byte-for-byte. Its private
browser layer uses an exact-lockfile ProseMirror/CodeMirror/Lezer stack and
keeps the canonical Markdown string plus SHA-256 authoritative. It proves safe
semantic headings, paragraphs, marks/links, lists/tasks, blockquotes,
horizontal rules, fenced code, and inert media representations; text-only
opaque cards preserve frontmatter, HTML, Twig, shortcodes, tables, unsafe URLs,
mixed/malformed/unknown source, and excessive nesting. Localized edits, exact
no-edit mode switches, conservative source/visual mapping, content-only dirty
state, stale-source denial, read-only/focus behavior, hostile source, bounds,
and diagnostic large-input fixtures pass in Node and actual system Chrome.

The internal ES module is packaged but not registered or loaded by PHP. Its
private lifecycle, dependency/license/bundle inventory, UTF-16 browser versus
PHP-byte offset boundary, reproducibility procedure, evidence, and limitations
are recorded in [the engine proof](../caxton-editor-engine.md). It is not a
public JavaScript extension contract and does not authorize Admin2 integration.

### 0.2.0 — Grav-aware Admin2 field (implemented)

- self-contained `caxton` Admin2 field and scoped page blueprint integration;
- page permission and current unsaved value handling;
- punctuation-free formatted visual text, exact Source mode, and HTML, Twig,
  shortcode, table, malformed, and unknown protected cards;
- first real signed-in black-box browser gate;
- accessible toolbar and source/visual toggle; and
- normal Grav Save/Publish remains authoritative.

The shipped field is requested only for page `markdown` fields after
`grav-caxton.use` authorization; `grav-caxton.source` separately controls the
Source switch. Direct typing and safe inline/heading toolbar operations apply
one localized top-level source patch. Ambiguous top-level restructuring is
rejected with a Source-mode instruction. The signed-in DDEV gate proves exact
no-edit switching, hidden Markdown punctuation, localized editing, opaque-byte
survival, reload non-persistence, ordinary Save, theme, responsive layout, and
clean relevant browser/log state.

### 0.2.1 — structural-authoring hardening (exact next milestone)

- safe paragraph split/create/delete transactions with localized-source proof;
- link, list, and quote toolbar flows without native browser prompts;
- accurate active/disabled toolbar state and selection/focus restoration;
- signed-in permission denial, read-only, and keyboard regressions; and
- no new page-write, autosave, Jarvis, collaboration, job, batch, or MCP path.

### 0.2.x — richer Grav constructs

- proven symmetric list/table/media/shortcode adapters;
- safe insertion registry/palette;
- extension client-module loading through Grav-owned routes;
- configuration-aware Markdown capabilities and diagnostics; and
- optional Revision Ledger visibility without a hard dependency.

### 0.3.x — polished authoring

- keyboard-first block controls, safe drag/reorder alternative, slash insertion;
- focus/full-screen mode and possibly split view;
- responsive, touch, RTL, IME, screen-reader and theme hardening;
- large-document profiling/virtualization; and
- collaboration evaluation only after source-patch and ownership semantics are
  proven.

### 0.4.x — optional Jarvis intelligence

- selection/block-aware actions through public Jarvis contracts;
- bounded context, provider/model status, preview/diff, explicit Accept/Reject,
  stale selection/source protection, and usage/cost reporting;
- deterministic Jarvis-present/failure/absence browser coverage; and
- no provider code, credential path, autosave, publish, jobs, batch, or MCP in
  Caxton.

### 1.0.0 — supported editor platform

- compatibility/deprecation policy for public extensions;
- completed migration/fallback/accessibility/security/operator guidance;
- release-quality browser/support matrix and large-document evidence; and
- no content dependency on Caxton's installation for ordinary features.

## Explicit non-goals

- cloning Editor Pro or Gutenberg feature-for-feature;
- storing the authoritative page as HTML, ProseMirror JSON, or Caxton block
  JSON;
- silently converting every unknown construct into paragraphs;
- executing Twig/shortcodes or remote embeds as part of editor display;
- replacing Grav Save/Publish, page permissions, optimistic concurrency, or
  revisions;
- live site rendering inside editable DOM;
- making Jarvis, Commander, Revision Ledger, or any suite plugin required;
- live collaboration, site-wide batch editing, autonomous AI, RAG, MCP, or
  background jobs in the foundation;
- Grav 1.7/classic Admin support during 0.x; or
- claiming complete losslessness until the exact guarantee has a fixture.

## Recovery and resume

Future sessions must read, in order:

1. `AGENTS.md` and `CURRENT-STATE.md`;
2. this specification and Decision 0005;
3. `docs/roadmap.md`, `docs/architecture.md`, `docs/security-model.md`, and
   `docs/testing.md`;
4. the latest `docs/SESSION-LOG.md` entry;
5. `plugins/grav-caxton/README.md` once the runnable package exists; and
6. Caxton contract fixtures/tests before changing parser or serializer code.

Never depend on the Editor Pro ZIP for a build or test. Its recorded hash and
behavioral findings are historical research evidence only. Do not copy it into
the repository. Before changing the document model, add a failing golden
fixture that proves the desired preservation behavior and state whether the
result is byte-preserving, localized normalization, opaque, or source-only.
