#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/.." && pwd)"
ddev_project="${1:-${GRAV_COMMANDER_DDEV_PROJECT:-/Users/cdaters/Documents/Spitfire/custom-plugins/file-vault-ddev}}"
base_url="${2:-${GRAV_COMMANDER_BROWSER_BASE_URL:-https://spitfire-file-vault.ddev.site}}"
commander_source="$repo_root/plugins/grav-commander"
jarvis_source="$repo_root/plugins/grav-jarvis"
fixture_source="$repo_root/tests/grav-jarvis/fixtures/grav-jarvis-browser-fixture"
browser_test="$repo_root/tests/grav-commander/jarvis-browser-regression.mjs"
page_fixture="$repo_root/tests/grav-commander/fixtures/fixture.md"
commander_target="$ddev_project/user/plugins/grav-commander"
jarvis_target="$ddev_project/user/plugins/grav-jarvis"
fixture_target="$ddev_project/user/plugins/grav-jarvis-browser-fixture"
page_target="$ddev_project/user/pages/jarvis-commander-fixture"
username="gcbrowser$(openssl rand -hex 3)"
account_target="$ddev_project/user/accounts/$username.yaml"
account_index="$ddev_project/user/data/flex/indexes/accounts.yaml"
notifications_target="$ddev_project/user/data/notifications"

[[ -d "$ddev_project/.ddev" ]] || { echo "ERROR: Pass a disposable running DDEV Grav project." >&2; exit 1; }
for command_name in ddev node npm openssl; do command -v "$command_name" >/dev/null || { echo "ERROR: $command_name is required." >&2; exit 1; }; done
[[ "$username" =~ ^gcbrowser[a-f0-9]{6}$ ]] || exit 1
[[ ! -e "$page_target" ]] || { echo "ERROR: Disposable page fixture already exists." >&2; exit 1; }

backup_root="$(mktemp -d)"
password="$(openssl rand -base64 24 | tr -d '\n')Aa1!"
had_commander=0; had_jarvis=0; had_fixture=0; had_account_index=0; had_notifications=0

cleanup() {
    rm -rf -- "$commander_target" "$jarvis_target" "$fixture_target" "$page_target"
    rm -f -- "$account_target"
    [[ "$had_commander" == 1 ]] && mv "$backup_root/grav-commander" "$commander_target"
    [[ "$had_jarvis" == 1 ]] && mv "$backup_root/grav-jarvis" "$jarvis_target"
    [[ "$had_fixture" == 1 ]] && mv "$backup_root/grav-jarvis-browser-fixture" "$fixture_target"
    if [[ "$had_account_index" == 1 ]]; then cp "$backup_root/accounts-index.yaml" "$account_index"; else rm -f -- "$account_index"; fi
    if [[ "$had_notifications" == 1 ]]; then
        rm -rf -- "$notifications_target"; cp -R "$backup_root/notifications" "$notifications_target"
    else
        rm -rf -- "$notifications_target"
    fi
    rm -rf -- "$backup_root"
    (cd "$ddev_project" && ddev mutagen sync >/dev/null 2>&1 && ddev exec bin/grav clearcache --quiet >/dev/null 2>&1) || true
}
trap cleanup EXIT

if [[ -d "$commander_target" ]]; then mv "$commander_target" "$backup_root/grav-commander"; had_commander=1; fi
if [[ -d "$jarvis_target" ]]; then mv "$jarvis_target" "$backup_root/grav-jarvis"; had_jarvis=1; fi
if [[ -d "$fixture_target" ]]; then mv "$fixture_target" "$backup_root/grav-jarvis-browser-fixture"; had_fixture=1; fi
[[ ! -f "$account_target" ]] || exit 1
if [[ -f "$account_index" ]]; then cp "$account_index" "$backup_root/accounts-index.yaml"; had_account_index=1; fi
if [[ -d "$notifications_target" ]]; then cp -R "$notifications_target" "$backup_root/notifications"; had_notifications=1; fi

cp -R "$commander_source" "$commander_target"
cp -R "$jarvis_source" "$jarvis_target"
cp -R "$fixture_source" "$fixture_target"
mkdir -p "$page_target"
cp "$page_fixture" "$page_target/fixture.md"

if [[ ! -d "$repo_root/tests/grav-jarvis/node_modules/playwright-core" ]]; then
    npm ci --ignore-scripts --no-audit --no-fund --prefix "$repo_root/tests/grav-jarvis" >/dev/null
fi

(
    cd "$ddev_project"
    ddev mutagen sync >/dev/null
    ddev exec bin/plugin login new-user \
        --user="$username" --password="$password" \
        --email="commander-jarvis-browser@example.invalid" \
        --permissions=b --admin-type=api --fullname="Commander Jarvis Browser" \
        --state=enabled --no-interaction >/dev/null
    ddev exec bin/grav clearcache --quiet >/dev/null
)

GRAV_COMMANDER_BROWSER_BASE_URL="$base_url" \
GRAV_COMMANDER_BROWSER_USERNAME="$username" \
GRAV_COMMANDER_BROWSER_PASSWORD="$password" \
node "$browser_test"

mv "$jarvis_target" "$backup_root/active-jarvis-for-absence"
mv "$fixture_target" "$backup_root/active-fixture-for-absence"
(
    cd "$ddev_project"
    ddev mutagen sync >/dev/null
    ddev exec bin/grav clearcache --quiet >/dev/null
)
GRAV_COMMANDER_BROWSER_MODE=absence \
GRAV_COMMANDER_BROWSER_BASE_URL="$base_url" \
GRAV_COMMANDER_BROWSER_USERNAME="$username" \
GRAV_COMMANDER_BROWSER_PASSWORD="$password" \
node "$browser_test"
mv "$backup_root/active-jarvis-for-absence" "$jarvis_target"
mv "$backup_root/active-fixture-for-absence" "$fixture_target"

log_file="$ddev_project/logs/grav.log"
if [[ -f "$log_file" ]] && tail -n 500 "$log_file" | rg -i '(commander|jarvis).*(fatal|uncaught)|(?:fatal|uncaught).*(commander|jarvis)' >/dev/null; then
    echo "ERROR: Commander/Jarvis fatal or uncaught errors were found in the recent Grav log." >&2
    exit 1
fi
echo "PASS: no recent Commander/Jarvis fatal or uncaught Grav log entries"
