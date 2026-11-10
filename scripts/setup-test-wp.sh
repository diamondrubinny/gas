#!/usr/bin/env bash
# Create a disposable WordPress test site (SQLite, no MySQL needed) with the
# plugin and theme from this repo symlinked in, then start PHP's built-in
# server. Used by CI and for local end-to-end testing.
#
# Usage:
#   WP_TEST_PASS='choose-a-password' scripts/setup-test-wp.sh [target-dir] [port]
#
# Env:
#   WP_TEST_PASS   admin password for the test site (required)
#   WP_VERSION     WordPress version to install (default: latest)
#
# The site is for local testing only. Never point it at real data.
set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-$REPO/.wp-test}"
PORT="${2:-8899}"
WP_VERSION="${WP_VERSION:-latest}"
: "${WP_TEST_PASS:?Set WP_TEST_PASS (admin password for the throwaway test site)}"

mkdir -p "$TARGET"
TARGET="$(cd "$TARGET" && pwd)"
SITE="$TARGET/site"
CACHE="$TARGET/cache"
mkdir -p "$CACHE"

fetch() { # url dest
  [ -s "$2" ] || curl -fsSL --retry 3 -o "$2" "$1"
}

if [ "$WP_VERSION" = "latest" ]; then
  WP_URL="https://wordpress.org/latest.tar.gz"
else
  WP_URL="https://wordpress.org/wordpress-${WP_VERSION}.tar.gz"
fi
fetch "$WP_URL" "$CACHE/wordpress-${WP_VERSION}.tar.gz"
fetch "https://downloads.wordpress.org/plugin/sqlite-database-integration.zip" "$CACHE/sqlite.zip"
fetch "https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar" "$CACHE/wp-cli.phar"

WP="php $CACHE/wp-cli.phar --path=$SITE --allow-root --quiet"

if [ ! -f "$SITE/wp-config.php" ]; then
  mkdir -p "$SITE"
  tar xzf "$CACHE/wordpress-${WP_VERSION}.tar.gz" -C "$SITE" --strip-components=1
  unzip -q -o "$CACHE/sqlite.zip" -d "$SITE/wp-content/plugins/"
  SQLITE_DIR="$SITE/wp-content/plugins/sqlite-database-integration"
  sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$SQLITE_DIR#" \
      -e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
      "$SQLITE_DIR/db.copy" > "$SITE/wp-content/db.php"
  ln -sfn "$REPO/plugins/gas-safety-manager" "$SITE/wp-content/plugins/gas-safety-manager"
  ln -sfn "$REPO/themes/gas-safety-theme" "$SITE/wp-content/themes/gas-safety-theme"

  $WP config create --dbname=wp --dbuser=unused --dbpass=unused --skip-check
  # Keep the site exactly at WP_VERSION and free of background work: an
  # outdated core (e.g. 6.4.5) otherwise auto-updates itself from WP-Cron in
  # the middle of a test run, blocking the server for a minute.
  $WP config set AUTOMATIC_UPDATER_DISABLED true --raw
  $WP config set WP_AUTO_UPDATE_CORE false --raw
  $WP config set DISABLE_WP_CRON true --raw
  $WP core install --url="http://127.0.0.1:$PORT" --title="Gas Safety Test" \
    --admin_user=admin --admin_password="$WP_TEST_PASS" \
    --admin_email=admin@example.test --skip-email
  $WP rewrite structure '/%postname%/'
  $WP theme activate gas-safety-theme
  $WP plugin activate gas-safety-manager
fi

# Router for PHP's built-in server (pretty permalinks + static files).
cat > "$TARGET/router.php" <<'PHP'
<?php
$root = $_SERVER['DOCUMENT_ROOT'];
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
if ( '/' !== $path && is_file( $root . $path ) ) {
	return false;
}
if ( is_dir( $root . $path ) && is_file( $root . rtrim( $path, '/' ) . '/index.php' ) ) {
	require $root . rtrim( $path, '/' ) . '/index.php';
	return;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require $root . '/index.php';
PHP

if curl -fs -o /dev/null "http://127.0.0.1:$PORT/wp-login.php"; then
  echo "Server already running on port $PORT"
else
  # Several workers, so one slow request cannot block the others.
  PHP_CLI_SERVER_WORKERS=4 nohup php -S "127.0.0.1:$PORT" -t "$SITE" "$TARGET/router.php" > "$TARGET/server.log" 2>&1 &
  for _ in $(seq 1 30); do
    curl -fs -o /dev/null "http://127.0.0.1:$PORT/wp-login.php" && break
    sleep 0.5
  done
fi

curl -fs -o /dev/null "http://127.0.0.1:$PORT/gas-app/" || { echo "App page not reachable"; tail -20 "$TARGET/server.log"; exit 1; }
actual="$($WP core version)"
if [ "$WP_VERSION" != "latest" ] && [ "$actual" != "$WP_VERSION" ]; then
  echo "Expected WordPress $WP_VERSION but found $actual"; exit 1
fi
echo "WordPress $actual ready at http://127.0.0.1:$PORT  (site: $SITE)"
