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

## Next-version Admin2 presentation

Make the following dashboard sections collapsible:

- **Environment Readiness**
- **Package Library**
- **Isolated Staging**
- **Recovery Journal**

Use attention-aware defaults instead of hiding important state indiscriminately:

- collapse **Environment Readiness** when all required checks pass; expand it
  automatically for warnings or failures;
- collapse **Recovery Journal** when every operation is complete; expand it for
  an active, failed, interrupted, or rollback-required operation;
- collapse **Isolated Staging** when empty; expand it when a verified stage is
  ready, an unrecognized directory exists, or another stage needs attention;
- keep **Package Library** expanded while it is the primary create/inspect/
  download workflow, or remember the operator's last choice if Admin2 provides
  a durable per-user disclosure preference.

Tint the **Environment Readiness** header according to its aggregate state:
green when ready, amber when usable with warnings or missing recommended
capabilities, and red when a required capability is unavailable. Always pair
the tint with a status word and icon so meaning does not depend on color alone.
The collapsed summary should retain the aggregate result and relevant counts.

Use semantic disclosure controls (`button`, `aria-expanded`, and an associated
panel), preserve keyboard operation, and avoid layout shifts while live restore
status refreshes.

It coexists with Grav Commander: Commander remains the trusted operator's
file/backup workbench; Site Safeguard owns verified deployment and restore
orchestration. Neither reads the other's private data directly.
