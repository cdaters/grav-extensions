# Meta Pilot

Meta Pilot is a clean-room Grav 2 metadata control center. It supplies one
consistent canonical URL, description, robots directive, Open Graph set,
X/Twitter card, and JSON-LD document for each public HTML page. It also
provides an XML sitemap, `robots.txt`, and an Admin 2 diagnostic report.

Meta Pilot is theme-agnostic. It works at the final HTML-response boundary so
themes that already use Grav's standard metadata partial continue to work.
Tags owned by an enabled Meta Pilot feature are normalized rather than
duplicated; disabling a feature leaves the theme's corresponding tags alone.

## Requirements

- Grav 2
- PHP 8.3 or newer
- Admin 2 and the API plugin for the dashboard
- A theme that renders a complete HTML document with a closing `</head>` tag

## First run

1. Install the `meta-pilot` folder at `user/plugins/meta-pilot`.
2. Clear Grav's cache.
3. Open **Meta Pilot** in Admin and review the page diagnostics.
4. Open **Plugins → Meta Pilot** to set a default social image and any site
   identity overrides.
5. Verify `/sitemap.xml` and `/robots.txt` on the public site.

The same report is available without Admin:

```sh
bin/plugin meta-pilot report
```

The dashboard reports missing, short, long, and duplicated metadata, missing
social images, and intentional `noindex` pages. Its score is a working triage
aid, not a search-ranking promise.

## Page metadata and overrides

The page editor adds a **Meta Pilot** section to the standard **Options** tab.
The equivalent frontmatter is documented below for repository and text-editor
workflows.

Meta Pilot first reads an optional `meta_pilot` block. It then honors Grav's
ordinary `metadata` values before generating safe fallbacks from the page.

```yaml
---
title: A Human Page Title
metadata:
  description: Existing Grav descriptions continue to work.
meta_pilot:
  title: Optional social/search title override
  description: Optional description override.
  image: social-card.jpg
  canonical: https://example.com/preferred-route
  robots: index, follow, max-image-preview:large
  type: article
  schema_type: Article
  twitter_card: summary_large_image
  sitemap:
    exclude: false
    changefreq: monthly
    priority: 0.7
---
```

`image` may name media attached to the page or contain an absolute,
root-relative, or Grav stream URL. Without an explicit image, Meta Pilot looks
for a configured hero image, common social-card filenames, the first attached
JPEG/PNG/WebP/AVIF image, and finally the plugin's default social image.

Set `meta_pilot.enabled: false` to leave a particular page's head untouched.
Set `meta_pilot.sitemap.exclude: true` to keep an otherwise public page out of
the sitemap. The official Grav Sitemap convention `sitemap.ignore: true` is
also honored for portability.

## Sitemap and robots behavior

The sitemap includes published, routable, non-modular pages. By default it
excludes access-protected and `noindex` pages. Existing `sitemap.changefreq`,
`sitemap.priority`, and `sitemap.ignore` frontmatter values are supported.

The generated `robots.txt` route appends the absolute sitemap URL by default.
A physical web-server `robots.txt` may take precedence before Grav receives
the request; remove it or configure the server if Meta Pilot should own that
route.

## Output and compatibility

- Meta Pilot changes only complete public HTML responses and its two document
  routes. Admin/API responses and page Markdown are never rewritten.
- It removes only the head tags controlled by features currently enabled in
  Meta Pilot, then emits one normalized set.
- Other JSON-LD scripts are preserved. Only scripts marked
  `data-meta-pilot="json-ld"` are replaced.
- Existing `metadata.description`, `metadata.robots`, Open Graph, and Twitter
  values are valid inputs and can be managed without vendor-specific fields.
- Routes, canonical URLs, and social images are emitted as absolute URLs.

## Troubleshooting

- **No tags appear:** confirm the plugin is enabled and the response has a
  closing `</head>` tag. Clear Grav's cache after configuration changes.
- **Two tags remain:** inspect whether the theme emits a nonstandard spelling
  or injects markup after Grav's output event. Meta Pilot normalizes standard
  canonical, robots, description, Open Graph, and Twitter tags.
- **Sitemap or robots route is 404:** check the configured route and confirm
  the feature is enabled. A physical file or web-server rule can bypass Grav.
- **A page is absent from the sitemap:** check its published/routable/access,
  `noindex`, `sitemap.ignore`, and `meta_pilot.sitemap.exclude` values.

## Privacy and security

Meta Pilot performs no remote crawling, tracking, or telemetry. Reports are
computed from the local Grav page tree and are available only to users with
`meta-pilot.read` (or super-admin) permission. All generated values are escaped
for their HTML, JSON, or XML context.
