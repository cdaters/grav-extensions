#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/.." && pwd)"
commander_dir="$repo_root/plugins/grav-commander"
jarvis_dir="$repo_root/plugins/grav-jarvis"
contract_dir="$repo_root/tests/grav-commander"

node --check "$commander_dir/admin-next/pages/grav-commander.js"
GRAV_COMMANDER_PLUGIN_DIR="$commander_dir" node "$contract_dir/jarvis-admin-ui-contract.mjs"

if command -v php >/dev/null 2>&1; then
    find "$commander_dir" -type f -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
    php -l "$contract_dir/jarvis-integration.php" >/dev/null
    GRAV_COMMANDER_PLUGIN_DIR="$commander_dir" \
        GRAV_JARVIS_PLUGIN_DIR="$jarvis_dir" \
        php "$contract_dir/jarvis-integration.php"
    exit 0
fi

ddev_project="${1:-${GRAV_COMMANDER_DDEV_PROJECT:-}}"
if [[ -z "$ddev_project" ]]; then
    canonical_fixture="/Users/cdaters/Documents/Spitfire/custom-plugins/file-vault-ddev"
    if [[ -d "$canonical_fixture/.ddev" ]]; then
        ddev_project="$canonical_fixture"
    fi
fi

if ! command -v ddev >/dev/null 2>&1 || [[ -z "$ddev_project" ]] || [[ ! -d "$ddev_project/.ddev" ]]; then
    echo "ERROR: PHP is unavailable. Pass a running DDEV project or set GRAV_COMMANDER_DDEV_PROJECT." >&2
    exit 1
fi

temporary_dir="$(mktemp -d "$ddev_project/.grav-commander-jarvis.XXXXXX")"
temporary_name="$(basename "$temporary_dir")"
case "$temporary_name" in
    .grav-commander-jarvis.*) ;;
    *) echo "ERROR: Refusing to use an unexpected temporary directory." >&2; exit 1 ;;
esac

cleanup() {
    rm -rf -- "$temporary_dir"
    (cd "$ddev_project" && ddev mutagen sync >/dev/null 2>&1) || true
}
trap cleanup EXIT

cp -R "$commander_dir" "$temporary_dir/commander"
cp -R "$jarvis_dir" "$temporary_dir/jarvis"
cp -R "$contract_dir" "$temporary_dir/tests"

(
    cd "$ddev_project"
    ddev mutagen sync >/dev/null
    ddev exec bash -lc "find '/var/www/html/$temporary_name/commander' -type f -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null"
    ddev exec php -l "/var/www/html/$temporary_name/tests/jarvis-integration.php" >/dev/null
    ddev exec env \
        GRAV_COMMANDER_PLUGIN_DIR="/var/www/html/$temporary_name/commander" \
        GRAV_JARVIS_PLUGIN_DIR="/var/www/html/$temporary_name/jarvis" \
        php "/var/www/html/$temporary_name/tests/jarvis-integration.php"
)
