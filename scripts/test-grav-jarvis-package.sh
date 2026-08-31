#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/.." && pwd)"
ddev_project="${1:-${GRAV_JARVIS_DDEV_PROJECT:-/Users/cdaters/Documents/Spitfire/custom-plugins/file-vault-ddev}}"
base_url="${2:-${GRAV_JARVIS_BROWSER_BASE_URL:-https://spitfire-file-vault.ddev.site}}"
old_package="$repo_root/dist/grav-jarvis-0.3.2.zip"
new_package="$repo_root/dist/grav-jarvis-0.3.3.zip"
target="$ddev_project/user/plugins/grav-jarvis"
config="$ddev_project/user/config/plugins/grav-jarvis.yaml"
data="$ddev_project/user/data/grav-jarvis"
fixture="$repo_root/tests/grav-jarvis/fixtures/package-config.yaml"

[[ -d "$ddev_project/.ddev" && -f "$old_package" && -f "$new_package" ]] || {
    echo "ERROR: DDEV fixture and both Jarvis packages are required." >&2
    exit 1
}
[[ "$target" == "$ddev_project/user/plugins/grav-jarvis" ]] || exit 1
[[ "$config" == "$ddev_project/user/config/plugins/grav-jarvis.yaml" ]] || exit 1
[[ "$data" == "$ddev_project/user/data/grav-jarvis" ]] || exit 1

backup="$(mktemp -d)"
stage="$(mktemp -d "$ddev_project/.grav-jarvis-package.XXXXXX")"
had_target=0; had_config=0; had_data=0
cleanup() {
    rm -rf -- "$target" "$data" "$stage"
    rm -f -- "$config"
    if [[ "$had_target" == 1 ]]; then mv "$backup/plugin" "$target"; fi
    if [[ "$had_config" == 1 ]]; then mkdir -p "$(dirname "$config")"; mv "$backup/config.yaml" "$config"; fi
    if [[ "$had_data" == 1 ]]; then mkdir -p "$(dirname "$data")"; mv "$backup/data" "$data"; fi
    rm -rf -- "$backup"
    (cd "$ddev_project" && ddev mutagen sync >/dev/null 2>&1 && ddev exec bin/grav clearcache --quiet >/dev/null 2>&1) || true
}
trap cleanup EXIT

if [[ -d "$target" ]]; then mv "$target" "$backup/plugin"; had_target=1; fi
if [[ -f "$config" ]]; then cp "$config" "$backup/config.yaml"; had_config=1; fi
if [[ -d "$data" ]]; then mv "$data" "$backup/data"; had_data=1; fi

mkdir -p "$stage/old" "$stage/new" "$(dirname "$config")"
unzip -q "$old_package" -d "$stage/old"
unzip -q "$new_package" -d "$stage/new"
cp -R "$stage/old/grav-jarvis" "$target"
cp "$fixture" "$config"
(
    cd "$ddev_project"
    ddev mutagen sync >/dev/null
    ddev exec bin/grav clearcache --quiet >/dev/null
)
rg -q '^version: 0\.3\.2$' "$target/blueprints.yaml"
rg -q '^  default_provider: anthropic$' "$config"

cp -R "$stage/new/grav-jarvis/." "$target/"
(
    cd "$ddev_project"
    ddev mutagen sync >/dev/null
    ddev exec bin/grav clearcache --quiet >/dev/null
)
rg -q '^version: 0\.3\.3$' "$target/blueprints.yaml"
rg -q '^  default_provider: anthropic$' "$config"
rg -q '^    default_model: package-upgrade-openai-model$' "$config"
echo "PASS: exact packaged 0.3.2 to 0.3.3 upgrade preserves site configuration"

public_status="$(curl -ksS -o /dev/null -w '%{http_code}' "$base_url/")"
api_status="$(curl -ksS -o /dev/null -w '%{http_code}' "$base_url/api/v1/grav-jarvis/bootstrap")"
[[ "$public_status" == 200 && "$api_status" == 401 ]]
echo "PASS: upgraded package public health and anonymous Jarvis API denial"

rm -rf -- "$target"
cp -R "$stage/new/grav-jarvis" "$target"
(
    cd "$ddev_project"
    ddev mutagen sync >/dev/null
    ddev exec bin/grav clearcache --quiet >/dev/null
)
rg -q '^version: 0\.3\.3$' "$target/blueprints.yaml"
public_status="$(curl -ksS -o /dev/null -w '%{http_code}' "$base_url/")"
[[ "$public_status" == 200 ]]
echo "PASS: fresh packaged 0.3.3 install and public health"

if unzip -Z1 "$new_package" | rg '(^|/)(\.ddev|\.env(?:\.|$)|user/data|[^/]+\.key$)' >/dev/null; then
    echo "ERROR: packaged Jarvis contains a local secret path." >&2
    exit 1
fi
echo "PASS: package contains no DDEV, environment, user-data, or master-key file"
