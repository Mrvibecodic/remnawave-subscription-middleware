<?php
$cur_drv = db_driver();
$gc_ov   = gc_overview();
$gc_db   = metrics_db_info();
$gc_free = gc_free_bytes();
?>
<style>
.gctbl{width:100%;margin-top:.9rem}
.gctbl th:first-child,.gctbl td:first-child{width:2.4rem;text-align:center}
.gctbl td.n,.gctbl th.n{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.gctbl input[type=checkbox]{margin:0;vertical-align:middle}
.gctbl tr.off td:not(:first-child){opacity:.45}
.gcfoot{display:flex;gap:.7rem;align-items:center;flex-wrap:wrap;margin-top:1rem}
</style>
<div class="card">
    <h2 style="margin-top:0;font-size:1rem">Очистка данных</h2>
    <p class="muted">Разово удаляет старые записи журналов и метрик. На работу прослойки это не влияет: пропадёт только история в соответствующих вкладках админки. Оверрайды, грейс, конфиги и настройки не затрагиваются.</p>
    <form method="post" onsubmit="return uiConfirmForm(this,'Удалить выбранные записи? Вернуть их будет нельзя.')">
        <input type="hidden" name="csrf" value="<?= h($token) ?>">
        <input type="hidden" name="action" value="gc_purge">
        <input type="hidden" name="gc_days" id="gcDays" value="30">
        <div class="seg" id="gcSeg">
            <?php foreach (gc_periods() as $gc_d): ?>
            <button type="button" data-d="<?= (int) $gc_d ?>">Старше <?= (int) $gc_d ?> дней</button>
            <?php endforeach; ?>
            <button type="button" data-d="0">Всё</button>
        </div>
        <table class="logtbl gctbl">
            <thead><tr><th></th><th>Таблица</th><th class="n">Всего строк</th><th class="n">Под удаление</th></tr></thead>
            <tbody id="gcTbl">
            <?php foreach ($gc_ov as $gc_name => $gc_r): ?>
                <tr data-t="<?= h($gc_name) ?>" data-d0="<?= $gc_r['total'] === null ? '' : (int) $gc_r['total'] ?>"<?php foreach (gc_periods() as $gc_d): ?> data-d<?= (int) $gc_d ?>="<?= ($gc_r['old'][$gc_d] ?? null) === null ? '' : (int) $gc_r['old'][$gc_d] ?>"<?php endforeach; ?>>
                    <td><input type="checkbox" name="gc_t[]" value="<?= h($gc_name) ?>" checked></td>
                    <td><?= h($gc_r['title']) ?> <code><?= h($gc_name) ?></code></td>
                    <td class="n muted"><?= $gc_r['total'] === null ? '—' : number_format((int) $gc_r['total'], 0, '.', ' ') ?></td>
                    <td class="n gc-n">—</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="gcfoot">
            <button class="danger" type="submit">Очистить</button>
            <span class="muted" style="font-size:.8rem">За один заход удаляется столько, сколько успевается примерно за 15 секунд. Если записей очень много, прослойка скажет, сколько осталось — нажмите ещё раз.</span>
        </div>
    </form>
</div>
<div class="card">
    <h2 style="margin-top:0;font-size:1rem">Сжать базу</h2>
    <p class="muted">Сейчас: <b><?= $cur_drv === 'mysql' ? 'MySQL / MariaDB' : 'SQLite' ?></b>, размер <b><?= h(metrics_fmt_bytes((int) ($gc_db['size'] ?? 0))) ?></b><?= ($gc_free === null || $gc_free <= 0) ? '' : (', свободно внутри — <b>' . h(metrics_fmt_bytes((int) $gc_free)) . '</b>') ?>.</p>
    <p class="muted">После удаления записей файл базы сам не уменьшается: освободившееся место остаётся внутри и переиспользуется под новые записи. Сжатие возвращает его операционной системе.</p>
    <div class="warn" style="margin-top:.6rem">Пока идёт сжатие, база заблокирована целиком — запросы подписки будут ждать. На большой базе это десятки секунд, так что лучше делать в спокойное время.<?= $cur_drv === 'mysql' ? ' Пользователю MySQL нужно право <code>ALTER</code>.' : ' На диске должно быть свободно примерно столько же, сколько весит база.' ?></div>
    <form method="post" onsubmit="return uiConfirmForm(this,'Сжать базу? На время работы она будет заблокирована.')" style="margin-top:.9rem">
        <input type="hidden" name="csrf" value="<?= h($token) ?>">
        <input type="hidden" name="action" value="gc_compact">
        <button type="submit" class="ghost">Сжать базу</button>
    </form>
</div>
<script>
(function(){
    var seg = document.getElementById('gcSeg');
    var tbl = document.getElementById('gcTbl');
    if (!seg || !tbl) return;
    function apply(d){
        Array.prototype.forEach.call(seg.querySelectorAll('button'), function(b){
            b.classList.toggle('on', b.getAttribute('data-d') === d);
        });
        document.getElementById('gcDays').value = d;
        Array.prototype.forEach.call(tbl.querySelectorAll('tr[data-t]'), function(tr){
            var raw = tr.getAttribute('data-d' + d);
            var has = raw !== '' && raw !== null;
            var n = has ? parseInt(raw, 10) : 0;
            var cell = tr.querySelector('.gc-n');
            cell.textContent = has ? n.toLocaleString('ru-RU') : '—';
            cell.classList.toggle('muted', n === 0);
            var cb = tr.querySelector('input[type=checkbox]');
            cb.disabled = (n === 0);
            cb.checked = (n > 0);
            tr.classList.toggle('off', n === 0);
        });
    }
    seg.addEventListener('click', function(e){
        var b = e.target;
        while (b && b !== seg && b.tagName !== 'BUTTON') b = b.parentNode;
        if (b && b.tagName === 'BUTTON') apply(b.getAttribute('data-d'));
    });
    apply('30');
})();
</script>
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

    <section class="<?= coll_cls('mig_help', true) ?>" data-coll="mig_help">
        <button type="button" class="coll-head" onclick="collToggle(this)"><span>📘 Как поставить MySQL/MariaDB и создать базу вручную (консоль сервера)</span>
            <span class="coll-hr"><svg width="30" height="30" class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></span>
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
