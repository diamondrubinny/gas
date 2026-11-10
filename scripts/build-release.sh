#!/usr/bin/env bash
# Build installable ZIPs (same layout as the original uploads) into dist/.
# Usage: scripts/build-release.sh
set -euo pipefail
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST="$REPO/dist"
mkdir -p "$DIST"

plugin_ver="$(sed -n "s/^define( 'GSM_VERSION', '\([^']*\)' );/\1/p" "$REPO/plugins/gas-safety-manager/gas-safety-manager.php")"
theme_ver="$(sed -n 's/^Version: *//p' "$REPO/themes/gas-safety-theme/style.css" | head -1)"
[ -n "$plugin_ver" ] && [ -n "$theme_ver" ] || { echo "Could not read versions"; exit 1; }

# Fail if the plugin header and the GSM_VERSION constant disagree.
header_ver="$(sed -n 's/^ \* Version: *//p' "$REPO/plugins/gas-safety-manager/gas-safety-manager.php" | head -1)"
[ "$header_ver" = "$plugin_ver" ] || { echo "Version mismatch: header $header_ver vs GSM_VERSION $plugin_ver"; exit 1; }

plugin_zip="$DIST/gas-safety-manager-v${plugin_ver}.zip"
theme_zip="$DIST/gas-safety-theme-v${theme_ver}.zip"
rm -f "$plugin_zip" "$theme_zip"

(cd "$REPO/plugins" && zip -qr "$plugin_zip" gas-safety-manager -x '*/.DS_Store' '*/node_modules/*')
(cd "$REPO/themes" && zip -qr "$theme_zip" gas-safety-theme -x '*/.DS_Store')

echo "Built:"
ls -l "$plugin_zip" "$theme_zip"
