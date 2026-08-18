# Decision 0002: restore outside the web request

**Status:** accepted for Site Safeguard 0.2  
**Date:** 2026-08-18

## Context

Version 0.1 could prove that a portable package and its isolated extraction
were intact, but deliberately stopped before replacing the running site. A
complete production-to-development workflow also needs a repeatable promotion
operation, a usable rollback copy, and a health check that does not depend on
the PHP process whose files are being replaced.

## Decision

Site Safeguard 0.2 provides full-site restore only through Grav CLI:

1. require an explicitly enabled restore setting and the exact confirmation
   phrase;
2. accept only a retained, verified, deployable stage;
3. revalidate every staged file and boot that stage in a fresh PHP process;
4. create, inspect, stage, and boot a portable rollback package of the current
   site before changing it;
5. acquire a non-blocking global restore lock and record a protected journal;
6. enable Grav maintenance mode and mirror the source stage into the site,
   removing stale non-preserved entries;
7. preserve configured host-local paths such as `.ddev`, runtime directories,
   and root environment files;
8. revalidate the restored content and boot Grav in another fresh PHP process;
   and
9. automatically restore the verified rollback stage if promotion or the final
   health check fails.

There is no HTTP or Admin restore endpoint. Admin may display the exact CLI
command for a verified stage, but cannot execute it.

## Consequences

- Production and local installations have one repeatable, evidence-producing
  full-site transfer path.
- Replacing plugin, theme, or Grav core files cannot interrupt an initiating
  web request because no such request performs the restore.
- Operators need shell access and a working PHP CLI with `proc_open()`.
- Host-local state must be reviewed before enabling restore on each destination.
- A future disposable Recovery Assistant can add a browser-guided,
  Kickstart-style process without weakening the installed plugin's safety
  boundary.
