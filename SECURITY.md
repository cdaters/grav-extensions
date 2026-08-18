# Security policy

Please do not place vulnerability details in a public issue. Contact the
maintainer privately and include the affected extension, version, reproduction
steps, and expected impact.

## Repository data boundary

This repository contains source code only. In particular, File Vault's runtime
catalog, statistics, activity log, signing key, password hashes, protected
files, and site-specific configuration must remain with the Grav installation
and must never be committed or included in a release archive.

The packaging script refuses to package known runtime and secret paths. Always
inspect a generated archive before publishing it.
