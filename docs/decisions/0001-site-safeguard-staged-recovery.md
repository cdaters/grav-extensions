# Decision 0001: prove recovery before promotion

**Status:** accepted for Site Safeguard 0.1  
**Date:** 2026-08-18

Version 0.2 extends this decision with the separate-process restore boundary in
[Decision 0002](0002-site-safeguard-cli-restore.md).

## Context

Creating a backup is not the same as proving that it can be restored. Replacing
the running webroot from the same browser request also creates an avoidable
failure mode: the process can overwrite the code it still needs, lose its
connection, or leave neither the old nor new site complete.

## Decision

Site Safeguard 0.1 implements only the safe first half of recovery:

1. build a self-describing ZIP with per-file SHA-256 and size metadata;
2. reject unsafe, duplicate, symlinked, oversized, or malformed entries;
3. independently hash every archived file;
4. extract deployable packages to a unique directory outside the live site;
5. independently hash every extracted file; and
6. mark the stage verified but not promotion-ready.

There is no live-promotion API, button, or CLI command in 0.1. The reserved
permission does not grant an operation that does not exist.

## Consequences

- Operators can prove package integrity without changing the running site.
- Partial content packages remain useful backups but cannot masquerade as a
  complete deployable site.
- A later promotion release must add maintenance coordination, an immediately
  usable rollback copy, process separation, atomic switching where the host
  supports it, health checks, and documented manual recovery.
- Until that release, production replacement remains an explicit hosting or
  deployment operation outside Site Safeguard.
