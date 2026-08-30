# Changelog

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
