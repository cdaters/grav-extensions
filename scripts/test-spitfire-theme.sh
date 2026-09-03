#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/.." && pwd)"
theme_root="$repo_root/themes/spitfire"

required_files=(
    "blueprints.yaml"
    "spitfire.php"
    "spitfire.yaml"
    "css/custom.css"
    "js/doc-sidebar.js"
    "templates/default.html.twig"
    "templates/partials/navigation.html.twig"
    "templates/macros/spitfire-navigation.html.twig"
    "templates/macros/spitfire-doc-navigation.html.twig"
    "templates/macros/spitfire-archive-doc-navigation.html.twig"
    "templates/macros/spitfire-icons.html.twig"
)

for relative_path in "${required_files[@]}"; do
    [[ -f "$theme_root/$relative_path" ]] || {
        echo "ERROR: missing theme file: $relative_path" >&2
        exit 1
    }
done

ruby -rpsych -e 'Psych.parse_file(ARGV.fetch(0))' "$theme_root/blueprints.yaml"
node --check "$theme_root/js/doc-sidebar.js"

ruby -e '
  css = File.read(ARGV.fetch(0))
  abort "ERROR: unbalanced CSS braces" unless css.count("{") == css.count("}")
' "$theme_root/css/custom.css"

if grep -RIn --include='*.twig' 'workshop_icon' "$theme_root/templates"; then
    echo "ERROR: the theme must not require Site Workshop for documentation icons" >&2
    exit 1
fi

if grep -RInE '/Users/|\.ddev|spitfire-file-vault\.ddev\.site' "$theme_root"; then
    echo "ERROR: host- or DDEV-specific path found in reusable theme source" >&2
    exit 1
fi

grep -q '<details class="sfng-doc-group"' "$theme_root/templates/macros/spitfire-doc-navigation.html.twig"
grep -q '<summary class="sfng-doc-group-toggle' "$theme_root/templates/macros/spitfire-doc-navigation.html.twig"
grep -q 'aria-current="page"' "$theme_root/templates/default.html.twig"

echo "Spitfire theme source checks passed."
