#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/.." && pwd)"

command -v node >/dev/null 2>&1 || {
    echo "ERROR: node is required." >&2
    exit 69
}

node "$repo_root/tests/site-safeguard/admin-ui-contract.mjs"
echo "Site Safeguard Admin UI contract passed."
