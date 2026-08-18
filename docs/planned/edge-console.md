# Edge Console

A narrowly scoped Cloudflare operations plugin for Grav.

The first milestone will use restricted API tokens for zone diagnostics, cache
purge, and development-mode controls with an audit trail. Tokens remain in
environment-specific configuration, logs are redacted, and an edge-service
failure must never corrupt local content or cache state.
