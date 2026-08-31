#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/.." && pwd)"
editor_dir="$repo_root/tests/grav-caxton/editor"

checksum() {
    if command -v shasum >/dev/null 2>&1; then
        shasum -a 256 "$1" | awk '{print $1}'
    elif command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$1" | awk '{print $1}'
    else
        echo "ERROR: shasum or sha256sum is required for bundle reproducibility." >&2
        exit 69
    fi
}

command -v node >/dev/null 2>&1 || {
    echo "ERROR: Node.js is required for the Caxton editor proof." >&2
    exit 69
}

command -v npm >/dev/null 2>&1 || {
    echo "ERROR: npm is required for the Caxton editor proof." >&2
    exit 69
}

(
    cd "$editor_dir"
    npm ci --ignore-scripts
    npm test
    npm run build
    npm run test:browser
)

first_hash="$(checksum "$repo_root/plugins/grav-caxton/admin-next/proof/caxton-editor.js")"
(
    cd "$editor_dir"
    npm run build >/dev/null
)
second_hash="$(checksum "$repo_root/plugins/grav-caxton/admin-next/proof/caxton-editor.js")"

if [[ "$first_hash" != "$second_hash" ]]; then
    echo "ERROR: Caxton browser bundle is not reproducible." >&2
    exit 1
fi

echo "Caxton editor bundle reproducibility passed: $second_hash"
