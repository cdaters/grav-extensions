# Site Safeguard — graduated specification

Site Safeguard graduated into a working development package at
[`plugins/site-safeguard`](../../plugins/site-safeguard/README.md).

Its first milestone created profile-driven archives with manifests and
checksums, omitted caches and host debris, validated imports, and built a
re-verified recovery stage outside the running site. Version 0.2 adds guarded,
CLI-only full-site restore. It revalidates and boots the source stage, creates
and boots an immediately usable rollback package and stage, coordinates Grav
maintenance mode, mirrors the staged site while preserving host-local paths,
verifies the restored files, and boots the result in a fresh PHP process.

A standalone Recovery Assistant, scheduled backups, encrypted off-site
providers, and explicit external-data-set support remain roadmap work. The CLI
restore is the supported full transfer mechanism today.

It coexists with Grav Commander: Commander remains the trusted operator's
file/backup workbench; Site Safeguard owns verified deployment and restore
orchestration. Neither reads the other's private data directly.
