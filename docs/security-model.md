# Shared security model

The extensions follow minimum authority and enforce protection at the resource
delivery or mutation boundary—not merely in the browser interface.

## Protected delivery

File Vault and Prism Gallery use separate implementations with a shared contract:

- opaque public identifiers;
- short-lived, tamper-evident same-origin URLs;
- delivery-time authorization;
- no protected filesystem path in page markup or token payload;
- restrictive cache, sniffing, indexing, and referrer headers;
- byte-range support where media/download clients require it.

This prevents stable direct links and casual hotlinking. It is not DRM: a person
authorized to receive browser-visible bytes can ultimately save them.

## Secrets and personal data

Signing keys, API tokens, passwords, raw/full IP addresses, user agents, and
protected files are runtime data. They never belong in plugin defaults, source
control, example screenshots, fixtures, or release ZIPs.

Analytics are opt-in. Use the least identifying mode, shortest useful retention,
restricted Admin permissions, and an accurate site privacy notice.

## Destructive operations

File mutation, extraction, restore, derivative replacement, and batch editing
require explicit scope, path validation, and recoverability. A web request must
not erase the running site before a staged replacement has been validated.

Site Safeguard applies this boundary to recovery packages: archive structure is
validated before deep hashing, complete packages are extracted only to a unique
directory outside the running Grav root, and extracted files are hashed again.
Version 0.1 deliberately exposes no live-promotion operation.

## AI providers and agent workflows

Jarvis treats model providers, model output, page/media context, and MCP-
retrieved material as separate trust boundaries.

- Provider credentials are server-only environment variables or external
  credential references. They never enter tracked YAML, Admin2 JavaScript,
  prompts, logs, caches, jobs, diagnostics, fixtures, or packages.
- A request discloses its destination provider and bounded context. Provider
  endpoints are validated; redirects cannot change origin, and local/private
  OpenAI-compatible endpoints require explicit opt-in.
- Untrusted content cannot become system instructions, grant a tool, expand a
  target set, execute generated code, or approve a change.
- Model work is limited by permission, rate, token, cost, context-size, and job
  budgets. Conversation/cache/job records are access-scoped and have explicit
  retention.
- Content and configuration output remains a non-mutating proposal until an
  authorized user reviews a diff and approves it. Apply rechecks permission and
  source version; stale proposals fail closed.
- External agents use a dedicated least-privilege Grav API user through the
  native REST/MCP permission model. Provider keys and Grav API keys are never
  interchangeable.

The full boundary is specified in
[`docs/planned/grav-jarvis.md`](planned/grav-jarvis.md).

Jarvis 0.1.0 implements the first portion of this boundary: request options and
metadata reject credential keys; `GRAV_JARVIS_*` environment values are loaded
only into an in-memory redactor; provider failures are converted to typed
exceptions without chaining the unsafe original; and successful provider
output/metadata is redacted before it crosses the public service. The release
contains no live provider, remote endpoint, API route, UI, persistent history,
or mutation path.

Report vulnerabilities using the root [security policy](../SECURITY.md).
