# Decision 0003: verify extension boundaries as black boxes

**Status:** accepted for the extension suite
**Date:** 2026-08-22

## Context

Unit, syntax, archive, and in-process service checks can all pass while a real
browser-to-server boundary is broken. Site Safeguard exposed two examples:

1. Safari performed attachment-related requests differently from the assumed
   JavaScript flow; and
2. a DDEV site restored from production generated a production-host download
   URL, so a valid DDEV ticket was delivered to `spitfirebbs.com`, where the
   DDEV package did not exist (`SS-DL-02`).

The package itself remained valid throughout. Only a request made from outside
the Grav/PHP process could prove the complete route, origin, environment,
headers, streaming behavior, and stored bytes together.

This is the same defense-in-depth principle used by Spitfire-NG: important
parsers and boundaries receive malformed-input, boundary, and regression tests
in addition to internal checks.

## Decision

Every extension in this repository will maintain black-box contract coverage
for its security-sensitive and state-changing boundaries.

A black-box test must interact through the same public interface used by an
operator, browser, CLI consumer, or another system. It must not treat a direct
service-method call as sufficient proof of an HTTP, filesystem, archive,
authentication, cache, or rendering boundary.

For every repaired boundary defect, the owning extension must receive a
repeatable regression test when practical. That test should prove the positive
path and at least one denial or corruption path, keep secrets out of output,
and compare durable results such as bytes, hashes, response headers, or
persisted records instead of trusting only a success message.

Site Safeguard's first suite-level black-box runner is
`scripts/test-site-safeguard-download.sh`. It proves that:

- ticket creation returns a root-relative route rather than a foreign origin;
- a signed ticket is rejected when replayed for a different request host;
- a ticket resolves its original protected directory even when the simulated
  public configuration points elsewhere;
- external HEAD and range requests succeed and advertise correct headers;
- a complete external download matches the source ZIP's SHA-256; and
- a modified ticket is denied with the expected safe reference code.

The shared coverage inventory and the commands for running tests live in
[`docs/testing.md`](../testing.md).

## Consequences

- A release is not considered fully verified merely because PHP linting,
  JavaScript parsing, or an Admin success card passes.
- Extension-specific black-box runners may require DDEV, a disposable Grav
  fixture, or a dedicated browser fixture; those requirements must be explicit.
- Live credentials and production mutation are never prerequisites for routine
  regression testing.
- Tests must redact bearer tokens, signed download tickets, passwords, and
  protected filesystem paths from ordinary output.
- Coverage will be added incrementally, beginning with the highest-risk
  delivery, archive, permission, restore, derivative, and cache boundaries.
- The repository preflight remains fast and offline; black-box suites are a
  separate release gate because they require a running Grav installation.
