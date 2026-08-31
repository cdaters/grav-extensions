# Architecture decisions

Use decision records for choices that constrain more than one release or
extension. Number records sequentially (`0001-short-title.md`) and begin from
[the template](0000-template.md).

Current shared rules are collected in [architecture.md](../architecture.md).
Create a decision record when changing a rule rather than silently rewriting
the rationale.

Accepted records:

- [0001: prove recovery before promotion](0001-site-safeguard-staged-recovery.md)
- [0002: run restore across a CLI process boundary](0002-site-safeguard-cli-restore.md)
- [0003: verify extension boundaries as black boxes](0003-black-box-extension-boundaries.md)
- [0004: build Jarvis as a Grav 2 AI and agent framework](0004-grav-jarvis-agent-framework.md)
- [0005: build Caxton around a source-backed document model](0005-grav-caxton-source-fidelity-editor.md)
