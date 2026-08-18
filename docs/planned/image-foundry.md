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

Automatic HTML rewriting, remote optimization services, animated-image
conversion, and in-place replacement are intentionally outside the first
milestone. A future reversible replacement workflow must retain the source and
an auditable restoration path.
