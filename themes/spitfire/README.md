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
- site-specific CSS.

Version 1.1 adds the Archive information architecture, responsive dropdown
menus, the long-form project story treatment, and the searchable newsletter
archive presentation.

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

Navigation dropdowns are generated from the Grav page hierarchy. Create actual
child pages, keep them routable, and set their visibility/order in Admin rather
than hard-coding menu links in the template.

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

Back up `user/themes/spitfire` and any site override YAML, then replace the child
theme directory with the new release. Clear cache and review the home hero,
nested navigation at desktop/mobile widths, modular Features and Text examples,
light/dark modes, and footer.

Updating Quark 2 does not overwrite this directory, but an upstream template or
CSS change can still alter inherited behavior. Test parent-theme upgrades on a
staging copy before production.

## License

MIT. Site photographs, logos, written content, and other assets may have their
own rights and are not relicensed merely because the theme code is MIT.
