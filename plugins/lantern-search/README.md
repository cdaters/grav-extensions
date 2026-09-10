# Lantern Search

Lantern Search is a clean-room, self-contained search plugin for Grav 2. It builds a compact index of public pages, ranks results with meaningfully weighted fields, tolerates small typing mistakes, and supplies both an accessible visitor search palette and an Admin2 control center.

Its first engine deliberately favors shared-host portability: it needs neither SQLite FTS5 nor a hosted search service. The provider events and stored schema leave room for a future SQLite or external adapter without changing the public interface.

## What the current release includes

- Incremental JSON indexing that reuses unchanged page records.
- Public-content safety: unpublished, non-routable, modular, ACL-protected, excluded, and `noindex` pages are omitted before index storage.
- Weighted title, taxonomy, description, and body relevance.
- Exact-phrase bonuses, prefix matching, optional typo tolerance, per-page boosts, excerpts, and facets.
- An accessible command palette with keyboard navigation, `/` and `Ctrl/Cmd+K` shortcuts, responsive layout, and light/dark support.
- A theme-aware Admin2 dashboard for status, rebuilding, settings, and search previews.
- CLI indexing and querying plus provider events for controlled integrations.
- Optional public-metadata providers supplied by other plugins without teaching Lantern Search about their storage models.

## Installation

Copy `lantern-search` to `user/plugins/lantern-search`, clear Grav's cache, and grant trusted Admin roles `lantern-search.read` and `lantern-search.rebuild`. Open **Lantern Search** in Admin2 and choose **Rebuild index**. The public palette is enabled by default and needs no theme override.

## Visitor search

Visitors can select the floating **Search** control, press `/` while not typing in another field, or press `Ctrl+K` / `Command+K`. The clearly labeled keyboard guide in the palette explains that Up/Down selects a result, Enter opens it, and Escape closes search. The palette follows Grav's active `data-theme` or light/dark class and falls back to the visitor's operating-system preference.

The JSON endpoint defaults to `/lantern-search/query?q=spitfire`. Optional parameters are `category`, `tag`, `language`, `template`, and `limit`; server-side bounds always apply.

## Page controls

The plugin adds **Include in public search** and **Search result boost** to normal page Options. These controls cannot override publication or ACL protections.

## CLI

```bash
bin/plugin lantern-search index
bin/plugin lantern-search index --force
bin/plugin lantern-search search "spitfire history"
```

The normal command reuses unchanged documents. `--force` rebuilds every eligible record.

## Optional content providers

Lantern Search deliberately has no built-in knowledge of File Vault or any other catalog plugin. An installed plugin can act as an adapter by subscribing to `onLanternSearchIndexPage`, which runs after Lantern's publication, routability, ACL, and `noindex` checks. If the other plugin is absent, no adapter is registered and Lantern continues indexing normal Grav content only.

The File Vault plugin supplies one such optional adapter. During a rebuild it enriches the eligible public File Vault page document with public catalog metadata: display title, original/download filename, version, author, publisher, description, functional category, tags, compatibility, provenance, release date, requirements, and documented work-file names. Search results continue to point to the public File Vault route; Lantern does not inspect archive contents or create download links.

Provider safety is part of the contract:

- Source data must already be public under the provider's own visibility and access policy.
- Protected payload bytes, private storage paths, unlisted/internal records, password or ACL secrets, signed tokens, authorization state, and private destination URLs must not be added.
- The provider owns translation from its data model into public search text; Lantern core does not read the provider's files or tables.
- A provider that changes indexed text must update the document term map and `source_hash`, allowing deterministic ranking and rebuild behavior.
- Provider failure should leave normal Grav indexing available instead of exposing fallback/private data.

The current page-enrichment event produces one result route per eligible Grav page. A future multi-document source event would be required for plugins that need a distinct result URL for every external record.

## Integration events

`onLanternSearchIndexPage` runs after core safety checks and before an eligible document is stored. Providers may add public fields or set `include` to `false`. `onLanternSearchResults` receives the bounded public payload after ranking.

## Security and privacy

- Protected content is excluded before extension events and index writes.
- The index contains public page text, so its storage directory must not be web-served.
- The visitor palette performs no analytics or visitor tracking.
- Public queries are length- and result-bounded.
- The JSON engine favors shared-host portability over an external service or database extension.

## Requirements

- Grav 2.x and PHP 8.3 or newer
- Admin2 and API plugins for the visual dashboard

## Roadmap

Likely follow-ups include synonyms, spelling suggestions, multilingual token strategies, pagination, scheduled/background indexing, first-class multi-document source adapters, and larger-site storage providers. JSON remains the portable baseline.

## Clean-room design references

Feature direction came from public Grav documentation and behavior, including the concepts of field weighting, facets, fuzzy matching, language-aware indexes, and lifecycle-triggered rebuilds. See Grav's [search documentation](https://learn.getgrav.org/20/grav-premium/yetisearch-pro), [API events](https://learn.getgrav.org/20/api/events), and [plugin event hooks](https://learn.getgrav.org/20/plugins/event-hooks). Lantern Search contains an original implementation and does not copy or adapt proprietary plugin source code.

## License

MIT

On screens up to 640px wide, result titles, routes and excerpts stack vertically.
Desktop keeps the compact title/route layout; no theme override is required.
