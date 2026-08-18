# Site Safeguard — graduated specification

Site Safeguard graduated into a working development package at
[`plugins/site-safeguard`](../../plugins/site-safeguard/README.md).

Its first milestone creates profile-driven archives with manifests and
checksums, omits caches and host debris, validates imports, and builds a
re-verified recovery stage outside the running site. Live promotion is reserved
for a later milestone that can coordinate maintenance mode, preserve an
immediately usable rollback package, and run outside the initiating web request.

It coexists with Grav Commander: Commander remains the trusted operator's
file/backup workbench; Site Safeguard owns verified deployment and restore
orchestration. Neither reads the other's private data directly.
