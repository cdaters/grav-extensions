#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/.." && pwd)"
ddev_root="${1:-$PWD}"
expected_state="${2:-enabled}"
helper_local="$repo_root/tests/site-safeguard/settings-contract.php"

for command_name in ddev docker python3; do
    command -v "$command_name" >/dev/null 2>&1 || {
        echo "ERROR: $command_name is required." >&2
        exit 69
    }
done

[[ -f "$ddev_root/.ddev/config.yaml" ]] || {
    echo "ERROR: pass the root of a running DDEV Grav project." >&2
    exit 66
}

case "$expected_state" in
    enabled) expected_restore=true ;;
    disabled) expected_restore=false ;;
    *)
        echo "ERROR: expected state must be enabled or disabled." >&2
        exit 64
        ;;
esac

describe_json="$(cd "$ddev_root" && ddev describe -j)"
container_name="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["raw"]["services"]["web"]["full_name"])' "$describe_json")"
helper_remote="/tmp/site-safeguard-settings-contract-$$.php"

cleanup() {
    docker exec "$container_name" rm -f "$helper_remote" >/dev/null 2>&1 || true
}
trap cleanup EXIT

docker cp "$helper_local" "$container_name:$helper_remote" >/dev/null
result="$(docker exec \
    -e SITE_SAFEGUARD_EXPECT_RESTORE="$expected_restore" \
    -w /var/www/html \
    "$container_name" php "$helper_remote")"

echo "Site Safeguard settings contract passed."
echo "$result"
