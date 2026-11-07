#!/usr/bin/env bash
# A local WordPress for testing the plugin: SQLite instead of MySQL, Elementor and Hello
# Elementor, SHD Performance from this repository (symlinked, so edits apply at once).
#
#   bash tools/perf-lab/local-wp/setup.sh <folder>           (for example $SCRATCH/wp)
#   then: PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8088 -t <folder> tools/perf-lab/local-wp/router.php
#   admin: http://127.0.0.1:8088/wp-admin/  user admin / password admin (local only)
#
# Needs PHP 8.2+ with sqlite3, pdo_sqlite, gd, mbstring, xml, curl, zip, intl; network access
# to wordpress.org, downloads.wordpress.org and raw.githubusercontent.com (WP-CLI).
# Add the site's other plugins (Elementor Pro, add-ons) from their zip files when tests need them.
set -euo pipefail
W="$(realpath -m "${1:?folder for the local WordPress}")"
REPO="$(cd "$(dirname "$0")/../../.." && pwd)"
LAB="$REPO/tools/perf-lab"
mkdir -p "$W" "$LAB/work/bin"
CLI="$LAB/work/bin/wp-cli.phar"
[ -f "$CLI" ] || curl -sSL --fail -o "$CLI" https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
WP="php $CLI --allow-root"
$WP core download --path="$W" --quiet --skip-content
mkdir -p "$W/wp-content/plugins" "$W/wp-content/themes" "$W/wp-content/mu-plugins"
curl -sSL --fail -o "$W/sqlite.zip" https://downloads.wordpress.org/plugin/sqlite-database-integration.latest-stable.zip
python3 -I -c "import zipfile,sys; zipfile.ZipFile(sys.argv[1]).extractall(sys.argv[2])" "$W/sqlite.zip" "$W/wp-content/plugins/"
rm "$W/sqlite.zip"
sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$W/wp-content/plugins/sqlite-database-integration#" \
	-e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
	"$W/wp-content/plugins/sqlite-database-integration/db.copy" > "$W/wp-content/db.php"
$WP config create --path="$W" --dbname=wp --dbuser=wp --dbpass=wp --dbhost=localhost --skip-check --quiet --extra-php <<'PHP'
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_ENVIRONMENT_TYPE', 'local' );
PHP
$WP core install --path="$W" --url=http://127.0.0.1:8088 --title="Local test" --admin_user=admin --admin_password=admin --admin_email=test@example.invalid --skip-email --quiet
$WP plugin install elementor --activate --path="$W" --quiet
$WP theme install hello-elementor --activate --path="$W" --quiet
$WP rewrite structure '/%postname%/' --path="$W" --quiet
ln -sfn "$REPO/shd-performance" "$W/wp-content/plugins/shd-performance"
cp "$LAB/local-wp/mu-plugins/site-mirror.php" "$W/wp-content/mu-plugins/"
$WP plugin activate shd-performance --path="$W" --quiet
echo "Local WordPress ready in $W"
echo "Start:  PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8088 -t $W $LAB/local-wp/router.php"
