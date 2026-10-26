#!/bin/sh
set -e
DEST=/var/www/html
mkdir -p "$DEST/data"

if [ ! -f "$DEST/data/config.php" ] && [ -f "$DEST/config.php" ]; then
  cp "$DEST/config.php" "$DEST/data/config.php" 2>/dev/null && echo "submw: перенёс config.php в data/"
fi

# Первый старт: заготовка для мастера установки. Дальше всё живёт в БД, env больше не читается.
if [ ! -f "$DEST/data/config.php" ] && [ ! -f "$DEST/data/install.json" ]; then
  KEY="k$(head -c 64 /dev/urandom | tr -dc 'a-z0-9' | head -c 7)=$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n' | head -c 24)"
  SCEN="${SUBMW_SCENARIO:-panel}"
  if [ "$SCEN" = "panel" ]; then
    PANEL_URL="${SUBMW_PANEL_URL:-http://remnawave:3000}"
    SUBPAGE_URL="${SUBMW_SUBPAGE_URL:-http://remnawave-subscription-page:3010}"
  else
    PANEL_URL="${SUBMW_PANEL_URL:-}"
    SUBPAGE_URL="${SUBMW_SUBPAGE_URL:-http://remnawave-subscription-page:3010}"
  fi
  if [ -n "${SUBMW_DB_HOST:-}" ]; then
    DB="{\"driver\":\"mysql\",\"host\":\"${SUBMW_DB_HOST}\",\"port\":${SUBMW_DB_PORT:-3306},\"name\":\"${SUBMW_DB_NAME:-submw}\",\"user\":\"${SUBMW_DB_USER:-submw}\",\"pass\":\"${SUBMW_DB_PASSWORD:-}\",\"charset\":\"utf8mb4\"}"
  else
    DB="{\"driver\":\"sqlite\",\"path\":\"${DEST}/data/submw.sqlite\"}"
  fi
  php -r '
    $j = ["key" => $argv[1], "scenario" => $argv[2], "step" => "env",
          "panel" => ["url" => $argv[3], "token" => getenv("SUBMW_PANEL_TOKEN") ?: "", "cookie" => getenv("SUBMW_PANEL_COOKIE") ?: "", "xkey" => getenv("SUBMW_PANEL_XKEY") ?: "", "tls" => getenv("SUBMW_PANEL_TLS") === "0" ? 0 : 1],
          "subpage" => ["url" => $argv[4], "enabled" => 1],
          "db" => json_decode($argv[5], true)];
    file_put_contents($argv[6], json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
  ' "$KEY" "$SCEN" "$PANEL_URL" "$SUBPAGE_URL" "$DB" "$DEST/data/install.json"
  chmod 640 "$DEST/data/install.json"
  echo "=================================================================="
  echo "submw: ссылка мастера установки (откройте по своему домену, за реверсом):"
  echo "    https://<домен-подписки>/admin/?${KEY}"
  echo "=================================================================="
fi
chown -R www-data:www-data "$DEST/data"

php-fpm -F &
FPM=$!
nginx -g 'daemon off;' &
NGINX=$!

( while sleep 60; do setpriv --reuid=www-data --regid=www-data --init-groups php "$DEST/tools/cron.php" || true; done ) &

while kill -0 "$FPM" 2>/dev/null && kill -0 "$NGINX" 2>/dev/null; do
  sleep 5
done

echo "submw: один из процессов завершился (fpm=$FPM nginx=$NGINX), выхожу для перезапуска контейнера" >&2
kill "$FPM" "$NGINX" 2>/dev/null || true
exit 1
