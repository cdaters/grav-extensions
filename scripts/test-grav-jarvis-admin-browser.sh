#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/.." && pwd)"
ddev_project="${1:-${GRAV_JARVIS_DDEV_PROJECT:-/Users/cdaters/Documents/Spitfire/custom-plugins/file-vault-ddev}}"
base_url="${2:-${GRAV_JARVIS_BROWSER_BASE_URL:-https://spitfire-file-vault.ddev.site}}"
page_route="${3:-${GRAV_JARVIS_BROWSER_PAGE_ROUTE:-archive}}"
test_root="$repo_root/tests/grav-jarvis"
jarvis_source="$repo_root/plugins/grav-jarvis"
fixture_source="$test_root/fixtures/grav-jarvis-browser-fixture"
jarvis_target="$ddev_project/user/plugins/grav-jarvis"
fixture_target="$ddev_project/user/plugins/grav-jarvis-browser-fixture"
username="jvbrowser$(openssl rand -hex 3)"
account_target="$ddev_project/user/accounts/$username.yaml"
account_index="$ddev_project/user/data/flex/indexes/accounts.yaml"
notifications_target="$ddev_project/user/data/notifications"

if [[ ! -d "$ddev_project/.ddev" ]]; then
    echo "ERROR: Pass a disposable running DDEV Grav project." >&2
    exit 1
fi
for command_name in ddev node npm openssl; do
    if ! command -v "$command_name" >/dev/null 2>&1; then
        echo "ERROR: $command_name is required for the Jarvis browser regression." >&2
        exit 1
    fi
done

if [[ ! "$username" =~ ^jvbrowser[a-f0-9]{6}$ ]]; then
    echo "ERROR: Refusing an unexpected temporary account name." >&2
    exit 1
fi
[[ "$jarvis_target" == "$ddev_project/user/plugins/grav-jarvis" ]] || exit 1
[[ "$fixture_target" == "$ddev_project/user/plugins/grav-jarvis-browser-fixture" ]] || exit 1
[[ "$account_target" == "$ddev_project/user/accounts/$username.yaml" ]] || exit 1

backup_root="$(mktemp -d)"
password="$(openssl rand -base64 24 | tr -d '\n')Aa1!"
had_jarvis=0
had_fixture=0
had_account_index=0
had_notifications=0

cleanup() {
    rm -rf -- "$jarvis_target" "$fixture_target"
    rm -f -- "$account_target"
    if [[ "$had_jarvis" == 1 ]]; then mv "$backup_root/grav-jarvis" "$jarvis_target"; fi
    if [[ "$had_fixture" == 1 ]]; then mv "$backup_root/grav-jarvis-browser-fixture" "$fixture_target"; fi
    if [[ "$had_account_index" == 1 ]]; then
        cp "$backup_root/accounts-index.yaml" "$account_index"
    else
        rm -f -- "$account_index"
    fi
    if [[ "$had_notifications" == 1 ]]; then
        rm -rf -- "$notifications_target"
        cp -R "$backup_root/notifications" "$notifications_target"
    else
        rm -rf -- "$notifications_target"
    fi
    rm -rf -- "$backup_root"
    (cd "$ddev_project" && ddev mutagen sync >/dev/null 2>&1 && ddev exec bin/grav clearcache --quiet >/dev/null 2>&1) || true
}
trap cleanup EXIT

if [[ -d "$jarvis_target" ]]; then mv "$jarvis_target" "$backup_root/grav-jarvis"; had_jarvis=1; fi
if [[ -d "$fixture_target" ]]; then mv "$fixture_target" "$backup_root/grav-jarvis-browser-fixture"; had_fixture=1; fi
if [[ -f "$account_target" ]]; then echo "ERROR: Temporary account collision." >&2; exit 1; fi
if [[ -f "$account_index" ]]; then cp "$account_index" "$backup_root/accounts-index.yaml"; had_account_index=1; fi
if [[ -d "$notifications_target" ]]; then cp -R "$notifications_target" "$backup_root/notifications"; had_notifications=1; fi
cp -R "$jarvis_source" "$jarvis_target"
cp -R "$fixture_source" "$fixture_target"

if [[ ! -d "$test_root/node_modules/playwright-core" ]]; then
    npm ci --ignore-scripts --no-audit --no-fund --prefix "$test_root" >/dev/null
fi

(
    cd "$ddev_project"
    ddev mutagen sync >/dev/null
    ddev exec rm -rf -- /var/www/html/cache/grav-jarvis-browser-fixture
    ddev exec bin/plugin login new-user \
        --user="$username" \
        --password="$password" \
        --email="jarvis-browser-regression@example.invalid" \
        --permissions=b \
        --admin-type=api \
        --fullname="Jarvis Browser Regression" \
        --state=enabled \
        --no-interaction >/dev/null
    ddev exec bin/grav clearcache --quiet >/dev/null
)

GRAV_JARVIS_BROWSER_BASE_URL="$base_url" \
GRAV_JARVIS_BROWSER_USERNAME="$username" \
GRAV_JARVIS_BROWSER_PASSWORD="$password" \
GRAV_JARVIS_BROWSER_PAGE_ROUTE="$page_route" \
node "$test_root/admin-browser-regression.mjs"

log_file="$ddev_project/logs/grav.log"
if [[ -f "$log_file" ]] && tail -n 500 "$log_file" | rg -i 'jarvis.*(fatal|uncaught)|(?:fatal|uncaught).*jarvis' >/dev/null; then
    echo "ERROR: Jarvis-related fatal or uncaught errors were found in the recent Grav log." >&2
    exit 1
fi
echo "PASS: no recent Jarvis-related fatal or uncaught Grav log entries"
