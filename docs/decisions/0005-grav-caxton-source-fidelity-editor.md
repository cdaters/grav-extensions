---
title: Build Caxton around a source-backed document model
status: accepted
date: 2026-08-30
extensions:
  - grav-caxton
  - grav-jarvis
  - grav-commander
  - revision-ledger
---

# Build Caxton around a source-backed document model

## Context

The suite already named **Page Studio** as a future rich editor. That brief was
intentionally small because a serious editor is an application platform, not a
toolbar replacement. The product is now named **Caxton**, with plugin slug
`grav-caxton`; Page Studio is not a second product.

Grav pages are source documents, not generic rich-text HTML. Their content may
combine Markdown, configuration-sensitive Grav Markdown extensions, media
references, raw HTML, Twig, shortcodes, fenced code, tables, unusual whitespace,
and syntax registered by other plugins. A conventional rich-text round trip
parses this source into a normalized tree and serializes the entire tree again.
Even when the rendered meaning is similar, that process can change delimiters,
spacing, list markers, table layout, line endings, or unknown syntax merely by
opening and saving a page.

The supplied Editor Pro 2.0.10 package was inspected in place at
`~/Downloads/editor-pro.zip` as a clean-room behavioral specimen (SHA-256
`15617f2adbeb6012204507dcb8eff93d6accf2d59a4015cc7a977dd70ec6f0a8`).
No source or asset from that package enters this repository. Its public feature
set and changelog reinforce the problem: mature editors repeatedly encounter
edge cases involving nested formatting/lists, shortcode whitespace and nesting,
HTML/Twig inside code fences, image paths with spaces, source-mode keyboard and
directionality, false dirty state, host layout/theme ownership, and explicit
content replacement.

Current authoritative references reviewed for this decision include:

- <https://learn.getgrav.org/20/api/developer-guide>
- <https://learn.getgrav.org/20/api/endpoints/admin-integration>
- <https://learn.getgrav.org/20/content/markdown>
- <https://github.com/getgrav/grav-plugin-admin2>
- <https://prosemirror.net/docs/guide/>
- <https://codemirror.net/docs/guide/>
- <https://lexical.dev/>
- <https://milkdown.dev/docs/guide/why-milkdown>
- <https://tiptap.dev/docs/editor/core-concepts/extensions>
- <https://tiptap.dev/docs/editor/markdown/guides/integrate-markdown-in-your-extension>

## Decision

Create **Caxton**, a site-agnostic Grav 2/Admin2 editor, as an independent
implementation with the product principle **blocks without block lock-in**.

### Source is authoritative

Caxton's source of truth is a bounded, source-backed concrete document model,
not HTML, ProseMirror JSON, a proprietary block database, or rendered Twig.
The model keeps the original source and ordered source spans. Each span has a
semantic type when Caxton can recognize it safely and otherwise becomes an
opaque preservation block.

The guarantees are deliberately tiered:

1. **Byte-preserving:** loading and serializing without an intentional edit
   returns the exact bytes, including line endings and untouched whitespace.
2. **Localized canonical edit:** editing one safely representable block may
   normalize only that block according to Caxton's documented serializer; all
   other spans remain byte-identical.
3. **Opaque preservation:** unknown, ambiguous, raw, or unsafe constructs remain
   exact source spans and cannot be structurally edited in visual mode.
4. **Explicit source edit:** source mode may change any bytes the user chooses.
   Caxton reparses the resulting buffer and reports which regions are visual-
   editable, opaque, malformed, or source-only.

Caxton must never claim that semantic equivalence is byte losslessness. A mode
switch alone is not an edit and cannot normalize the source.

### Editor engines are adapters

The planned visual adapter uses **ProseMirror core**. Its schema, immutable
transactions, node views, selection mapping, history, and collaboration-ready
step model fit semantic block editing without requiring a framework-specific
application shell. The planned source adapter uses **CodeMirror 6** for
Markdown-aware source editing, large-document viewport rendering, keyboard
behavior, and extension compartments.

Neither engine owns persisted content. Caxton translates only safe semantic
spans into the visual adapter and applies resulting changes back as bounded
source patches. CodeMirror edits the authoritative source buffer directly.
Both sit behind Caxton interfaces so a later engine change does not migrate
page content.

The alternatives were rejected for the initial architecture as follows:

- **TipTap:** capable and extensible, but it adds another API/vendor layer over
  ProseMirror, and its current Markdown integration is still evolving. Some
  conversion/export facilities are commercial. It does not remove Caxton's
  need for a Grav-aware source-preservation model.
- **Milkdown:** strong Markdown-first and plugin-oriented design on ProseMirror
  and remark, but its Markdown AST/parser/serializer pipeline still normalizes
  syntax and trivia. Caxton would carry both Milkdown's transformer abstraction
  and its own source-span model.
- **Lexical:** lean and capable, but Markdown serialization and custom Grav
  constructs are application/plugin concerns. It provides less direct leverage
  for this Markdown-first, embedded-code-editor use case.
- **CodeMirror only:** excellent source editing but not the desired semantic
  visual composition experience.
- **ProseMirror only:** excellent structured editing but not a first-class raw
  source editor and insufficient by itself for byte-preserving Markdown.

This choice is independent even though another Grav editor also uses
ProseMirror. Caxton selects the lower-level engine because its transaction and
node-view model fits the requirement; it will not copy that product's code,
assets, implementation text, private architecture, or global JavaScript API.

### Grav and Admin2 boundaries

Caxton will ship an Admin2 custom field under `admin-next/fields/` and use the
documented Web Component `field`/`value`/bubbling `change` contract. The field
must be self-contained in its shipped bundle, use Admin2's injected tag and API
globals, inherit host theme/direction tokens, and never rewrite host layout.

The field owns only the unsaved content value. Normal Grav Save/Publish remains
authoritative. Caxton introduces no page-write endpoint, parallel page store,
or hidden autosave. Frontmatter remains owned by the page form; if a standalone
source document contains a YAML frontmatter envelope, Caxton preserves it as a
non-visual source segment unless a later explicit frontmatter contract is
approved.

### Extension model

The PHP service is registered as `$grav['gravCaxton']` and public contracts use
`Grav\Plugin\GravCaxton\Contracts`. Trusted installed plugins may register
descriptors through `onCaxtonExtensionRegister`. A descriptor declares a
stable namespaced ID, capabilities, construct kinds, and eventual client
adapter identity. It never receives implicit page-write authority.

Client extensions will be loaded only through Grav/Admin2-owned plugin asset
routes and an allowlisted registry assembled server-side. They may add parsers,
serializers, visual node views, commands, palettes, and inspections for their
own syntax. Unknown or unavailable extensions degrade to opaque source blocks;
page content remains usable after Caxton or an extension is removed.

### Optional Jarvis boundary

Caxton is a complete editor without Jarvis. A later milestone may resolve
`$grav['gravJarvis']` only after public interface checks and use public Jarvis
contracts for bounded selection/block proposals. Caxton retains selection,
source-version, permission, preview, and unsaved-buffer authority. Jarvis owns
providers, credentials, validation/model discovery, retry, cache, budgets,
cost, and chunking. No model output can save, publish, execute, register a
block, or widen the selected source range.

### Security and trust

- Source, HTML, Twig, shortcodes, Markdown extension output, media metadata,
  pasted content, extension descriptors, and Jarvis output are untrusted.
- Visual editing uses inert editor node views, never the site's rendered Twig
  or shortcode pipeline. Twig/shortcode preview is disabled until a separately
  permissioned, sanitized, bounded preview contract exists.
- Raw HTML is displayed as escaped/inert source or a sanitized structural
  representation; it is never inserted into editor chrome with unsanitized
  `innerHTML`.
- Parsing and serialization are size/time/recursion/count bounded. Pathological
  input fails to source mode without losing bytes.
- Server APIs require both Admin2/API and Caxton permissions. CSRF/authentication
  follow the Grav API token boundary.
- Third-party Caxton extensions are trusted installed code, but remain scoped
  to their declared registry capabilities and receive no implicit data or write
  access.

## Consequences

- Caxton can offer semantic blocks while stored pages remain ordinary Grav
  Markdown wherever possible.
- The source-span/patch layer is more work than an ordinary AST serializer, but
  it makes preservation testable and prevents unrelated normalization.
- Some constructs will intentionally remain opaque or source-only until a
  symmetric parser/serializer exists. Visual coverage grows by evidence, not by
  optimistic parsing.
- ProseMirror and CodeMirror add a JavaScript build and dependency-maintenance
  burden beginning with the visual/source adapter milestone. Their licenses,
  versions, bundle size, accessibility, and security advisories become release
  inputs.
- Grav 2/Admin2 is the 0.x compatibility target. Classic Admin and Grav 1.7 are
  not promised.
- Collaboration, split view, live site preview, Jarvis, and rich extension UIs
  remain later milestones and cannot weaken the source/persistence boundary.
