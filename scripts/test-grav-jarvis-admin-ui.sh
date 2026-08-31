#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/.." && pwd)"

if ! command -v node >/dev/null 2>&1; then
    echo "ERROR: Node.js is required for the Jarvis Admin2 UI contract." >&2
    exit 1
fi

GRAV_JARVIS_PLUGIN_DIR="$repo_root/plugins/grav-jarvis" \
    node "$repo_root/tests/grav-jarvis/admin-ui-contract.mjs"
