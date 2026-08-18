# Testing and verification

## Repository preflight

Run:

```bash
./scripts/verify-extensions.sh
```

This verifies required package files, rejects common runtime/secret artifacts,
and lints every PHP file when a host PHP executable is available.

## Grav integration test

For a meaningful release candidate, also test in a clean Grav installation:

1. Install only the extension and its declared dependencies.
2. Clear Grav cache and confirm the public site still renders.
3. Confirm Admin loads in light and dark modes.
4. Exercise anonymous, authenticated, unauthorized, and super-admin paths.
5. Verify keyboard operation and narrow/mobile layout.
6. Review browser console, Grav logs, response headers, and network errors.
7. Upgrade over the preceding released version without deleting runtime data.
8. Package the extension and repeat the install from that ZIP.

## Security-sensitive checks

- Signed links expire and do not reveal protected storage paths.
- ACL/password/limit checks run at delivery time, not only at page rendering.
- Range and HEAD requests do not inflate analytics.
- Remote URLs are validated and redirect with a restrictive referrer policy.
- User-controlled paths cannot escape configured roots.
- Logs redact secrets and collection failures never weaken access checks.
- Disabled JavaScript leaves useful public HTML and no unauthorized URL.

Record package-specific regressions in its changelog or issue tracker.
