#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/.." && pwd)"
plugin_dir="$repo_root/plugins/grav-jarvis"
contract_dir="$repo_root/tests/grav-jarvis"
contract_files=(
    "$contract_dir/contract.php"
    "$contract_dir/provider-boundary.php"
    "$contract_dir/openai-provider.php"
    "$contract_dir/compatible-provider.php"
    "$contract_dir/anthropic-provider.php"
)

if command -v php >/dev/null 2>&1; then
    while IFS= read -r -d '' php_file; do
        php -l "$php_file" >/dev/null
    done < <(find "$plugin_dir" -type f -name '*.php' -print0)
    for contract_file in "${contract_files[@]}"; do
        php -l "$contract_file" >/dev/null
        GRAV_JARVIS_PLUGIN_DIR="$plugin_dir" php "$contract_file"
    done
    exit 0
fi

ddev_project="${1:-${GRAV_JARVIS_DDEV_PROJECT:-}}"
if [[ -z "$ddev_project" ]]; then
    local_fixture="/Users/cdaters/Documents/Spitfire/custom-plugins/file-vault-ddev"
    if [[ -d "$local_fixture/.ddev" ]]; then
        ddev_project="$local_fixture"
    fi
fi

if ! command -v ddev >/dev/null 2>&1 || [[ -z "$ddev_project" ]] || [[ ! -d "$ddev_project/.ddev" ]]; then
    echo "ERROR: PHP is unavailable. Pass a running DDEV project or set GRAV_JARVIS_DDEV_PROJECT." >&2
    exit 1
fi

temporary_dir="$(mktemp -d "$ddev_project/.grav-jarvis-contract.XXXXXX")"
temporary_name="$(basename "$temporary_dir")"
case "$temporary_name" in
    .grav-jarvis-contract.*) ;;
    *)
        echo "ERROR: Refusing to use an unexpected temporary directory." >&2
        exit 1
        ;;
esac

cleanup() {
    rm -rf -- "$temporary_dir"
    (cd "$ddev_project" && ddev mutagen sync >/dev/null 2>&1) || true
}
trap cleanup EXIT

cp -R "$plugin_dir" "$temporary_dir/plugin"
cp -R "$contract_dir" "$temporary_dir/tests"

(
    cd "$ddev_project"
    ddev mutagen sync >/dev/null
    ddev exec bash -lc \
        "find '/var/www/html/$temporary_name/plugin' -type f -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null"
    ddev exec bash -lc \
        "find '/var/www/html/$temporary_name/tests' -type f -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null"
    for contract_file in "${contract_files[@]}"; do
        contract_name="$(basename "$contract_file")"
        ddev exec env \
            GRAV_JARVIS_PLUGIN_DIR="/var/www/html/$temporary_name/plugin" \
            php "/var/www/html/$temporary_name/tests/$contract_name"
    done
)
