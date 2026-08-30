# Grav Extensions working agreement

This repository is the durable project record. Conversation history is useful
context, but it is not authoritative project memory.

## Resume procedure

Before modifying the suite:

1. read `CURRENT-STATE.md`;
2. read `README.md`, `docs/roadmap.md`, and `docs/architecture.md`;
3. read `docs/decisions/README.md` and the latest entries in
   `docs/SESSION-LOG.md`;
4. when `CURRENT-STATE.md` names a planned extension as active, read its brief
   under `docs/planned/` and every decision record linked from that brief;
5. inspect `git status` and recent commits; and
6. summarize the active checkpoint, exact next action, deferred work, and any
   uncommitted local work before changing it.

## Durable rules

- Keep every plugin independently installable and site-agnostic. Site-specific
  content and presentation belong in a site or theme, not plugin defaults.
- Preserve unrelated dirty-worktree changes. Never fold unfinished work into a
  release or checkpoint merely because it is present locally.
- Treat extension READMEs as traveling operator documentation, decision records
  as durable rationale, `CURRENT-STATE.md` as the active handoff, and
  `docs/SESSION-LOG.md` as append-only chronology.
- Security-sensitive and state-changing boundaries require external black-box
  regression coverage under Decision 0003. Internal service calls alone do not
  prove an HTTP, browser, filesystem, archive, cache, or process boundary.
- Do not use production credentials or production mutation for routine tests.
  Use DDEV or another disposable fixture and redact tokens, secrets, and
  protected paths from ordinary test output.
- Before release, run repository preflight, extension-specific black-box tests,
  install/package checks, and proportionate public/Admin health checks.
- Do not deploy, restore, delete, tag, or publish a release without authority
  for that external or destructive action.

## Meaningful session close

At a meaningful checkpoint:

1. update `CURRENT-STATE.md`;
2. append the work, evidence, and remaining caveats to `docs/SESSION-LOG.md`;
3. update a decision record only when a durable rule changed;
4. keep the root README, architecture, roadmap, planned brief, and
   documentation index consistent when a first-class component changes;
5. run and record applicable gates;
6. commit a coherent scope without unrelated local work; and
7. push the checkpoint to `origin` when authorized.
