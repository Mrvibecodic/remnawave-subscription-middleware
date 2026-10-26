# Установка прослойки подписки Remnawave

Прослойка встаёт между клиентами и панелью на домене подписки: отдаёт подписки и страницу в браузере, умеет блокировки/оверрайды, грейс, лог, вебхуки, чат, приманку и админку.

Установка — в три действия: поднять контейнер, направить домен на прослойку, пройти **мастер установки** в админке. Мастер сам проверяет связь с панелью, права API-токена, страницу подписки, вебхук и в конце запрашивает подписку первого пользователя через ваш домен — как это сделает клиент.

Образ один: `ghcr.io/mrvibecodic/remnawave-subscription-middleware` (`v1` — стабильный, `dev` — тестовый). Данные (БД, конфиг) — в volume, обновление не трогает.

---

## Какой сценарий выбрать

| | Где панель | Реверс-прокси | Файл |
|---|---|---|---|
| **1. Рядом с панелью** (рекомендуется) | этот же сервер | реверс панели (eGames / remnawave-reverse-proxy) | `docker-compose.panel.yml` |
| **2. Отдельный сервер, реверс уже есть** | другой сервер | ваш nginx / Caddy / панель хостинга | `docker-compose.standalone.yml` (+ `-mysql`) |
| **3. Отдельный чистый сервер** | другой сервер | Caddy из compose, сертификат сам | `docker-compose.standalone-caddy.yml` (+ `-mysql`) |
| 4. Без Docker | другой сервер | nginx + php-fpm на хост, режим зеркала | `install.sh` |

Варианты `-mysql` поднимают MariaDB в том же compose; без них — SQLite в volume (хватает большинству).

Не хотите выбирать руками — скрипт спросит всё в консоли и сам скачает нужный compose:
```bash
bash <(curl -fsSL https://raw.githubusercontent.com/Mrvibecodic/remnawave-subscription-middleware/main/install.sh)
```

---

## Сценарий 1. Рядом с панелью

```bash
mkdir -p /opt/submw && cd /opt/submw
curl -fsSL https://raw.githubusercontent.com/Mrvibecodic/remnawave-subscription-middleware/main/docker/docker-compose.panel.yml -o docker-compose.yml
docker compose up -d
docker compose logs submw        # здесь ссылка мастера установки
```
В файле ничего править не нужно, если сеть панели стандартная (`remnawave-network`) и контейнеры называются `remnawave` / `remnawave-subscription-page`.

**Направьте домен подписки на прослойку** в реверсе панели (`127.0.0.1:8080`):
- Caddy (remnawave-reverse-proxy): в `/opt/remnawave/docker-compose.yml` у контейнера Caddy `SUB_BACKEND_URL=127.0.0.1:8080`, затем `docker compose up -d remnawave-caddy`;
- nginx (eGames): в `/opt/remnawave/nginx.conf` — `upstream json { server 127.0.0.1:8080; }`, затем `docker exec remnawave-nginx nginx -t && docker exec remnawave-nginx nginx -s reload`;
- любой другой: server-блок домена подписки → `proxy_pass http://127.0.0.1:8080` с `Host $host`, `X-Forwarded-Proto $scheme`, `X-Forwarded-For`.

С этого момента и до конца мастера подписки на домене недоступны (минуты). Контейнер `remnawave-subscription-page` остаётся — прослойка показывает его страницу в браузере.

Откройте ссылку мастера из логов: `https://<домен-подписки>/admin/?k…`. Мастер по умолчанию уже знает внутренние адреса панели и subpage, спросит API-токен и пароль админки.

## Сценарий 2. Отдельный сервер, реверс уже есть

```bash
mkdir -p /opt/submw && cd /opt/submw
curl -fsSL https://raw.githubusercontent.com/Mrvibecodic/remnawave-subscription-middleware/main/docker/docker-compose.standalone.yml -o docker-compose.yml
nano docker-compose.yml          # у subpage: REMNAWAVE_PANEL_URL, REMNAWAVE_API_TOKEN, при cookie-защите панели — EGAMES_COOKIE
docker compose up -d
docker compose logs submw
```
В реверсе направьте домен подписки на `127.0.0.1:8080` (как в сценарии 1, «любой другой»). Откройте ссылку мастера, укажите публичный адрес панели и токен — мастер подскажет, если панель закрыта cookie (eGames), X-Api-Key (caddy-with-auth) или Cloudflare.

Если прослойка должна стать **зеркалом** (свой домен, подписки берутся с домена подписки панели) — выберите это в мастере на шаге «Сценарий»; subpage в compose тогда не нужен.

С MariaDB вместо SQLite: `docker-compose.standalone-mysql.yml` — всё то же, ничего дополнительно не настраивается.

## Сценарий 3. Отдельный чистый сервер (Caddy)

DNS домена подписки уже должен указывать на этот сервер, порты 80/443 свободны.
```bash
mkdir -p /opt/submw && cd /opt/submw
curl -fsSL https://raw.githubusercontent.com/Mrvibecodic/remnawave-subscription-middleware/main/docker/docker-compose.standalone-caddy.yml -o docker-compose.yml
curl -fsSL https://raw.githubusercontent.com/Mrvibecodic/remnawave-subscription-middleware/main/docker/caddy/Caddyfile -o Caddyfile
nano docker-compose.yml          # SUBMW_DOMAIN у caddy; у subpage: адрес панели, токен, EGAMES_COOKIE
docker compose up -d
docker compose logs submw
```
Caddy выпустит сертификат Let's Encrypt при первом запросе и будет продлевать сам. Дальше — ссылка мастера.

## Сценарий 4. Без Docker

```bash
git clone https://github.com/Mrvibecodic/remnawave-subscription-middleware.git
cd remnawave-subscription-middleware
sudo bash install.sh             # пункт 4
```
Ставит nginx + php-fpm + certbot, выпускает сертификат и печатает ссылку мастера. Это режим зеркала: subpage не нужен, страницу в браузере прослойка берёт с origin.

---

## Мастер установки

Открывается только по домену через реверс (по IP:порт — не запустится). Шаги: окружение → сценарий → панель → права токена → страница подписки / origin → вебхук → админ. На каждом — проверка с объяснением, что не так и где это чинится. Блокируют только то, без чего прослойка не отдаст подписку: БД, панель и токен, четыре обязательных права, версия панели не ниже 2.7.4. Остальное — предупреждения, их можно закрыть позже на вкладке «Подключение» → «Проверка связи».

В конце мастер показывает **ссылку-ключ** вида `https://домен/admin/?k…=…`. Без неё адрес `/admin/` показывает ту же страницу-приманку, что и корень сайта. Откройте ссылку в каждом браузере и на телефоне, где нужен доступ. Потеряли — `docker compose exec submw php tools/admin-gate.php` печатает ссылку (`--new` перевыпускает, `--off` выключает); то же есть на вкладке «Подключение».

Если панель на 3.2.0+, мастер сверяет `SUB_PUBLIC_DOMAIN` панели с доменом прослойки и состояние вебхуков; строки для `.env` панели даёт готовыми:
```
WEBHOOK_ENABLED=true
WEBHOOK_URL=https://<домен-подписки>/webhook.php
WEBHOOK_SECRET_HEADER=<секрет из мастера, 64 символа>
```

## Обновление

```bash
cd /opt/submw && docker compose pull && docker compose up -d
```
Вкладка «Обновление» в админке подсвечивает новую версию и показывает эти команды. Без Docker — файловое обновление с GitHub оттуда же.

## Заметки

- Прослойка ходит к панели по внутреннему адресу, когда они на одном сервере (`http://remnawave:3000`) — мимо Cloudflare, так и задумано. С отдельного сервера — по публичному адресу; cookie eGames и X-Api-Key указываются в мастере.
- subscription-page на отдельном сервере ходит в панель сам: `REMNAWAVE_PANEL_URL`, `REMNAWAVE_API_TOKEN`, а за cookie-защитой — `EGAMES_COOKIE` (имя=значение из ссылки входа в панель). Если он не поднимается — `docker compose logs subpage`.
- Реверс обязан передавать `Host`, `X-Forwarded-Proto` и `X-Forwarded-For`: без `Host` мастер считает, что его открыли по IP.
- Префикс подписки (`CUSTOM_SUB_PREFIX` у subscription-page) мастер в режиме зеркала определяет сам из ссылки подписки.
