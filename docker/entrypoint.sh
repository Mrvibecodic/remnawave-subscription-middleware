#!/bin/sh
set -e
DEST=/var/www/html
mkdir -p "$DEST/data"
if [ ! -f "$DEST/data/config.php" ] && [ ! -f "$DEST/data/install.json" ]; then
  cat > "$DEST/data/install.json" <<JSON
{"target_domain":"","mirror_domain":"${SUBMW_DOMAIN:-}","mode":"panel","remnawave_url":"${SUBMW_PANEL_URL:-http://remnawave:3000}","subpage_external_url":"${SUBMW_SUBPAGE_URL:-http://remnawave-subscription-page:3010}","db":{"driver":"sqlite","path":"${DEST}/data/submw.sqlite"}}
JSON
fi
chown -R www-data:www-data "$DEST/data"
php-fpm -D
exec nginx -g 'daemon off;'
