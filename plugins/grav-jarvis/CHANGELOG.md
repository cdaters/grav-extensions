# Changelog

## 0.3.2 — 2026-08-30

- Added a browser-safe Admin2 provider setup catalog covering enabled/disabled
  state, exact credential environment-variable names, local credential status,
  configured defaults, compatible-instance metadata, and declared capabilities
  without returning or retaining credential values.
- Added coherent OpenAI/Anthropic setup cards, current official key links, the
  ChatGPT-subscription versus API-platform distinction, explicit server-side
  Validate / Test Connection actions, and normalized credential/validation
  failure presentation.
- Made preferred provider, built-in/compatible provider defaults, compatible
  instance metadata, selected reliability controls, chunk bounds, budgets, and
  optional versioned pricing metadata manageable through the normal Admin2
  plugin blueprint. No secret field was added.
- Preserved configured/current model choices through discovery failure, showed
  the configured default distinctly, and flagged a default missing from live
  discovery without silently replacing it.
- Rewrote the traveling operator guide with OpenAI project/key permissions,
  API-versus-ChatGPT billing, Anthropic, production, exact canonical DDEV local
  secret workflow, rotation/removal, validation, model selection,
  troubleshooting, and the decision not to treat masked plaintext YAML as a
  secure secret store.
- Expanded deterministic backend/component and signed-in Admin2 coverage for
  missing/configured/invalid credentials, setup help, validation, discovery,
  default changes, graceful discovery failure, responsive/theme/accessibility,
  no credential leakage, and package exclusion of local secret files.
- Preserved every frozen 0.1.x public interface, fixed endpoint and SSRF policy,
  permission/CSRF boundary, and unsaved-buffer-only proposal acceptance.

## 0.3.0 — 2026-08-30

- Added additive provider-neutral reliability contracts and a service decorator
  with bounded transient retries and normalized retry-after handling.
- Added disabled-by-default site/actor/page/action/provider/model-scoped private
  response caching with hashed keys, bounded TTL/capacity, and no raw requests.
- Added normalized request/retry/cache usage reports, fixed-point estimates from
  operator-supplied versioned pricing, and opt-in request/input/output/cost/retry
  budgets that fail before known excess calls.
- Added deterministic Grav/Markdown frontmatter, block, list, and fenced-code
  chunking plus summarize-only bounded final synthesis; rewrite reconstruction,
  background jobs, crawling, RAG, MCP, and Commander integration remain deferred.
- Added concise Admin2 usage/cost/retry/cache and budget-failure presentation,
  while preserving one-time review receipts and unsaved-buffer-only acceptance.
- Added deterministic offline reliability and chunking suites and updated the
  authenticated browser regression for automatic transient recovery.

## 0.2.1 — 2026-08-30

- Hardened the Admin2 assistant and page panel across authenticated lifecycle,
  keyboard, narrow-screen, inherited light/dark-theme, loading, validation,
  retry, and safe categorized-error states without widening provider authority.
- Added explicit Reject receipt revocation, replacement-receipt invalidation,
  deterministic expiry/capacity handling, cross-user/cross-page denial, replay
  protection, and a bounded private proposal store containing hashes only.
- Kept failed generation, stale acceptance, and transient provider errors
  recoverable in the UI: reviewed content remains visible until a successful
  retry or explicit Reject, and Accept still changes only the unsaved buffer.
- Added an authenticated, deterministic Playwright regression using a temporary
  Admin2 account and test-only provider fixture. It covers all six actions,
  provider/model states, token and authority boundaries, receipt lifecycle,
  accessibility, responsive layout, theme inheritance, and no save/publish or
  browser-to-provider traffic.
- Added backend hardening tests for opaque receipts, expiry, capacity, replay,
  replacement, failure classification, safe API codes, and fixed provider
  authority. No live provider, network request, or paid API credit is required.
- Preserved the frozen 0.1.x public interfaces and the 0.2.0 whole-buffer review
  and approval contract. Per-user preference persistence and selection-aware
  editing remain deliberately deferred.

## 0.2.0 — 2026-08-30

- Added a permission-filtered Admin2 Jarvis page with provider/model selection,
  safe provider validation, general prompts, normalized responses, loading,
  retry, missing-credential, and unavailable-provider states.
- Added the native page-editor context panel using Admin2's published current-
  buffer and replace-buffer events, with Rewrite, Proofread, Shorten, Expand,
  Summarize, and Custom Prompt actions.
- Added a provider-neutral internal prompt library and deterministic 49,152-
  byte content, 8,192-byte frontmatter, and 4,096-byte/32-item media context
  bounds. Known environment secrets and credential-like fields are redacted;
  truncation is visible and content-truncated proposals are preview-only.
- Added before/after review, explicit Accept/Reject, separate access/use/approve
  permissions, page ACL and API-scope enforcement, source/proposal hash checks,
  and 15-minute one-time receipts that persist hashes only. Accept changes only
  the unsaved editor buffer; no route saves, publishes, or deletes a page.
- Added deterministic Admin backend and browser-component contracts covering
  all six actions, provider/model/status states, bounded context, redaction,
  absence/failure behavior, accept-once/stale behavior, Reject non-mutation,
  and no browser provider traffic or save/publish event.
- Preserved every frozen 0.1.0/0.1.1 public interface byte-for-byte. Selection-
  aware editing is deferred because Admin2 2.1.2 exposes no stable selected-
  text contract.

## 0.1.4 — 2026-08-30

- Added an isolated official Anthropic provider using versioned Models and
  Messages API contracts without changing any frozen Jarvis interface.
- Added environment-only `GRAV_JARVIS_ANTHROPIC_API_KEY` authentication,
  non-generating validation/model discovery, ordered text-block normalization,
  provider-reported usage, typed failures, and default network-free provider
  registration.
- Added the provider-neutral `max_output_units` request option and private
  OpenAI/Anthropic wire mappings so opt-in live smoke calls have a hard output
  bound without vendor vocabulary entering shared contracts.
- Added ten deterministic Anthropic checks for bounded transport, headers,
  validation, discovery, capabilities, completion, usage, multiple blocks,
  bounded cursor pagination, malformed/empty/incomplete responses, credentials/
  configuration, auth/rate/4xx/5xx/timeout failures, offline behavior,
  redaction, and frozen contracts.
- Added an explicitly opt-in OpenAI/Anthropic live smoke harness that cleanly
  skips without a configured credential and never replaces deterministic
  release tests.
- Deliberately omitted CLI commands, Admin2 UI, streaming, tool calling,
  structured output, jobs, MCP workflows, Commander integration, and further
  providers. Jarvis 0.2.0 is the first Admin2 usability slice.

## 0.1.3 — 2026-08-30

- Added a separate opt-in OpenAI Responses-compatible provider type without
  changing the official OpenAI adapter or shared Jarvis contracts.
- Added named compatible-provider instances with immutable public HTTPS base
  URIs, stable provider IDs, non-secret default models, provider-scoped
  environment-variable names, and truthful optional model-discovery claims.
- Required an explicit Responses-compatible text subset, failed closed on
  Chat Completions-only and malformed/partial shapes, and classified a missing
  declared model endpoint without attempting generation.
- Reused the 0.1.2 bounded transport, including public-address enforcement,
  DNS pinning, TLS, disabled redirects/proxies, and time/size limits. Private
  and local compatible endpoints remain unsupported.
- Added deterministic full-compatible, multi-instance, no-discovery, missing-
  endpoint, incompatible-shape, rate-limit, cross-instance credential, and
  private-destination fixtures.
- Deliberately omitted CLI, Admin2 UI, streaming, structured output, tool
  calling, jobs, MCP workflows, and other live providers.

## 0.1.2 — 2026-08-30

- Added a bounded provider-neutral production HTTP transport with HTTPS/base-
  path allowlisting, public-address validation and DNS pinning, TLS
  verification, disabled redirects/proxies, explicit timeouts, request/
  response/header size bounds, and sanitized typed failures.
- Added the official OpenAI provider using `GET /models` for validation/model
  discovery and `POST /responses` for synchronous completion.
- Kept OpenAI request, response, status, and usage structures inside the
  adapter while normalizing text, models, and provider-reported token usage
  into the unchanged 0.1.0/0.1.1 public contracts.
- Added environment-only `GRAV_JARVIS_OPENAI_API_KEY` resolution, a non-secret
  default-model setting, and default provider registration without any network
  activity during plugin boot.
- Added deterministic offline transport/OpenAI fixtures covering success,
  usage, empty/malformed responses, missing/malformed credentials and config,
  authentication, rate limits, other HTTP failures, timeouts, SSRF controls,
  absence/failure behavior, and secret redaction.
- Deliberately omitted Admin2 assistant UI, Grav Commander integration,
  streaming, background jobs, MCP workflows, and other live providers.

## 0.1.1 — 2026-08-30

- Added optional provider-validation and model-discovery contracts without
  changing the 0.1.0 service, provider, or registry interfaces.
- Added provider-neutral validation issue/result and model descriptor/catalog
  DTOs plus an additive introspection service interface.
- Added provider-scoped environment credential resolution, non-serializable
  credential values, safe credential prefixing, and credential/configuration
  exceptions that never include secret values.
- Added sanitized HTTP request/response and transport contracts plus a
  deterministic fixture transport and offline conformance provider.
- Added offline coverage for validation, discovery, missing/malformed
  credentials and configuration, response parsing, authentication, rate
  limiting, transport failures, redaction, absence, and deterministic output.
- Deliberately omitted every live provider, Admin2 UI, Grav Commander
  integration, background jobs, and MCP workflows.

## 0.1.0 — 2026-08-30

- Added the provider-neutral Jarvis service, provider, registry, request,
  result, usage, and exception contracts.
- Added the `onJarvisProviderRegister` event and `$grav['gravJarvis']` service.
- Added a deterministic fake provider for tests without registering it in
  production.
- Added normalized provider failures and environment-aware redaction for
  exceptions, successful output, and result metadata.
- Added standalone consumer, registration, failure, and redaction contracts.
- Deliberately omitted live providers, Admin2 UI, background jobs, MCP
  workflows, and Grav Commander integration.
