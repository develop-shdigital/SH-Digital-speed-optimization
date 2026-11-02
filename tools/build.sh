#!/usr/bin/env bash
# Build the installable plugin zips.
#
#   tools/build.sh
#
# For each plugin (shd-performance, shd-consent):
# 1. Minifies the inline front-end scripts (assets/js/*.js -> assets/js/min/*.min.js)
#    with terser; the plugins fall back to the readable files when no build exists.
# 2. Writes dist/<plugin>-<version>.zip, ready for Plugins → Add New → Upload.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TERSER="${TERSER:-npx --yes terser@5}"
mkdir -p "$ROOT/dist"

for slug in shd-performance shd-consent; do
	PLUGIN="$ROOT/$slug"
	mkdir -p "$PLUGIN/assets/js/min"
	for src in "$PLUGIN"/assets/js/*.js; do
		name="$(basename "$src" .js)"
		$TERSER "$src" --compress --mangle --comments false --output "$PLUGIN/assets/js/min/$name.min.js"
		echo "$slug: minified $name.js: $(wc -c < "$src") -> $(wc -c < "$PLUGIN/assets/js/min/$name.min.js") bytes"
	done

	for php in $(find "$PLUGIN" -name '*.php'); do
		php -l "$php" > /dev/null
	done

	version="$(sed -n 's/^ \* Version: *//p' "$PLUGIN/$slug.php" | head -1 | tr -d '[:space:]')"
	zip_path="$ROOT/dist/$slug-$version.zip"
	rm -f "$zip_path"
	(cd "$ROOT" && zip -qr "$zip_path" "$slug" -x '*.DS_Store' -x '*/node_modules/*')
	echo "built $zip_path ($(wc -c < "$zip_path") bytes)"
done
