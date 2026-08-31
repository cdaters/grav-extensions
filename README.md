# Grav Extensions

This repository is the shared home for Craig Daters' independent Grav plugins
and themes. Every extension remains independently installable and packageable;
the repository simply keeps development, compatibility rules, and release tools
in one place.

## Repository and Obsidian vault

The repository root is also a portable Obsidian vault. Obsidian users can open
this folder as a vault and begin at
[Grav Extensions Workshop](%F0%9F%A7%B0%20Grav%20Extensions%20Workshop.md).
No community plugin is required to read or maintain the documentation.

GitHub and filesystem readers can begin with the
[documentation index](docs/index.md).

## Resuming and recovering development

The Git repository and its tracked documentation are the durable project
record. Begin with [AGENTS.md](AGENTS.md) and
[CURRENT-STATE.md](CURRENT-STATE.md), then follow the resume procedure recorded
there. Durable rationale lives in [architecture decisions](docs/decisions/README.md),
and meaningful chronology is appended to
[the session log](docs/SESSION-LOG.md). Do not rely on conversation history as
the only record of a bug, test result, decision, or next action.

At a meaningful checkpoint, update the current state, append the session log,
run applicable quality and black-box gates, commit a coherent scope, and push
the tracked checkpoint to `origin`. Runtime packages, protected site data,
credentials, and DDEV volumes remain outside Git and need separate backups.

## Included extensions

| Extension | Type | Status | Install directory |
| --- | --- | --- | --- |
| File Vault | Plugin | Development release | `user/plugins/file-vault` |
| Prism Gallery | Plugin | Development release | `user/plugins/prism-gallery` |
| Site Safeguard | Plugin | 0.3.11 development release | `user/plugins/site-safeguard` |
| Image Foundry | Plugin | 0.2.1 development release | `user/plugins/image-foundry` |
| Meta Pilot | Plugin | 0.2.0 development release | `user/plugins/meta-pilot` |
| Revision Ledger | Plugin | 0.2.1 development release | `user/plugins/revision-ledger` |
| Lantern Search | Plugin | 0.1.0 development release | `user/plugins/lantern-search` |
| Site Workshop | Plugin | 0.2.0 development release; Icon Bench and Frontmatter Annex available | `user/plugins/site-workshop` |
| Flexible Markdown Alerts | Plugin | 1.0.1 development release; configurable alert types and optional Icon Bench interoperability | `user/plugins/flexible-markdown-alerts` |
| Grav Commander | Plugin | 0.3.12; optional bounded Jarvis consumer, file tools, and backups | `user/plugins/grav-commander` |
| Jarvis | Plugin | 0.3.2 provider-setup and operator-experience release; 0.3.1 public-contract consumer milestone proven through Commander | `user/plugins/grav-jarvis` |
| Caxton | Plugin | 0.2.0 first source-faithful Admin2 field | `user/plugins/grav-caxton` |
| Spitfire | Quark 2 child theme | Site theme | `user/themes/spitfire` |

The future suite is deliberately maintained as a roadmap until each extension
has working code and tests. See [docs/roadmap.md](docs/roadmap.md) and
[docs/architecture.md](docs/architecture.md).

## Jarvis AI services and Admin2 assistant

**Jarvis** (`grav-jarvis`) is the Grav 2 AI-service and agent-integration
framework. The runnable 0.3.2 package preserves its frozen 0.1.x contracts and
adds bounded reliability, cost/budget reporting, safe summarize-only chunking,
the permission-filtered Admin2 assistant, and native page-editor panel
for six review-first actions. It reads the current unsaved buffer through
Admin2's public events, sends bounded/redacted page context through the server-
side provider service, and requires before/after review. Accept updates only
the unsaved buffer; it never saves or publishes. Reject/replacement explicitly
revoke one-time receipts, and the deterministic authenticated browser gate
covers lifecycle, keyboard, responsive, theme, and safe provider-error states.
Grav Commander 0.3.12 now proves the first real optional consumer using only
the public Jarvis contracts. It adds bounded eligible-file actions and can
apply a hash/version-bound result only to its unsaved editor buffer; Commander
stays fully usable without Jarvis. Bounded provider-neutral HTTP, official
OpenAI and Anthropic adapters, and explicitly profiled compatible instances
remain available. Admin2 now explains each provider's enablement, exact
credential environment variable, validation state, configured default, and
discovered models, while non-secret defaults and bounded reliability settings
remain ordinary validated Grav configuration. Vendor data stays inside
adapters, credentials remain environment-only, and plugin boot makes no network
request. ChatGPT subscriptions, cookies, sessions, OAuth state, and browser
storage are never credentials for Jarvis. Broader site-wide and Grav REST/MCP
work remains staged. Read the
[Jarvis manual](plugins/grav-jarvis/README.md),
[Jarvis specification](docs/planned/grav-jarvis.md) and
[Decision 0004](docs/decisions/0004-grav-jarvis-agent-framework.md).

## Caxton source-faithful editing

**Caxton** (`grav-caxton`) is the independent Grav 2/Admin2 editor project that
replaces the earlier Page Studio working name. The runnable foundation keeps
original page bytes authoritative, exposes a bounded source-document and
localized-patch service as `$grav['gravCaxton']`, preserves unsafe or unknown
constructs as inert opaque blocks, and accepts trusted plugin extensions through
`onCaxtonExtensionRegister`. A no-edit parse/serialize is byte-identical; the
public PHP edits currently allowed are plain headings and plain single-line
paragraphs guarded by a source SHA-256. Version 0.2.0 preserves every 0.1.0
public contract and wraps the private, pinned ProseMirror/CodeMirror adapters in
the first permission-filtered Admin2 field. Visual mode shows formatted text
without Markdown punctuation; Source mode exposes the exact Markdown; ambiguous
constructs remain inert protected cards. Only ordinary Admin2 Save/Publish can
persist the current value.
Jarvis is an optional future proposal service and is not a dependency. Read the
[Caxton manual](plugins/grav-caxton/README.md),
[Caxton editor-engine proof](docs/caxton-editor-engine.md),
[Caxton specification](docs/planned/grav-caxton.md), and
[Decision 0005](docs/decisions/0005-grav-caxton-source-fidelity-editor.md).

Every installable package also carries its own end-user README so the guidance
travels with release ZIPs:

- [File Vault manual](plugins/file-vault/README.md)
- [Prism Gallery manual](plugins/prism-gallery/README.md)
- [Site Safeguard manual](plugins/site-safeguard/README.md)
- [Image Foundry manual](plugins/image-foundry/README.md)
- [Meta Pilot manual](plugins/meta-pilot/README.md)
- [Revision Ledger manual](plugins/revision-ledger/README.md)
- [Lantern Search manual](plugins/lantern-search/README.md)
- [Site Workshop manual](plugins/site-workshop/README.md)
- [Flexible Markdown Alerts manual](plugins/flexible-markdown-alerts/README.md)
- [Grav Commander manual](plugins/grav-commander/README.md)
- [Jarvis manual](plugins/grav-jarvis/README.md)
- [Caxton manual](plugins/grav-caxton/README.md)
- [Spitfire child-theme manual](themes/spitfire/README.md)

## Repository layout

```text
plugins/             independently installable Grav plugins
themes/              independently installable Grav themes
docs/                suite architecture, roadmap, and release notes
scripts/             validation and packaging helpers
dist/                generated ZIP files (ignored by Git)
```

Site content, site configuration, runtime data, protected downloads, caches,
logs, and credentials do not belong in this repository.

## Working with Grav Commander

Grav Commander remains available from its existing standalone repository and
the Grav Package Manager. It is incorporated here with Git subtree history,
rather than copied as an unrelated snapshot.

The `commander` remote points to the standalone repository. To bring its `main`
branch into this repository:

```bash
git subtree pull --prefix=plugins/grav-commander commander main
```

To publish Commander-only changes back to that repository:

```bash
git subtree push --prefix=plugins/grav-commander commander main
```

Review and test the split before pushing a release. Commander keeps its own
version, changelog, tags, and GPM identity.

## Validate and package

Validate repository structure, YAML syntax (when Ruby is available), and PHP
syntax (when PHP is available):

```bash
./scripts/verify-extensions.sh
```

Create an installable ZIP containing the required top-level extension folder:

```bash
./scripts/package-extension.sh plugin file-vault
./scripts/package-extension.sh plugin prism-gallery
./scripts/package-extension.sh plugin site-safeguard
./scripts/package-extension.sh plugin image-foundry
./scripts/package-extension.sh plugin meta-pilot
./scripts/package-extension.sh plugin revision-ledger
./scripts/package-extension.sh plugin lantern-search
./scripts/package-extension.sh plugin site-workshop
./scripts/package-extension.sh plugin flexible-markdown-alerts
./scripts/package-extension.sh plugin grav-commander
./scripts/package-extension.sh plugin grav-jarvis
./scripts/package-extension.sh plugin grav-caxton
./scripts/package-extension.sh theme spitfire
```

Generated archives are written to `dist/` and are intentionally ignored.

The complete checklist is in [docs/releasing.md](docs/releasing.md).

Security-sensitive and state-changing extension boundaries also require
external black-box regression coverage. The suite-wide contract, current
coverage inventory, and DDEV runners are documented in
[Testing and verification](docs/testing.md).

## Licensing

Each extension contains its own license. Do not assume that a license at the
repository root overrides an extension's package license.
