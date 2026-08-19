# Site Safeguard — graduated specification

Site Safeguard graduated into a working development package at
[`plugins/site-safeguard`](../../plugins/site-safeguard/README.md).

Its first milestone created profile-driven archives with manifests and
checksums, omitted caches and host debris, validated imports, and built a
re-verified recovery stage outside the running site. Version 0.3 adds guarded
full-site restore through either a detached Admin-launched CLI worker or a
manual CLI command. It revalidates and boots the source stage, creates and boots
an immediately usable rollback package and stage, coordinates Grav maintenance
mode, mirrors the staged site while preserving host-local paths, verifies the
restored files, and boots the result in a fresh PHP process.

A standalone Recovery Console, SSA/SSS archive engineering, scheduled backups,
encrypted off-site providers, and explicit external-data-set support remain
roadmap work. Admin restore and its CLI fallback share the same recovery engine.

It coexists with Grav Commander: Commander remains the trusted operator's
file/backup workbench; Site Safeguard owns verified deployment and restore
orchestration. Neither reads the other's private data directly.
