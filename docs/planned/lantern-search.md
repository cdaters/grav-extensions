# Lantern Search

Lantern Search reached its first development release on 2026-08-19. It now
provides incremental indexing, weighted ranking, facets, excerpts, prefix and
typo-tolerant matching, an accessible public command palette, Admin2
management, CLI parity, and provider events.

The implementation is clean-room and intentionally starts with a portable JSON
engine. The permanent invariant is that unpublished, non-routable, `noindex`,
and ACL-protected pages are excluded before data reaches storage or provider
events.

Publicly documented search-plugin patterns informed the capability map: field
weighting, facets, fuzzy matching, language-aware indexing, CLI maintenance,
and page-lifecycle rebuilds. The implementation itself is original. The JSON
engine avoids a mandatory SQLite FTS5 or external-service dependency on shared
hosting while preserving extension events for later storage providers.

See the [Lantern Search manual](../../plugins/lantern-search/README.md).
