#!/usr/bin/env bash
set -euo pipefail

if [[ "${GRAV_JARVIS_LIVE_SMOKE:-0}" != "1" ]]; then
    echo "SKIP: Jarvis live smoke is opt-in; set GRAV_JARVIS_LIVE_SMOKE=1."
    exit 0
fi

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/.." && pwd)"
plugin_dir="$repo_root/plugins/grav-jarvis"
live_test="$repo_root/tests/grav-jarvis/live-smoke.php"
provider="${1:-openai}"

case "$provider" in
    openai|anthropic) ;;
    *)
        echo "ERROR: Live smoke provider must be openai or anthropic." >&2
        exit 1
        ;;
esac

if command -v php >/dev/null 2>&1; then
    php -l "$live_test" >/dev/null
    GRAV_JARVIS_PLUGIN_DIR="$plugin_dir" \
        JARVIS_LIVE_PROVIDER="$provider" \
        php "$live_test"
    exit 0
fi

ddev_project="${2:-${GRAV_JARVIS_DDEV_PROJECT:-}}"
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

temporary_dir="$(mktemp -d "$ddev_project/.grav-jarvis-live.XXXXXX")"
temporary_name="$(basename "$temporary_dir")"
case "$temporary_name" in
    .grav-jarvis-live.*) ;;
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
cp "$live_test" "$temporary_dir/live-smoke.php"

(
    cd "$ddev_project"
    ddev mutagen sync >/dev/null
    ddev exec env \
        GRAV_JARVIS_LIVE_SMOKE=1 \
        GRAV_JARVIS_PLUGIN_DIR="/var/www/html/$temporary_name/plugin" \
        JARVIS_LIVE_PROVIDER="$provider" \
        php "/var/www/html/$temporary_name/live-smoke.php"
)
