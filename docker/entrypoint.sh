#!/bin/sh
set -e
DEST=/var/www/html
mkdir -p "$DEST/data"

# Старые установки хранили config.php в корне; новый путь — data/config.php.
# Переносим, если новый ещё не создан, чтобы установка не сбрасывалась после обновления образа.
if [ ! -f "$DEST/data/config.php" ] && [ -f "$DEST/config.php" ]; then
  cp "$DEST/config.php" "$DEST/data/config.php" 2>/dev/null && echo "submw: перенёс config.php в data/"
fi

if [ ! -f "$DEST/data/config.php" ] && [ ! -f "$DEST/data/install.json" ]; then
  cat > "$DEST/data/install.json" <<JSON
{"target_domain":"","mirror_domain":"${SUBMW_DOMAIN:-}","mode":"panel","remnawave_url":"${SUBMW_PANEL_URL:-http://remnawave:3000}","subpage_external_url":"${SUBMW_SUBPAGE_URL:-http://remnawave-subscription-page:3010}","db":{"driver":"sqlite","path":"${DEST}/data/submw.sqlite"}}
JSON
fi
chown -R www-data:www-data "$DEST/data"

# Запускаем php-fpm и nginx под простым присмотром: если любой из них падает,
# выходим с ошибкой, чтобы docker (restart) перезапустил контейнер, а не висел с 502.
php-fpm -F &
FPM=$!
nginx -g 'daemon off;' &
NGINX=$!

while kill -0 "$FPM" 2>/dev/null && kill -0 "$NGINX" 2>/dev/null; do
  sleep 5
done

echo "submw: один из процессов завершился (fpm=$FPM nginx=$NGINX), выхожу для перезапуска контейнера" >&2
kill "$FPM" "$NGINX" 2>/dev/null || true
exit 1
