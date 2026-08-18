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

Report vulnerabilities using the root [security policy](../SECURITY.md).
