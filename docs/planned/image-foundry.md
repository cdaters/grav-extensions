# Image Foundry

An original-preserving image optimization and derivative-management plugin.

## 0.1.0 scope

- Catalog JPEG, PNG, WebP, and AVIF sources under explicit Grav-relative roots.
- Generate self-hosted WebP/AVIF variants at configured responsive widths.
- Never upscale, overwrite, rename, or remove a source image.
- Keep generated data outside the public Grav root with atomic catalog writes.
- Invalidate only derivatives whose source hash or generation policy changed.
- Expose opaque, immutable derivative URLs without disclosing filesystem paths.
- Provide an Admin2 dashboard, equivalent CLI operations, and an opt-in Twig
  `<picture>` helper with an ordinary original URL as its fallback.

Version 0.2 adds reversible, opt-in automatic public-HTML replacement for
eligible direct local `<img>` sources. Remote optimization services,
animated-image conversion, CSS backgrounds, and generated Grav crop/cache
correlation remain outside the implemented milestone. Originals remain the
authoritative restoration path.
