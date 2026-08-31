---
title: Build Jarvis as a Grav 2 AI and agent framework
status: accepted
date: 2026-08-30
extensions:
  - grav-jarvis
  - grav-commander
---

# Build Jarvis as a Grav 2 AI and agent framework

## Context

Grav AI Pro demonstrates a useful centralized provider service, Admin
assistant, prompt library, streaming, CLI, background processing, caching,
retries, usage reporting, and extension API. Reimplementing that premium
product feature-for-feature would duplicate an existing product and would not
take full advantage of Grav 2.

Grav 2 now provides a first-party REST API, Admin2 extension points, and an MCP
server. REST and MCP use Grav's permissions, optimistic concurrency, and API
events. The extension suite also already requires conservative writes,
optional public service/event integration, recoverable destructive actions,
and external black-box evidence at security-sensitive boundaries.

The suite needs one site-agnostic service so Grav Commander and other plugins
can request model work without each owning provider credentials, retry logic,
prompt storage, or incompatible response formats.

## Decision

Create a planned first-class plugin with product/display name **Jarvis** and
slug/future directory `grav-jarvis`.

Jarvis will be an original Grav 2 AI-service and bounded-workflow framework,
not a clone of Grav AI Pro. It will combine:

- public contracts under `Grav\Plugin\GravJarvis\Contracts` and a provider-
  neutral PHP service registered as `$grav['gravJarvis']`;
- OpenAI, Anthropic, and OpenAI-compatible adapters first, with Gemini and
  OpenRouter considered after the contract stabilizes;
- Admin2 page-aware assistance and CLI access;
- versioned prompts, streaming, retries, privacy-safe caching, usage/cost
  reporting, Grav-aware chunking, and background jobs;
- structured proposals with diff, preview, explicit approval, source-version
  conflict checks, and optional Revision Ledger checkpoints;
- permission-checked batch workflows that enumerate targets and revalidate each
  apply; and
- composition with Grav's REST API and MCP server for site operations rather
  than a parallel permission or mutation system.

Other plugins may consume only the public interface or documented events. They
must work when Jarvis is absent. Jarvis cannot inherit another plugin's write
authority, and a consumer cannot obtain provider credentials from Jarvis.
Grav Commander is the first named integration candidate, beginning with
read-only assistance and preserving all Commander containment, permissions,
backup, and approval checks for any later edit proposal.

Secrets are server-only environment variables or external credential
references. They never enter tracked configuration, Admin2 JavaScript, API
responses, logs, caches, jobs, diagnostics, fixtures, or release packages.
Model/page/media content is untrusted data. No model output can execute code,
grant tools, widen its target set, or approve its own mutation.

The specification remains under `docs/planned/grav-jarvis.md` until a runnable
0.1.0-quality plugin meets the repository's package boundary. The first coding
checkpoint will be a small contract skeleton with interfaces/DTOs, provider
registry, deterministic fake provider, and service/absence contract tests. It
will not begin with the complete assistant or live provider stack.

## Consequences

- The suite gains one shared AI seam without making AI a dependency of every
  extension.
- Grav REST/MCP remains authoritative for site operations and permissions;
  provider calls remain a separate internal concern.
- Diff/preview/approve and stale-source denial become product requirements,
  not optional UI polish.
- Provider and model churn is isolated behind adapters and capability checks,
  but maintaining multiple providers, streaming, accounting, and jobs is still
  substantial work.
- Environment-secret handling, prompt-injection resistance, budgets, privacy,
  auditability, and external black-box tests are release gates.
- Grav 1.7/classic Admin compatibility is not promised by the 0.x line.
- No `plugins/grav-jarvis` directory is created by this decision alone;
  repository convention reserves that location for a runnable, documented,
  tested package.

## Implementation status

Jarvis 0.3.0 completes the first reliability portion of this decision. The
frozen provider-neutral service remains the only provider seam; Admin2 calls
fixed authenticated Jarvis routes and never receives credentials or vendor
wire shapes. The page panel uses public Admin2 whole-buffer events, bounded and
redacted current-page context, before/after review, and one-time hash receipts.
Accept changes only the unsaved buffer after Jarvis/page permission and source
rechecks; Reject and successful regeneration revoke their old receipts. A
signed-in deterministic browser gate now proves all six actions, one-time and
stale lifecycle behavior, safe provider-error presentation, keyboard/narrow/
theme behavior, and the absence of save/publish requests. Jarvis has no save,
publish, delete, Commander, job, or MCP mutation path. Selection-aware work
remains deferred until Admin2 publishes a stable selection contract.

An additive `ReliabilityServiceInterface` now composes bounded transient-only
retry, disabled-by-default privacy-scoped response caching, nullable normalized
usage, operator-versioned fixed-point cost estimates, opt-in operation budgets,
and deterministic Grav/Markdown chunk provenance around that frozen seam.
Known budget excesses stop before provider calls; unknown price/usage remains
explicitly unknown. Cache keys are hashes scoped by installation, actor, page,
action, provider, and model; bounded owner-only values contain redacted success
results, never requests or failures. Large-context execution is summarize-only
with bounded ordered partials and one final synthesis. There is no external
telemetry, durable AI history/accounting store, job queue, retrieval/RAG layer,
recursive expansion, automatic mutation, or new provider family.

The next decision checkpoint is a narrow optional Grav Commander consumer in
0.3.1. It is authorized only if Commander resolves the public/additive service,
keeps a non-AI fallback, previews before any existing Commander action, and
retains all of its own permission/containment authority. Jarvis absence,
misconfiguration, budget denial, or provider failure must never disable
Commander itself.
