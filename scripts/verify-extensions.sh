#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/.." && pwd)"
failures=0

report_failure() {
    echo "ERROR: $*" >&2
    failures=$((failures + 1))
}

check_extension() {
    local extension_type="$1"
    local extension_dir="$2"
    local slug
    slug="$(basename "$extension_dir")"

    [[ -f "$extension_dir/blueprints.yaml" ]] || report_failure "$slug is missing blueprints.yaml"
    [[ -f "$extension_dir/README.md" ]] || report_failure "$slug is missing README.md"

    case "$extension_type" in
        plugin)
            [[ -f "$extension_dir/$slug.php" ]] || report_failure "$slug is missing $slug.php"
            ;;
        theme)
            [[ -f "$extension_dir/$slug.php" ]] || report_failure "$slug is missing $slug.php"
            ;;
    esac
}

while IFS= read -r -d '' plugin_dir; do
    check_extension plugin "$plugin_dir"
done < <(find "$repo_root/plugins" -mindepth 1 -maxdepth 1 -type d -print0 | sort -z)

while IFS= read -r -d '' theme_dir; do
    check_extension theme "$theme_dir"
done < <(find "$repo_root/themes" -mindepth 1 -maxdepth 1 -type d -print0 | sort -z)

for forbidden in \
    '.DS_Store' '._*' '__MACOSX' '.env' '.env.*' '*.pem' '*.key' '*.p12' '*.pfx' \
    'activity.json' 'catalog.json' 'stats.json' 'signing.key'; do
    while IFS= read -r found; do
        [[ -z "$found" ]] || report_failure "forbidden runtime or secret file: ${found#$repo_root/}"
    done < <(find "$repo_root/plugins" "$repo_root/themes" -name "$forbidden" -print)
done

while IFS= read -r private_permission; do
    [[ -z "$private_permission" ]] || report_failure "API permission guards must not privately override Grav's protected method: ${private_permission#$repo_root/}"
done < <(grep -RIl --include='*.php' -E 'private[[:space:]]+function[[:space:]]+requirePermission[[:space:]]*\(' "$repo_root/plugins" "$repo_root/themes" || true)

if command -v php >/dev/null 2>&1; then
    while IFS= read -r -d '' php_file; do
        php -l "$php_file" >/dev/null || report_failure "PHP syntax: ${php_file#$repo_root/}"
    done < <(find "$repo_root/plugins" "$repo_root/themes" -type f -name '*.php' -print0)
else
    echo "NOTICE: PHP is not installed on the host; PHP syntax checks were skipped."
fi

if (( failures > 0 )); then
    echo "$failures validation failure(s)." >&2
    exit 1
fi

echo "Extension structure and repository hygiene checks passed."
