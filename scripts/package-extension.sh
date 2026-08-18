#!/usr/bin/env bash
set -euo pipefail

usage() {
    echo "Usage: $0 <plugin|theme> <slug>" >&2
    exit 64
}

[[ $# -eq 2 ]] || usage

extension_type="$1"
extension_slug="$2"

case "$extension_type" in
    plugin) source_group="plugins" ;;
    theme) source_group="themes" ;;
    *) usage ;;
esac

if [[ ! "$extension_slug" =~ ^[a-z0-9][a-z0-9-]*$ ]]; then
    echo "Invalid extension slug: $extension_slug" >&2
    exit 64
fi

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/.." && pwd)"
source_dir="$repo_root/$source_group/$extension_slug"
blueprint="$source_dir/blueprints.yaml"

[[ -d "$source_dir" ]] || {
    echo "Extension not found: $source_dir" >&2
    exit 66
}

[[ -f "$blueprint" ]] || {
    echo "Missing blueprints.yaml: $source_dir" >&2
    exit 65
}

version="$(awk '
    /^version:[[:space:]]*/ {
        value = $0
        sub(/^version:[[:space:]]*/, "", value)
        gsub(/["'\''[:space:]]/, "", value)
        print value
        exit
    }
' "$blueprint")"

[[ -n "$version" ]] || {
    echo "Could not read a top-level version from $blueprint" >&2
    exit 65
}

command -v zip >/dev/null 2>&1 || {
    echo "The zip command is required." >&2
    exit 69
}

output_dir="$repo_root/dist"
output_file="$output_dir/${extension_slug}-${version}.zip"
mkdir -p "$output_dir"

temporary_dir="$(mktemp -d "${TMPDIR:-/tmp}/grav-extension.XXXXXX")"
trap 'rm -rf "$temporary_dir"' EXIT

mkdir -p "$temporary_dir/$extension_slug"

tar \
    --exclude='.git' \
    --exclude='.git/*' \
    --exclude='.DS_Store' \
    --exclude='._*' \
    --exclude='__MACOSX' \
    --exclude='.ddev' \
    --exclude='node_modules' \
    --exclude='user/data' \
    --exclude='user/accounts' \
    --exclude='file-vault-files' \
    -C "$source_dir" -cf - . | tar -C "$temporary_dir/$extension_slug" -xf -

if find "$temporary_dir" -type f \( \
    -name '.env' -o -name '.env.*' -o -name '*.pem' -o -name '*.key' \
    -o -name '*.p12' -o -name '*.pfx' -o -name 'auth.json' \
\) -print -quit | grep -q .; then
    echo "Refusing to package a possible credential file." >&2
    exit 78
fi

rm -f "$output_file"
(
    cd "$temporary_dir"
    zip -q -r "$output_file" "$extension_slug"
)

if command -v shasum >/dev/null 2>&1; then
    shasum -a 256 "$output_file"
else
    echo "Created $output_file"
fi
