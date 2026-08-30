# Changelog

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
