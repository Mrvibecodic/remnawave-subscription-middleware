#!/usr/bin/env bash
set -euo pipefail

[ "$(id -u)" -eq 0 ] || { echo "Запусти от root: sudo bash install.sh"; exit 1; }

REPO="remnawave-subscription-middleware"
DEST="/opt/${REPO}"
SRC_DIR="$(cd "$(dirname "$0")" && pwd)"
ACME_WEBROOT="/var/www/acme"

ask()  { local p="$1" d="${2:-}" v; read -rp "$p${d:+ [$d]}: " v; printf '%s' "${v:-$d}"; }
asks() { local p="$1" v; read -rsp "$p: " v; echo >&2; printf '%s' "$v"; }

echo "== Установка прослойки подписки Remnawave (РФ-сервер, nginx + PHP-FPM + SQLite) =="
DOMAIN="$(ask 'Домен прослойки (он же зеркало подписок), напр. mirror.example.com')"
ORIGIN_DOMAIN="$(ask 'Origin — домен подписки панели, напр. sub.example.com')"
echo "Тип базы данных:"
echo "  1) SQLite — файл, ничего не ставим (по умолчанию, для слабых серверов)"
echo "  2) MySQL/MariaDB — поставим лёгкую MariaDB и создадим базу автоматически"
DB_MODE="$(ask 'Выбор (1/2)' 1)"
echo "Способ выпуска сертификата:"
echo "  1) HTTP-01 — проверка по 80 порту (порт должен быть открыт извне)"
echo "  2) Cloudflare DNS API — без открытия порта (нужен API-токен Zone.DNS:Edit)"
CERT_MODE="$(ask 'Выбор (1/2)' 1)"
ACME_EMAIL="$(ask 'Email для Let'\''s Encrypt')"
CF_AUTH=""; CF_TOKEN=""; CF_EMAIL=""; CF_KEY=""
if [ "$CERT_MODE" = "2" ]; then
  echo "Доступ к Cloudflare:"
  echo "  1) API Token (рекомендуется; права Zone.DNS: Edit)"
  echo "  2) Global API Key + email аккаунта Cloudflare"
  CF_AUTH="$(ask 'Выбор (1/2)' 1)"
  if [ "$CF_AUTH" = "2" ]; then
    CF_EMAIL="$(ask 'Email аккаунта Cloudflare')"
    CF_KEY="$(asks 'Cloudflare Global API Key')"
  else
    CF_TOKEN="$(asks 'Cloudflare API Token')"
  fi
fi

[ -n "$DOMAIN" ] && [ -n "$ORIGIN_DOMAIN" ] || { echo "Не заданы домен/origin."; exit 1; }

echo "-> Установка зависимостей (если ещё нет)..."
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
PKGS="php-fpm php-cli php-sqlite3 php-mysql php-curl php-mbstring php-xml git openssl curl gnupg2 ca-certificates lsb-release certbot"
[ "$DB_MODE" = "2" ] && PKGS="$PKGS mariadb-server"
[ "$CERT_MODE" = "2" ] && PKGS="$PKGS python3-certbot-dns-cloudflare"
apt-get install -y $PKGS >/dev/null

echo "-> Установка актуального nginx из официального репозитория nginx.org..."
if dpkg -l 2>/dev/null | grep -qE '^ii[[:space:]]+nginx' || command -v nginx >/dev/null 2>&1; then
    echo "!! ВНИМАНИЕ: на сервере уже установлен nginx."
    echo "   Установщик удалит текущие пакеты nginx (remove + purge nginx-common) и поставит сборку с nginx.org."
    echo "   purge затронет системный конфиг в /etc/nginx (сайты в conf.d, nginx.conf и т.п.) — сделайте бэкап, если nginx обслуживает другие сайты."
    ANS="$(ask 'Удалить предустановленный nginx и продолжить?' 'N')"
    case "$ANS" in
        [yY]|[yY][eE][sS]) echo "   -> удаляю предустановленный nginx..." ;;
        *) echo "Отменено: nginx не тронут, установка прервана."; exit 1 ;;
    esac
fi
apt-get remove -y nginx nginx-common nginx-core nginx-full >/dev/null 2>&1 || true
apt-get purge  -y nginx nginx-common >/dev/null 2>&1 || true
OS_ID="$(. /etc/os-release; echo "${ID:-ubuntu}")"
CODENAME="$(lsb_release -cs 2>/dev/null || true)"
[ -z "$CODENAME" ] && CODENAME="$(. /etc/os-release; echo "${VERSION_CODENAME:-}")"
[ "$OS_ID" = "debian" ] && NGX_OS="debian" || NGX_OS="ubuntu"
curl -fsSL https://nginx.org/keys/nginx_signing.key | gpg --dearmor | tee /usr/share/keyrings/nginx-archive-keyring.gpg >/dev/null
echo "deb [signed-by=/usr/share/keyrings/nginx-archive-keyring.gpg] http://nginx.org/packages/${NGX_OS} ${CODENAME} nginx" > /etc/apt/sources.list.d/nginx.list
printf 'Package: *\nPin: origin nginx.org\nPin: release o=nginx\nPin-Priority: 900\n' > /etc/apt/preferences.d/99nginx
apt-get update -qq
apt-get install -y nginx >/dev/null
mkdir -p /etc/nginx/conf.d
sed -i 's/^user[[:space:]].*/user www-data;/' /etc/nginx/nginx.conf
systemctl enable --now nginx >/dev/null 2>&1 || true
echo -n "   "; nginx -v 2>&1

PHP_VER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
PHP_POOL_DIR="/etc/php/${PHP_VER}/fpm/pool.d"
PHP_SOCK="/run/php/submw.sock"
if [ -d "$PHP_POOL_DIR" ]; then
  cat > "${PHP_POOL_DIR}/submw.conf" <<POOL
[submw]
user = www-data
group = www-data
listen = ${PHP_SOCK}
listen.owner = www-data
listen.group = www-data
pm = ondemand
pm.max_children = 8
pm.process_idle_timeout = 10s
pm.max_requests = 500
POOL
else
  PHP_SOCK="/run/php/php${PHP_VER}-fpm.sock"
fi
systemctl enable --now "php${PHP_VER}-fpm" >/dev/null 2>&1 || true
systemctl restart "php${PHP_VER}-fpm" >/dev/null 2>&1 || true

echo "-> Стягиваю файлы в ${DEST}..."
mkdir -p "$DEST"
if [ "$SRC_DIR" != "$DEST" ]; then cp -r "$SRC_DIR/." "$DEST/"; fi
rm -f "$DEST/install.sh"
mkdir -p "$DEST/data"
chown -R www-data:www-data "$DEST"
find "$DEST" -type d -exec chmod 755 {} \;
find "$DEST" -type f -exec chmod 644 {} \;
chmod 775 "$DEST/data"

DB_JSON=""
if [ "$DB_MODE" = "2" ]; then
  echo "-> Настройка MariaDB (база и пользователь со случайными именем/паролем)..."
  systemctl enable --now mariadb >/dev/null 2>&1 || true
  DB_NAME="submw_$(openssl rand -hex 4)"
  DB_USER="submw_$(openssl rand -hex 4)"
  DB_PASS="$(openssl rand -hex 24)"
  mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
  DB_JSON="\"db\":{\"driver\":\"mysql\",\"host\":\"127.0.0.1\",\"port\":3306,\"name\":\"${DB_NAME}\",\"user\":\"${DB_USER}\",\"pass\":\"${DB_PASS}\"}"
  echo "   MariaDB: база ${DB_NAME}, пользователь ${DB_USER} — креды переданы мастеру установки"
else
  DB_JSON="\"db\":{\"driver\":\"sqlite\",\"path\":\"${DEST}/data/submw.sqlite\"}"
  echo "   БД: SQLite -> ${DEST}/data/submw.sqlite (создаст мастер установки)"
fi

cat > "${DEST}/data/install.json" <<JSON
{"target_domain":"${ORIGIN_DOMAIN}","mirror_domain":"${DOMAIN}",${DB_JSON}}
JSON
chown www-data:www-data "${DEST}/data/install.json"
chmod 600 "${DEST}/data/install.json"

CONF="/etc/nginx/conf.d/${DOMAIN}.conf"
write_https_vhost() {
  cat > "$CONF" <<NG
limit_req_zone \$binary_remote_addr zone=submw_login:10m rate=12r/m;
server {
    listen 80;
    server_name ${DOMAIN};
    location ^~ /.well-known/acme-challenge/ { root ${ACME_WEBROOT}; }
    location / { return 301 https://\$host\$request_uri; }
}
server {
    listen 443 ssl;
    http2 on;
    server_name ${DOMAIN};

    ssl_certificate     /etc/letsencrypt/live/${DOMAIN}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/${DOMAIN}/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    add_header Strict-Transport-Security "max-age=31536000" always;

    root ${DEST};
    index index.php;
    charset utf-8;
    client_max_body_size 8m;

    gzip on;
    gzip_min_length 1024;
    gzip_proxied expired no-cache no-store private auth;
    gzip_types text/css application/javascript application/json image/svg+xml text/plain;
    gzip_comp_level 5;

    location ^~ /.well-known/acme-challenge/ { root ${ACME_WEBROOT}; }

    location ~ ^/(config\.php|config\.example\.php|lib\.php|schema\.sql|README\.md|install\.sh)\$ { deny all; }
    location ~* \.(sqlite|sqlite3|db|db-wal|db-shm)\$ { deny all; }
    location ~ /\.(?!well-known) { deny all; }
    location ^~ /data/ { deny all; }
    location ^~ /lib/  { deny all; }
    location ^~ /backups/ { deny all; }

    location = /webhook.php { fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name; include fastcgi_params; fastcgi_pass unix:${PHP_SOCK}; }

    location /admin/ { limit_req zone=submw_login burst=10 nodelay; try_files \$uri \$uri/ /admin/index.php\$is_args\$args; }

    location ~* \.(css|js|mjs|svg|png|jpe?g|gif|webp|ico|woff2?|ttf|map)\$ { try_files \$uri /index.php\$is_args\$args; expires 30d; access_log off; }

    location / { try_files \$uri /index.php\$is_args\$args; }

    location ~ \.php\$ { fastcgi_split_path_info ^(.+\.php)(/.+)\$; fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name; include fastcgi_params; fastcgi_pass unix:${PHP_SOCK}; }
}
NG
}
write_http_bootstrap() {
  cat > "$CONF" <<NG
server {
    listen 80;
    server_name ${DOMAIN};
    location ^~ /.well-known/acme-challenge/ { root ${ACME_WEBROOT}; }
    location / { return 200 'ok'; }
}
NG
}

mkdir -p "${ACME_WEBROOT}/.well-known/acme-challenge"
chown -R www-data:www-data "$ACME_WEBROOT"

enable_site() { rm -f /etc/nginx/conf.d/default.conf; }

if [ "$CERT_MODE" = "2" ]; then
    echo "-> Сертификат через Cloudflare DNS..."
    umask 077
    if [ "$CF_AUTH" = "2" ]; then
      cat > /root/.cloudflare.ini <<EOF2
dns_cloudflare_email = ${CF_EMAIL}
dns_cloudflare_api_key = ${CF_KEY}
EOF2
    else
      cat > /root/.cloudflare.ini <<EOF2
dns_cloudflare_api_token = ${CF_TOKEN}
EOF2
    fi
    chmod 600 /root/.cloudflare.ini
    certbot certonly --non-interactive --agree-tos -m "$ACME_EMAIL" \
        --dns-cloudflare --dns-cloudflare-credentials /root/.cloudflare.ini \
        --dns-cloudflare-propagation-seconds 30 --deploy-hook "systemctl reload nginx" -d "$DOMAIN" || {
        echo "!! Cloudflare: сертификат не выпущен. Проверьте тип доступа (1=API Token / 2=Global Key+email) и права (Zone.DNS: Edit). nginx не менялся — запустите скрипт снова."; exit 1; }
else
    echo "-> Выпуск по HTTP-01 (нужен открытый 80 порт)..."
    write_http_bootstrap
    enable_site
    nginx -t && systemctl reload nginx
    certbot certonly --non-interactive --agree-tos -m "$ACME_EMAIL" \
        --webroot -w "$ACME_WEBROOT" --deploy-hook "systemctl reload nginx" -d "$DOMAIN" || {
        echo "!! HTTP-01: не удалось. 80 порт должен быть открыт извне (firewall/Cloudflare). Запустите снова или выберите Cloudflare DNS (способ 2)."; exit 1; }
fi

echo "-> Финальный конфиг nginx (HTTPS + вебхук + фронт-контроллер)..."
write_https_vhost
enable_site
nginx -t && systemctl reload nginx

echo
echo "================ ГОТОВО ================"
echo "Файлы:    ${DEST}"
if [ "$DB_MODE" = "2" ]; then
  echo "БД:       MySQL/MariaDB — база ${DB_NAME}, пользователь ${DB_USER} (параметры переданы мастеру)"
else
  echo "БД:       SQLite -> ${DEST}/data/submw.sqlite (создаст мастер установки)"
fi
echo "Админка:  https://${DOMAIN}/admin/   — пройдите мастер установки"
echo "Вебхук:   https://${DOMAIN}/webhook.php"
echo "Зеркало:  https://${DOMAIN}/          — этот адрес идёт в ссылки подписки"
echo
echo "В мастере укажите: origin=${ORIGIN_DOMAIN}, URL панели + API-токен, секрет вебхука,"
echo "логин/пароль админки. Поля БД заполнять не нужно — параметры БД подставит мастер."
echo "Затем пропишите https://${DOMAIN}/webhook.php в .env панели Remnawave"
echo "(WEBHOOK_ENABLED=true, WEBHOOK_URL=..., WEBHOOK_SECRET_HEADER=...)."
