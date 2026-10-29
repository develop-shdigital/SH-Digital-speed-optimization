#!/usr/bin/env bash
# Build the installable plugin zip.
#
#   tools/build.sh
#
# 1. Minifies the inline front-end scripts (assets/js/*.js -> assets/js/min/*.min.js)
#    with terser; the plugin falls back to the readable files when no build exists.
# 2. Writes dist/shd-performance-<version>.zip, ready for Plugins → Add New → Upload.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PLUGIN="$ROOT/shd-performance"
TERSER="${TERSER:-npx --yes terser@5}"

mkdir -p "$PLUGIN/assets/js/min"
for src in "$PLUGIN"/assets/js/*.js; do
	name="$(basename "$src" .js)"
	$TERSER "$src" --compress --mangle --comments false --output "$PLUGIN/assets/js/min/$name.min.js"
	echo "minified $name.js: $(wc -c < "$src") -> $(wc -c < "$PLUGIN/assets/js/min/$name.min.js") bytes"
done

for php in $(find "$PLUGIN" -name '*.php'); do
	php -l "$php" > /dev/null
done

version="$(sed -n 's/^ \* Version: *//p' "$PLUGIN/shd-performance.php" | head -1 | tr -d '[:space:]')"
mkdir -p "$ROOT/dist"
zip_path="$ROOT/dist/shd-performance-$version.zip"
rm -f "$zip_path"
(cd "$ROOT" && zip -qr "$zip_path" shd-performance -x '*.DS_Store' -x '*/node_modules/*')
echo "built $zip_path ($(wc -c < "$zip_path") bytes)"
