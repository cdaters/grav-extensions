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
