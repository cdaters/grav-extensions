# Prism Gallery

Prism Gallery is a site-agnostic, framework-free media gallery and viewer for Grav 1.7 and Grav 2. It supports images, local video, YouTube, Vimeo, and HTTPS iframe content without loading remote players until a visitor opens an item.

## Requirements

- Grav 1.7.32+ or Grav 2
- PHP version supported by that Grav installation
- Shortcode Core 6+ for shortcode galleries
- Quark 2 only when using its Gallery Modular page type; other themes can use
  the shortcodes and bundled markup

## Installation

Copy the complete `prism-gallery` folder to `user/plugins/prism-gallery`, enable
the plugin, and clear Grav cache:

```bash
bin/grav clearcache
```

Site-specific configuration belongs in
`user/config/plugins/prism-gallery.yaml`. Do not edit `prism-gallery.yaml`
inside the plugin when a setting should survive an update.

## Protected local media

Full-resolution local page media is protected by default. Public gallery markup contains an opaque media identifier instead of the original page-media path. When an item opens, Prism makes a same-site authorization request and receives a signed URL that expires after 15 minutes by default.

Gallery thumbnails remain ordinary Grav-generated derivatives because a public browser must fetch them to draw the gallery. Their URLs are content-hashed and do not expose the original page folder. This feature discourages casual hotlinking and search-engine discovery; it is not DRM, because any media a visitor can view can ultimately be saved.

## Quark2 modular gallery

Prism supplies the `partials/lightbox.html.twig` integration that Quark2's existing `modular/gallery.html.twig` expects. Create a Gallery modular page, upload images to it, and add them under the Gallery tab. Quark2 itself does not need to be modified.

Selecting an item opens Prism's dialog over the current page. Closing with the
button, Escape, or backdrop returns focus to the item that opened it; the raw
image does not replace the page.

## Shortcodes

```text
[prism-gallery min="220px" gap="1rem" label="Project gallery"]
[prism image="photo-one.jpg" thumb="photo-one.jpg?cropZoom=600,450" title="First photograph"]
Optional **Markdown description**.
[/prism]
[prism video="https://www.youtube.com/watch?v=VIDEO_ID" thumb="video-cover.jpg" title="Project film"]
YouTube and Vimeo players are loaded only after opening.
[/prism]
[prism video="clip.mp4" thumb="clip-cover.jpg" title="Local video" /]
[prism iframe="https://example.org/embed" title="Embedded experience" /]
[/prism-gallery]
```

Conventional `[lightbox]` and `[lightbox-gallery]` aliases are enabled by default to simplify content migration, but Prism's own shortcode names are preferred.

### Shortcode attributes

`[prism-gallery]` accepts `min`, `gap`, `ratio`, `label`, `class`, and `id`.
Each `[prism]` item accepts one source (`image`, `video`, or `iframe`) plus
`thumb`, `title`, `alt`, and an optional Markdown body used as its description.
Use local page-media filenames for protected local items. Only trusted editors
should be allowed to create arbitrary iframe destinations.

## Accessibility and behavior

- Dialog semantics and focus restoration
- Focus trapping while open
- Escape, arrow, Home, and End keyboard controls
- Swipe navigation and downward-swipe close
- Deferred remote embeds
- Opaque, just-in-time local-media URLs with signed expiration
- Reduced-motion support
- Responsive image zoom
- Multiple independent galleries per page

## Configuration

Admin settings control asset loading, conventional aliases, looping, keyboard,
touch, zoom, backdrop close, video autoplay, animation, protected-media TTL,
and grid defaults. **Always** asset loading is the cache-safe default. Use
automatic loading only when full-page caches cannot serve gallery markup on a
request where asset discovery did not run.

## Updating

Replace only `user/plugins/prism-gallery`, preserve the site override under
`user/config/plugins/prism-gallery.yaml`, clear cache, and retest one modular
gallery plus one shortcode gallery. Page media and page Markdown live outside
the plugin and are not replaced.

## Troubleshooting

- A shortcode prints literally: install/enable Shortcode Core and clear cache.
- A local original returns 403: confirm the page is published and the token has
  not expired; reload the page to request a fresh link.
- A remote video stays blank: verify its provider URL and browser content
  security policy.
- Styling or controls are absent on a cached page: keep `load_assets: always`.
- A thumbnail remains linkable: thumbnails are public derivatives by design;
  protection applies to the local full-resolution source.

## Security boundary

Opaque URLs discourage stable linking and search discovery but are not DRM.
Authorized visitors can save media their browser receives. Remote iframe
providers receive a request only after the visitor opens that item.

## License

MIT
