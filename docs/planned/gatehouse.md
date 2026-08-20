# Gatehouse

Gatehouse is the planned authentication-hardening plugin for Grav 2. It will augment, not replace, Admin2's authentication flow.

The first implementation should provide configurable CAPTCHA providers supported by the Grav ecosystem, rate limiting, progressive backoff, lockout visibility, trusted-proxy-aware client addressing, security headers, and an audited recovery path. CAPTCHA must remain an optional risk control rather than the only defense, and login must fail safely when a third-party provider is unavailable.

Later milestones can add passkey/WebAuthn support, stronger session controls, login notifications, IP allow/deny policy, and security-provider events. Secrets must stay in environment-specific configuration and never enter portable packages.
