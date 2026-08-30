# Changelog

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
