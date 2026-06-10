# Установка прослойки подписки Remnawave

Прослойка встаёт между клиентами и панелью: отдаёт страницу подписки и конфиги, умеет блокировки/оверрайды, грейс, лог, вебхуки, чат, приманку и админку.

Варианты:
- **Рядом с панелью, Docker** — основной (панель и так в Docker). Прослойка-контейнер заменяет прямой доступ к `subscription-page`.
- **Рядом с панелью, пакеты на хост** — без Docker, если так удобнее.
- **Отдельный сервер** — прослойка одна, на своём домене с TLS.

Во всех «рядом с панелью» вариантах контейнер `subscription-page` **остаётся работать** — прослойка проксирует страницу подписки на него (режим «внешний контейнер»), а клиентские конфиги и свои фичи обрабатывает сама.

---

## Вариант 1. Рядом с панелью, Docker (рекомендуется)

Условие: панель Remnawave + reverse-proxy (напр. **eGames**) уже стоят, домен подписки сейчас обслуживает контейнер `remnawave-subscription-page`.

### Шаг 1. Поднять контейнер прослойки
Через установщик:
```bash
git clone https://github.com/Mrvibecodic/remnawave-subscription-middleware.git
cd remnawave-subscription-middleware
sudo bash install.sh    # выбрать "3) Рядом с панелью, Docker-контейнером"
```
Спросит: домен подписки, имя docker-сети панели (`remnawave-network`), внутренний URL панели (`http://remnawave:3000`), URL контейнера subpage (`http://remnawave-subscription-page:3010`), локальный порт (`8080`), тег образа (`dev`/`main`). Поставит Docker (если нет), создаст `docker-compose.yml`, скачает образ из GHCR и поднимет контейнер.

Либо вручную — положите `docker-compose.example.yml` рядом со стеком панели, поправьте env и:
```bash
docker compose up -d remnawave-subscription-middleware
```
Образ: `ghcr.io/mrvibecodic/remnawave-subscription-middleware:dev` (внутри nginx+php-fpm, код запечён; данные — в volume `submw-data`).

### Шаг 2. Направить домен подписки на прослойку (правка nginx ПАНЕЛИ)
**eGames (nginx):** в конфиге nginx панели (обычно `/opt/remnawave/nginx.conf`) — одна строка:
```nginx
upstream json {
    server 127.0.0.1:8080;
}
```
(было `127.0.0.1:3010`). Заголовки в server-блоке трогать не нужно. Перезагрузить nginx панели:
```bash
docker exec <nginx-контейнер-панели> nginx -t && docker exec <nginx-контейнер-панели> nginx -s reload
```
**Общий случай:** найдите server-блок домена подписки (проксирует на subscription-page :3010) и поменяйте upstream/`proxy_pass` на `http://127.0.0.1:8080`.

### Шаг 3. Завершить мастер
Откройте `https://<домен-подписки>/admin/`. Режим «панель» + адреса панели/subpage уже подставлены окружением — задайте логин/пароль админки и API-токен панели.

### Обновление
```bash
cd /opt/remnawave-subscription-middleware
docker compose pull && docker compose up -d
```
Во вкладке «Обновление» админка сама подсветит наличие новой версии и покажет эти команды. Данные (config.php, БД) в volume — не теряются.

---

## Вариант 2. Рядом с панелью, пакеты на хост (без Docker)

```bash
git clone https://github.com/Mrvibecodic/remnawave-subscription-middleware.git
cd remnawave-subscription-middleware
sudo bash install.sh    # выбрать "2) Рядом с панелью (за её nginx)"
```
Поставит nginx+php-fpm, поднимет прослойку на `127.0.0.1:8080` (без TLS/certbot, 443 не трогает), запишет режим/адреса. Дальше — те же Шаг 2 и Шаг 3, что и в Docker-варианте. Обновление — обычное файловое (вкладка «Обновление» / `git pull`).

---

## Вариант 3. Отдельный сервер (прослойка одна)

```bash
git clone https://github.com/Mrvibecodic/remnawave-subscription-middleware.git
cd remnawave-subscription-middleware
sudo bash install.sh    # выбрать "1) Отдельный сервер"
```
Поставит nginx + php-fpm + certbot, выпустит сертификат и поднимет всё на `443`. Это режим **зеркала** (проксирует origin-домен подписки панели) — бандл/контейнер subpage не нужны. В конце — ссылка на мастер.

---

## Заметки
- **`SUB_PUBLIC_DOMAIN`** в `.env` панели уже равен домену подписки (eGames его выставил) — менять не нужно.
- Прослойка ходит к панели по внутреннему адресу (имя контейнера в Docker / `127.0.0.1:3000` на хосте) — мимо Cloudflare, так задумано.
- Контейнер `remnawave-subscription-page` оставляем работать — прослойка отдаёт страницу через него.
- В eGames sub-vhost есть `proxy_intercept_errors on; ... @redirect (444)` — на штатных ответах прослойки (200) это не мешает.
- Обновление: в **Docker** — `docker compose pull` (админка подскажет); вне Docker — файловое обновление с GitHub. Docker-файлы (`Dockerfile`, `docker/`, `INSTALL.md`, `.github/`) при файловом обновлении помечены «не обновляется» и не тянутся.
