# Site Safeguard archive-format direction

ZIP remains a first-class, always-supported Site Safeguard format. The proposed
Site Safeguard Archive (`.ssa`) and Site Safeguard Secure (`.sss`) formats are a
design and benchmarking project, not an implemented feature in version 0.3.

## Why investigate a streaming format

Portable backup creation has different constraints from ordinary desktop
archiving. Shared hosts can impose short execution slices, memory limits, slow
filesystem access, and throttled CPU. A forward-only record stream can offer:

- bounded memory independent of archive size;
- resumable creation at entity or chunk boundaries;
- no central-directory structure to retain or reconstruct;
- optional multi-part output without rewriting earlier parts; and
- one-pass compression, inventory hashing, and authenticated encryption.

A format name alone does not provide these benefits. The writer, scheduler,
recovery reader, and failure model must all be designed around bounded chunks.

Modern ZIP implementations can write entry sizes and CRC values after entry
data by using data descriptors, so ZIP does not universally require a PHP-level
pre-read. Site Safeguard will benchmark its actual `ZipArchive`/libzip path
against an SSA prototype before making performance claims or changing defaults.

## SSA principles

The initial SSA specification should be public, versioned, little-endian, and
simple enough for an independent recovery reader. Proposed properties:

- a small archive header with format version and feature flags;
- a sequence of directory, file, and link entity records;
- UTF-8 relative paths and explicit UNIX mode and modification-time metadata;
- 64-bit sizes;
- stored or raw Deflate payload chunks using ubiquitous Zlib;
- bounded chunks with explicit lengths so damaged input cannot trigger
  unbounded allocations;
- a SHA-256 content inventory produced while source bytes are streamed;
- an authenticated or checksummed terminal manifest which can be absent on an
  interrupted archive and is required before an archive is considered complete;
- optional archive splitting only at well-defined record/chunk boundaries; and
- no central directory and no requirement to seek backwards.

SSA readers must apply the same traversal, duplicate-path, symlink, expansion,
entry-count, and containment defenses already used for ZIP imports.

## SSS principles

SSS should wrap the SSA record stream in modern authenticated encryption rather
than inventing a cipher or copying an older encrypted-archive construction.
The current preferred direction is:

- PHP Sodium `secretstream_xchacha20poly1305` for chunked authenticated
  encryption with rekey and final-record support;
- `sodium_crypto_pwhash` Argon2id for passphrase-based key derivation;
- a random salt and explicit, versioned KDF resource parameters in the public
  envelope header;
- authentication of format version and non-secret header parameters as
  associated data;
- fixed upper bounds for encrypted and plaintext chunk lengths;
- final-tag enforcement so truncation is rejected;
- generic authentication errors which do not disclose whether a passphrase,
  header, or data chunk was wrong; and
- published test vectors plus cross-process round-trip, tamper, truncation,
  reordering, and wrong-passphrase tests.

Sodium is a preferred capability, not a minimum Site Safeguard dependency. A
host without Sodium can continue using ZIP and unencrypted SSA. A future
OpenSSL AES-256-GCM compatibility profile may be specified for such hosts, but
it would have a distinct profile/version, KDF parameters, nonce construction,
test vectors, and capability warning. SSS must never silently substitute CBC,
an unauthenticated cipher, or plaintext when the requested provider is missing.

Bcrypt is appropriate for verifying an independent Recovery Console access
password, but not for deriving the archive-encryption key. The archive KDF and
the console login verifier are separate security boundaries.

## Default-format gate

SSA may become the creation default only after all of the following are true:

1. ZIP remains selectable and fully tested.
2. The SSA/SSS specifications and test vectors are published.
3. The plugin and standalone Recovery Console can both read the formats.
4. Large-file and large-tree tests run under representative shared-host memory,
   execution-slice, and I/O limits.
5. Benchmarks demonstrate a material creation or recovery advantage over the
   existing ZIP writer.
6. Interrupted, corrupt, truncated, and malicious archives fail safely.
7. Format migration and backwards-compatibility rules are documented.

This gate keeps the future default evidence-based while preserving ZIP as the
portable escape hatch.

## Research basis

The direction is informed by Akeeba's publicly documented JPA and JPS design
goals: PHP-efficient forward entity blocks, no ZIP-style central directory or
per-file CRC32 in JPA, and chunked compression/encryption in JPS. Site Safeguard
will use an independently specified record layout and modern authenticated
cryptography; it will not copy Akeeba implementation code.

- [Akeeba JPA archive format](https://www.akeeba.com/documentation/akeeba-backup-joomla/jpa-archive-format.html)
- [Akeeba JPS archive format](https://www.akeeba.com/documentation/akeeba-backup-joomla/jps-archive-format.html)
- [PHP Sodium secretstream](https://www.php.net/manual/en/function.sodium-crypto-secretstream-xchacha20poly1305-init-push.php)
- [PHP Sodium password hashing](https://www.php.net/manual/en/function.sodium-crypto-pwhash.php)
