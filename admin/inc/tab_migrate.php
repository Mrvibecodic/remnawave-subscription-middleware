<?php $cur_drv = db_driver(); ?>
<?php if (submw_in_docker()): ?>
    <div class="card">
        <h2 style="margin-top:0;font-size:1rem">Миграция базы данных (Docker)</h2>
        <p class="muted">Сейчас прослойка работает на: <b><?= $cur_drv === 'mysql' ? 'MySQL / MariaDB' : 'SQLite' ?></b>. Миграция копирует все таблицы в другую БД и переключает прослойку. <code>config.php</code> лежит в volume — переключение сохраняется при <code>docker compose pull</code>.</p>
    </div>
    <?php if ($cur_drv !== 'mysql'): $envdb = submw_env_db(); ?>
    <div class="card">
        <h2 style="margin-top:0;font-size:1rem">Перейти на MySQL / MariaDB</h2>
        <p class="muted">В Docker MariaDB поднимается соседним сервисом в compose, а её адрес и креды прокидываются прослойке через окружение — форму заполнять не нужно.</p>
        <p style="margin:.9rem 0 .3rem"><b>1.</b> В <code>docker-compose.yml</code> (там же, где сервис прослойки) добавьте сервис БД и env прослойке. Пароль задайте свой и одинаковый в двух местах:</p>
        <pre>services:
  remnawave-submw-db:
    image: mariadb:11
    container_name: remnawave-submw-db
    restart: always
    networks: [remnawave-network]
    environment:
      - MARIADB_DATABASE=submw
      - MARIADB_USER=submw
      - MARIADB_PASSWORD=ВАШ_ПАРОЛЬ
      - MARIADB_ROOT_PASSWORD=ВАШ_ROOT_ПАРОЛЬ
    volumes:
      - submw-db:/var/lib/mysql

  remnawave-subscription-middleware:
    environment:
      - SUBMW_DB_HOST=remnawave-submw-db
      - SUBMW_DB_NAME=submw
      - SUBMW_DB_USER=submw
      - SUBMW_DB_PASSWORD=ВАШ_ПАРОЛЬ

volumes:
  submw-db:</pre>
        <p class="muted">Блоки <code>services:</code> / <code>volumes:</code> не дублируйте — добавляйте сервис/том в существующие.</p>
        <p style="margin:.9rem 0 .3rem"><b>2.</b> Применить (из каталога с compose):</p>
        <pre>docker compose pull
docker compose up -d</pre>
        <p style="margin:.9rem 0 .3rem"><b>3.</b> Обновите эту страницу и нажмите «Переехать» — прослойка прочитает БД из окружения, скопирует данные и переключится.</p>
        <?php if ($envdb && ($envdb['name'] ?? '') !== '' && ($envdb['user'] ?? '') !== ''): ?>
            <div class="info" style="margin-top:.6rem">БД из compose найдена: хост <code><?= h($envdb['host']) ?></code>, база <code><?= h($envdb['name']) ?></code>, пользователь <code><?= h($envdb['user']) ?></code>.</div>
            <form method="post" onsubmit="return uiConfirmForm(this,'Перенести все данные в MySQL и переключить прослойку на неё?')">
                <input type="hidden" name="csrf" value="<?= h($token) ?>">
                <input type="hidden" name="action" value="migrate_db">
                <input type="hidden" name="to" value="mysql">
                <div style="margin-top:1rem"><button type="submit">🐬 Переехать на MySQL</button></div>
            </form>
        <?php else: ?>
            <div class="warn" style="margin-top:.6rem">Переменные БД (<code>SUBMW_DB_HOST</code> и др.) в окружении не заданы. Выполните шаги 1–2 — после <code>up -d</code> здесь появится кнопка «Переехать».</div>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="card">
        <h2 style="margin-top:0;font-size:1rem">Вернуться на SQLite</h2>
        <p class="muted">Все данные скопируются в файловую базу <code>data/submw.sqlite</code> (в volume), прослойка переключится на неё. MySQL-база останется нетронутой — после этого можно убрать сервис БД и env из compose.</p>
        <form method="post" onsubmit="return uiConfirmForm(this,'Перенести все данные в SQLite и переключить прослойку на неё?')">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="migrate_db">
            <input type="hidden" name="to" value="sqlite">
            <div style="margin-top:.4rem"><button type="submit">🪶 Мигрировать на SQLite</button></div>
        </form>
    </div>
    <?php endif; ?>
<?php else: ?>
    <div class="card">
        <h2 style="margin-top:0;font-size:1rem">Миграция базы данных</h2>
        <p class="muted">Сейчас прослойка работает на: <b><?= $cur_drv === 'mysql' ? 'MySQL / MariaDB' : 'SQLite' ?></b>. Миграция копирует все таблицы в другую БД и переключает прослойку на неё (config.php обновляется автоматически). Перед миграцией сделайте бэкап.</p>
    </div>

    <?php if ($cur_drv !== 'mysql'): ?>
    <div class="card">
        <h2 style="margin-top:0;font-size:1rem">Перейти на MySQL / MariaDB</h2>
        <p class="muted">Укажите параметры <b>уже созданной</b> MySQL-базы (её содержимое будет перезаписано). Создать БД и пользователя можно при установке (<code>install.sh</code> → опция «полноценная БД») или вручную в панели хостинга / командой.</p>
        <form method="post" onsubmit="return uiConfirmForm(this,'Перенести все данные в MySQL и переключить прослойку на неё?')">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="migrate_db">
            <input type="hidden" name="to" value="mysql">
            <div class="row">
                <div><label>Хост</label><input type="text" name="m_host" value="127.0.0.1"></div>
                <div><label>Порт</label><input type="text" name="m_port" value="3306"></div>
            </div>
            <label>Имя БД</label><input type="text" name="m_name" placeholder="submw">
            <label>Пользователь</label><input type="text" name="m_user" placeholder="submw">
            <label>Пароль</label><input type="password" name="m_pass">
            <div style="margin-top:1.1rem"><button type="submit">🐬 Мигрировать на MySQL</button></div>
        </form>
    </div>

    <section class="coll collapsed" data-coll="mig_help">
        <button type="button" class="coll-head" onclick="collToggle(this)"><span>📘 Как поставить MySQL/MariaDB и создать базу вручную (консоль сервера)</span>
            <span class="coll-hr"><svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></span>
        </button>
        <div class="coll-body">
            <p class="muted">Если MySQL ещё не установлен — поставьте MariaDB и создайте базу под прослойку, затем введите её параметры в форму выше. Все команды — от root (или через <code>sudo</code>).</p>

            <p style="margin:.9rem 0 .3rem"><b>1. Установка MariaDB</b> <span class="muted">— Debian и Ubuntu одинаково (apt)</span></p>
            <pre>apt update
apt install -y mariadb-server
systemctl enable --now mariadb</pre>
            <p class="muted">По желанию — базовая защита сервера БД: <code>mysql_secure_installation</code>.</p>

            <p style="margin:.9rem 0 .3rem"><b>2. Создать базу и пользователя</b></p>
            <p class="muted">Войдите в консоль MariaDB:</p>
            <pre>mysql</pre>
            <p class="muted">И выполните (замените <code>СВОЙ_ПАРОЛЬ</code> на свой надёжный пароль):</p>
            <pre>CREATE DATABASE submw CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'submw'@'127.0.0.1' IDENTIFIED BY 'СВОЙ_ПАРОЛЬ';
CREATE USER 'submw'@'localhost' IDENTIFIED BY 'СВОЙ_ПАРОЛЬ';
GRANT ALL PRIVILEGES ON submw.* TO 'submw'@'127.0.0.1';
GRANT ALL PRIVILEGES ON submw.* TO 'submw'@'localhost';
FLUSH PRIVILEGES;
EXIT;</pre>

            <p style="margin:.9rem 0 .3rem"><b>3. Заполнить форму выше</b></p>
            <p class="muted">Хост <code>127.0.0.1</code>, порт <code>3306</code>, имя БД <code>submw</code>, пользователь <code>submw</code>, пароль — ваш. Таблицы прослойка создаст при миграции сама.</p>

            <p class="muted" style="margin-top:.8rem"><b>Удалённый MySQL</b> тоже подойдёт: укажите его хост/порт и креды, а на сервере БД разрешите пользователю подключение с IP этого сервера (<code>'submw'@'IP_прослойки'</code>) и откройте порт 3306 для него.</p>
        </div>
    </section>
    <?php else: ?>
    <div class="card">
        <h2 style="margin-top:0;font-size:1rem">Вернуться на SQLite</h2>
        <p class="muted">Все данные скопируются в файловую базу <code>data/submw.sqlite</code>, прослойка переключится на неё. Прежняя MySQL-база останется нетронутой.</p>
        <form method="post" onsubmit="return uiConfirmForm(this,'Перенести все данные в SQLite и переключить прослойку на неё?')">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="migrate_db">
            <input type="hidden" name="to" value="sqlite">
            <div style="margin-top:.4rem"><button type="submit">🪶 Мигрировать на SQLite</button></div>
        </form>
    </div>
    <?php endif; ?>
<?php endif; ?>
