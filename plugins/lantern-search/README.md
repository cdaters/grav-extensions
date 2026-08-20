# Lantern Search

Lantern Search is a clean-room, self-contained search plugin for Grav 2. It builds a compact index of public pages, ranks results with meaningfully weighted fields, tolerates small typing mistakes, and supplies both an accessible visitor search palette and an Admin2 control center.

Its first engine deliberately favors shared-host portability: it needs neither SQLite FTS5 nor a hosted search service. The provider events and stored schema leave room for a future SQLite or external adapter without changing the public interface.

## What 0.1.0 includes

- Incremental JSON indexing that reuses unchanged page records.
- Public-content safety: unpublished, non-routable, modular, ACL-protected, excluded, and `noindex` pages are omitted before index storage.
- Weighted title, taxonomy, description, and body relevance.
- Exact-phrase bonuses, prefix matching, optional typo tolerance, per-page boosts, excerpts, and facets.
- An accessible command palette with keyboard navigation, `/` and `Ctrl/Cmd+K` shortcuts, responsive layout, and light/dark support.
- A theme-aware Admin2 dashboard for status, rebuilding, settings, and search previews.
- CLI indexing and querying plus provider events for controlled integrations.

## Installation

Copy `lantern-search` to `user/plugins/lantern-search`, clear Grav's cache, and grant trusted Admin roles `lantern-search.read` and `lantern-search.rebuild`. Open **Lantern Search** in Admin2 and choose **Rebuild index**. The public palette is enabled by default and needs no theme override.

## Visitor search

Visitors can select the floating **Search** control, press `/` while not typing in another field, or press `Ctrl+K` / `Command+K`. Up/Down chooses a result, Enter opens it, and Escape closes the palette.

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

Likely follow-ups include synonyms, spelling suggestions, multilingual token strategies, pagination, scheduled/background indexing, content-source adapters, and larger-site storage providers. JSON remains the portable baseline.

## Clean-room design references

Feature direction came from public Grav documentation and behavior, including the concepts of field weighting, facets, fuzzy matching, language-aware indexes, and lifecycle-triggered rebuilds. See Grav's [search documentation](https://learn.getgrav.org/20/grav-premium/yetisearch-pro), [API events](https://learn.getgrav.org/20/api/events), and [plugin event hooks](https://learn.getgrav.org/20/plugins/event-hooks). Lantern Search contains an original implementation and does not copy or adapt proprietary plugin source code.

## License

MIT
