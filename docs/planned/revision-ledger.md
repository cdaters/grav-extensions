# Revision Ledger — graduated specification

Revision Ledger graduated into a working Grav 2 development package at
`plugins/revision-ledger` in version 0.2.0.

The initial release records automatic and explicit snapshots, author and reason
metadata, readable comparisons, retention policies, and deliberate rollback.
Other plugins can request a checkpoint through a small public service/event;
they cannot reach into the ledger's private files. The package README is the
authoritative operator manual; this file remains the original scope record.
