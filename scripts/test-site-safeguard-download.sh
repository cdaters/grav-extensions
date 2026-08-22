#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/.." && pwd)"
ddev_root="${1:-$PWD}"
helper_local="$repo_root/tests/site-safeguard/issue-download-ticket.php"

for command_name in ddev docker curl python3 shasum; do
    command -v "$command_name" >/dev/null 2>&1 || {
        echo "ERROR: $command_name is required." >&2
        exit 69
    }
done

[[ -f "$ddev_root/.ddev/config.yaml" ]] || {
    echo "ERROR: pass the root of a running DDEV Grav project." >&2
    exit 66
}

describe_json="$(cd "$ddev_root" && ddev describe -j)"
container_name="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["raw"]["services"]["web"]["full_name"])' "$describe_json")"
site_origin="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["raw"]["primary_url"].rstrip("/"))' "$describe_json")"
site_host="$(python3 -c 'import sys,urllib.parse; print(urllib.parse.urlparse(sys.argv[1]).netloc)' "$site_origin")"
helper_remote="/tmp/site-safeguard-black-box-$$.php"
temporary_dir="$(mktemp -d "${TMPDIR:-/tmp}/site-safeguard-black-box.XXXXXX")"

cleanup() {
    docker exec "$container_name" rm -f "$helper_remote" >/dev/null 2>&1 || true
    rm -rf "$temporary_dir"
}
trap cleanup EXIT

docker cp "$helper_local" "$container_name:$helper_remote" >/dev/null
ticket_json="$(docker exec -e SITE_SAFEGUARD_TEST_HOST="$site_host" -w /var/www/html "$container_name" php "$helper_remote")"

ticket="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["token"])' "$ticket_json")"
route="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["route"])' "$ticket_json")"
source_path="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["source_path"])' "$ticket_json")"
package_name="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["package"])' "$ticket_json")"
scope_independent="$(python3 -c 'import json,sys; print(str(json.loads(sys.argv[1])["scope_independent"]).lower())' "$ticket_json")"
host_bound="$(python3 -c 'import json,sys; print(str(json.loads(sys.argv[1])["host_bound"]).lower())' "$ticket_json")"

[[ "$route" == /* && "$route" != *://* ]] || {
    echo "ERROR: download route is not root-relative." >&2
    exit 1
}
[[ "$scope_independent" == "true" ]] || {
    echo "ERROR: signed ticket did not survive the environment-scope test." >&2
    exit 1
}
[[ "$host_bound" == "true" ]] || {
    echo "ERROR: signed ticket was not bound to its issuing host." >&2
    exit 1
}

download_url="${site_origin}${route}"
headers_head="$temporary_dir/head.headers"
headers_range="$temporary_dir/range.headers"
download_range="$temporary_dir/range.bin"
download_full="$temporary_dir/package.zip"

curl -ksS -I "$download_url" -o "$headers_head"
grep -Eq '^HTTP/[0-9.]+ 200([[:space:]]|$)' "$headers_head" || {
    echo "ERROR: HEAD request did not return HTTP 200." >&2
    exit 1
}

curl -ksS -H 'Range: bytes=0-127' -D "$headers_range" "$download_url" -o "$download_range"
grep -Eq '^HTTP/[0-9.]+ 206([[:space:]]|$)' "$headers_range" || {
    echo "ERROR: range request did not return HTTP 206." >&2
    exit 1
}
grep -Eiq '^accept-ranges:[[:space:]]*bytes' "$headers_range" || {
    echo "ERROR: range response omitted Accept-Ranges: bytes." >&2
    exit 1
}
grep -Eiq '^content-range:[[:space:]]*bytes 0-127/' "$headers_range" || {
    echo "ERROR: range response returned the wrong Content-Range." >&2
    exit 1
}
[[ "$(wc -c < "$download_range" | tr -d '[:space:]')" == "128" ]] || {
    echo "ERROR: range response did not contain exactly 128 bytes." >&2
    exit 1
}

curl -ksS "$download_url" -o "$download_full"
expected_sha="$(docker exec "$container_name" sha256sum "$source_path" | awk '{print $1}')"
actual_sha="$(shasum -a 256 "$download_full" | awk '{print $1}')"
[[ "$actual_sha" == "$expected_sha" ]] || {
    echo "ERROR: downloaded package SHA-256 does not match protected storage." >&2
    exit 1
}

tampered_url="${download_url}x"
tampered_status="$(curl -ksS -o "$temporary_dir/tampered.body" -D "$temporary_dir/tampered.headers" -w '%{http_code}' "$tampered_url")"
[[ "$tampered_status" == "403" ]] || {
    echo "ERROR: modified ticket was not denied with HTTP 403." >&2
    exit 1
}
grep -Eiq '^x-site-safeguard-error:[[:space:]]*SS-DL-01' "$temporary_dir/tampered.headers" || {
    echo "ERROR: modified ticket did not return SS-DL-01." >&2
    exit 1
}

echo "Site Safeguard black-box download contract passed."
echo "Origin: $site_origin"
echo "Package: $package_name"
echo "SHA-256: $actual_sha"
echo "Verified: same-origin route, host binding, environment scope, HEAD, range, full bytes, tamper denial"
