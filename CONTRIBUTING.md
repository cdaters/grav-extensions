# Contributing

Focused issues and pull requests are welcome. Each extension is an independent
package, so state the affected slug and version in every report.

## Before opening a change

1. Read the package README and changelog.
2. Keep site-specific content and branding out of general-purpose plugins.
3. Do not commit Grav runtime data, protected downloads, credentials, caches,
   activity logs, or local configuration.
4. Preserve public HTML without JavaScript and keyboard-accessible controls.
5. Run `./scripts/verify-extensions.sh`.
6. Update the package changelog when behavior changes.

Security reports should follow [SECURITY.md](SECURITY.md), not a public issue.

## Compatibility

Avoid edits to third-party themes and plugins. Integrations should use Grav
events, Twig namespace paths, page blueprints, documented services, and
configuration. An extension must continue to work when another suite extension
is absent unless its blueprint declares that dependency.

## Grav Commander

Commander retains its standalone repository and GPM release history. Use the
subtree procedure documented in the root README; do not replace its directory
with an unrelated copy.
