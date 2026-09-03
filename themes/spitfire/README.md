# Spitfire theme

This is the update-safe site theme for SpitfireBBS.com. It inherits everything
it does not override from the installed `quark2` theme.

This package is intentionally site-specific. General-purpose plugins in the
same repository must not depend on it.

The child theme owns:

- the Spitfire logo;
- the site footer treatment;
- the nested Home and Archive navigation treatment;
- stable Admin-editable anchors for modular Home sections;
- the compact hero title/subtitle spacing;
- an optional responsive two-column introduction for Features modules;
- the modular text wrapped-image option and its Admin blueprint; and
- a reusable, responsive modular form treatment;
- responsive legacy-manual cards, chapter navigation and preserved-text presentation;
- separate responsive documentation shells for current SPITFIRE NG guidance
  and the historical Archive;
- page-tree-driven documentation navigation, breadcrumbs, and active-page
  treatment;
- SPITFIRE NG project, homepage, documentation-card, and status presentation;
- a progressively enhanced documentation sidebar; and
- the compact optional frontend account control;
- site-specific CSS.

Version 1.1 adds the Archive information architecture, responsive dropdown
menus, the long-form project story treatment, and the searchable newsletter
archive presentation.

Version 1.2 adds a reusable modular form wrapper and refreshes the packaged
site favicon. Form definitions remain in page content so validation, CAPTCHA,
email and save actions can be configured per form.

Version 1.3 adds the Manuals & Documentation presentation: responsive era and
chapter cards, compact chapter navigation, and readable monospace treatment for
historical DOS documents. The documentation content remains in `user/pages`.

Version 1.4 reconciles the accepted site integration into canonical source. It
adds distinct current-project and historical documentation shells, responsive
sidebars, breadcrumbs, project and homepage presentation, and an optional
frontend account control. Page wording and project status remain site content,
not hard-coded theme data.

Page content remains in `user/pages` and site configuration remains in
`user/config`, both of which are already outside the parent theme.

## Requirements

- Grav 1.7+ or Grav 2
- Quark 2 1.1.11+

## Installation

1. Install and retain the parent `quark2` theme.
2. Copy `spitfire` to `user/themes/spitfire`.
3. Select `spitfire` as the active theme in `user/config/system.yaml` or Admin.
4. Clear Grav cache.

Site settings belong in `user/config/themes/spitfire.yaml`. Page-specific
modular options belong in each page's frontmatter and remain outside the theme.

## Content options

The Features modular blueprint can enable a balanced two-column introduction
at desktop widths while keeping its headings full width. The Text modular
blueprint can float its selected image left or right so following prose wraps
around it, returning to a single column on narrow screens.

Features, Text, and Form modular pages also provide a **Section spacing**
selector in Admin. **Normal** retains Quark 2's standard responsive spacing,
**Tight** uses Quark 2's `section-tight` spacing, and **Tighter** uses the
Spitfire child theme's `section-tighter` spacing at half of Tight. Adjacent
modules each contribute their own padding, so choose a compact option on both
sides when reducing the gap between two sections.

Navigation dropdowns are generated from the Grav page hierarchy. Create actual
child pages, keep them routable, and set their visibility/order in Admin rather
than hard-coding menu links in the template.

## Documentation navigation

Pages carrying the `sfng-documentation` body class use the current-project
documentation shell. Its sidebar is generated from visible, published children
of `/spitfire-ng/manuals-documentation`; a section with children becomes a
native, keyboard-operable disclosure group, and the current section opens by
default. JavaScript recenters the active item when needed but is not required
to open or follow navigation.

Routes below `/archive/manuals-documentation` use a separate historical shell.
Its curated navigation can include published archival reading pages that stay
hidden from global navigation. The shell labels them as historical material and
does not present them as current SPITFIRE NG instructions.

The documentation templates accept the bounded `doc_icon` names used by the
site and render an internal Font Awesome allowlist. The theme therefore does
not require Site Workshop merely to render navigation; Site Workshop remains
an independent optional source of icons for other site content.

The implementation lives in:

- `templates/default.html.twig` for the two documentation shells;
- `templates/macros/spitfire-doc-navigation.html.twig` for current guidance;
- `templates/macros/spitfire-archive-doc-navigation.html.twig` for historical
  reading editions;
- `templates/macros/spitfire-icons.html.twig` for bounded decorative icons;
- `js/doc-sidebar.js` for optional active-item scrolling; and
- `css/custom.css` for project and documentation presentation.

## Frontend account control

When the Login plugin is enabled, the navigation partial adds a compact account
control. Guests reach the configured login route. Authenticated visitors receive
an account menu and logout action; an authorized administrator may also reach
the site-owned Archive maintainer page. Admin2 and frontend login sessions remain
separate.

## Footer and branding

The footer partial, logo files, and site CSS are child-theme assets. Edit them
here, never in Quark 2. Theme configuration controls the appearance defaults;
page content and legal/footer wording should be reviewed whenever the site
owner or year changes.

Keep Quark 2 installed and update it normally. Do not move these files back into
`user/themes/quark2`. When Quark 2 changes an upstream template that this child
overrides, compare that parent file with the corresponding child file before
adopting the upstream markup.

## Updating this child theme

This directory in `grav-extensions` is authoritative. A Grav/DDEV installation
receives a copied deployment at `user/themes/spitfire`; that installed copy must
not become an independent source of truth. Before deployment, compare both
trees, reconcile intentional integration work here, validate and package the
canonical theme, then replace the installed copy from canonical source. Keep
page content, configuration, accounts, runtime data, and caches outside the
theme.

Back up `user/themes/spitfire` and any site override YAML before replacement.
After deployment, clear Grav cache and review the home hero, SPITFIRE NG landing
page, both documentation shells, nested navigation at desktop/mobile widths,
modular Features and Text examples, light/dark modes, optional account control,
and footer. Build the reviewed package from the repository root with:

```bash
./scripts/package-extension.sh theme spitfire
```

Updating Quark 2 does not overwrite this directory, but an upstream template or
CSS change can still alter inherited behavior. Test parent-theme upgrades on a
staging copy before production.

## License

MIT. Site photographs, logos, written content, and other assets may have their
own rights and are not relicensed merely because the theme code is MIT. The
SPITFIRE and SPITFIRE NG logo files reconciled from the site's maintained brand
asset collection remain branding assets under that separate rights boundary.
