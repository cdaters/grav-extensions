#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/.." && pwd)"
ddev_project="${1:-${GRAV_CAXTON_DDEV_PROJECT:-/Users/cdaters/Documents/Spitfire/custom-plugins/file-vault-ddev}}"
base_url="${2:-${GRAV_CAXTON_BROWSER_BASE_URL:-https://spitfire-file-vault.ddev.site}}"
plugin_source="$repo_root/plugins/grav-caxton"
plugin_target="$ddev_project/user/plugins/grav-caxton"
jarvis_fixture_source="$repo_root/tests/grav-jarvis/fixtures/grav-jarvis-browser-fixture"
jarvis_fixture_target="$ddev_project/user/plugins/grav-jarvis-browser-fixture"
test_root="$repo_root/tests/grav-caxton"
suffix="$(openssl rand -hex 3)"
username="cxbrowser$suffix"
route="caxton-browser-$suffix"
page_target="$ddev_project/user/pages/99.$route"
account_target="$ddev_project/user/accounts/$username.yaml"
account_index="$ddev_project/user/data/flex/indexes/accounts.yaml"
pages_index="$ddev_project/user/data/flex/indexes/pages.json"
notifications_target="$ddev_project/user/data/notifications"
backup_root="$(mktemp -d)"
password="$(openssl rand -base64 24 | tr -d '\n')Aa1!"
had_plugin=0
had_jarvis_fixture=0
had_account_index=0
had_pages_index=0
had_notifications=0

[[ -d "$ddev_project/.ddev" ]] || { echo "ERROR: Pass a disposable running DDEV Grav project." >&2; exit 1; }
[[ "$plugin_target" == "$ddev_project/user/plugins/grav-caxton" ]] || exit 1
[[ "$jarvis_fixture_target" == "$ddev_project/user/plugins/grav-jarvis-browser-fixture" ]] || exit 1
[[ "$page_target" == "$ddev_project/user/pages/99.caxton-browser-"* ]] || exit 1
[[ "$account_target" == "$ddev_project/user/accounts/cxbrowser"*.yaml ]] || exit 1

cleanup() {
    rm -rf -- "$plugin_target" "$jarvis_fixture_target" "$page_target"
    rm -f -- "$account_target"
    if [[ "$had_plugin" == 1 ]]; then mv "$backup_root/grav-caxton" "$plugin_target"; fi
    if [[ "$had_jarvis_fixture" == 1 ]]; then mv "$backup_root/grav-jarvis-browser-fixture" "$jarvis_fixture_target"; fi
    if [[ "$had_account_index" == 1 ]]; then cp "$backup_root/accounts-index.yaml" "$account_index"; else rm -f -- "$account_index"; fi
    if [[ "$had_pages_index" == 1 ]]; then cp "$backup_root/pages-index.json" "$pages_index"; else rm -f -- "$pages_index"; fi
    if [[ "$had_notifications" == 1 ]]; then
        rm -rf -- "$notifications_target"
        cp -R "$backup_root/notifications" "$notifications_target"
    else
        rm -rf -- "$notifications_target"
    fi
    rm -rf -- "$backup_root"
    (
        cd "$ddev_project"
        ddev mutagen sync >/dev/null 2>&1
        ddev exec rm -rf -- "/var/www/html/user/pages/99.$route" "/var/www/html/user/accounts/$username.yaml"
        if [[ "$had_account_index" == 0 ]]; then ddev exec rm -f -- /var/www/html/user/data/flex/indexes/accounts.yaml; fi
        if [[ "$had_pages_index" == 0 ]]; then ddev exec rm -f -- /var/www/html/user/data/flex/indexes/pages.json; fi
        ddev mutagen sync >/dev/null 2>&1
        ddev exec bin/grav clearcache --quiet >/dev/null 2>&1
    ) || true
    rm -rf -- "$page_target"
    rm -f -- "$account_target"
}
trap cleanup EXIT

if [[ -d "$plugin_target" ]]; then mv "$plugin_target" "$backup_root/grav-caxton"; had_plugin=1; fi
if [[ -d "$jarvis_fixture_target" ]]; then mv "$jarvis_fixture_target" "$backup_root/grav-jarvis-browser-fixture"; had_jarvis_fixture=1; fi
if [[ -f "$account_index" ]]; then cp "$account_index" "$backup_root/accounts-index.yaml"; had_account_index=1; fi
if [[ -f "$pages_index" ]]; then cp "$pages_index" "$backup_root/pages-index.json"; had_pages_index=1; fi
if [[ -d "$notifications_target" ]]; then cp -R "$notifications_target" "$backup_root/notifications"; had_notifications=1; fi
cp -R "$plugin_source" "$plugin_target"
cp -R "$jarvis_fixture_source" "$jarvis_fixture_target"
mkdir -p "$page_target"
printf '%s' $'---\ntitle: Caxton Browser Fixture\npublished: true\nvisible: false\n---\n## Visible heading\n\nPlain **bold** and *italic*.\n\n{% protected %}\n' > "$page_target/default.md"
printf '%s' 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=' | base64 --decode > "$page_target/fixture.png"

(
    cd "$ddev_project"
    ddev mutagen sync >/dev/null
    ddev exec bin/plugin login new-user \
        --user="$username" --password="$password" \
        --email="caxton-browser-regression@example.invalid" \
        --permissions=b --admin-type=api --fullname="Caxton Browser Regression" \
        --state=enabled --no-interaction >/dev/null
    ddev exec bin/grav clearcache --quiet >/dev/null
)

GRAV_CAXTON_BROWSER_BASE_URL="$base_url" \
GRAV_CAXTON_BROWSER_USERNAME="$username" \
GRAV_CAXTON_BROWSER_PASSWORD="$password" \
GRAV_CAXTON_BROWSER_PAGE_ROUTE="$route" \
GRAV_CAXTON_BROWSER_SCREENSHOT="${GRAV_CAXTON_BROWSER_SCREENSHOT:-/tmp/caxton-admin2-regression.png}" \
node "$test_root/admin-browser-regression.mjs"

log_file="$ddev_project/logs/grav.log"
if [[ -f "$log_file" ]] && tail -n 500 "$log_file" | rg -i 'caxton.*(fatal|uncaught)|(?:fatal|uncaught).*caxton' >/dev/null; then
    echo "ERROR: Caxton-related fatal or uncaught errors were found in the recent Grav log." >&2
    exit 1
fi
echo "PASS: no recent Caxton-related fatal or uncaught Grav log entries"
