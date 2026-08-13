    <style>
    .cv-wrap{border:1px solid var(--line);border-radius:12px;overflow-x:auto}
    .cv-tbl{width:100%;min-width:64rem;border-collapse:separate;border-spacing:0;font-size:.85rem}
    .cv-tbl th{background:var(--bg2);color:var(--muted);font-weight:600;font-size:.7rem;text-transform:uppercase;letter-spacing:.03em;text-align:left;padding:.55rem .6rem;box-shadow:inset 0 -1px 0 var(--line);white-space:nowrap}
    .cv-tbl td{padding:.4rem .6rem;box-shadow:inset 0 -1px 0 var(--line);vertical-align:middle}
    .cv-tbl input[type=text]{width:100%;height:32px;min-height:32px;box-sizing:border-box;padding:0 .5rem;font-size:.82rem;border-radius:var(--radius)}
    .cv-tbl select{width:100%;height:32px;min-height:32px;box-sizing:border-box;padding:0 .4rem;font-size:.82rem;border-radius:var(--radius)}
    .cv-c-on{width:52px}.cv-c-key{width:15%}.cv-c-name{width:16%}.cv-c-os{width:9%}.cv-c-src{width:10%}.cv-c-ref{width:19%}.cv-c-how{width:12%}.cv-c-cmp{width:11%}.cv-c-cur{width:12%}.cv-c-del{width:44px}
    .cv-tbl .cv-del{padding:0;width:28px;height:28px;display:inline-flex;align-items:center;justify-content:center;border-radius:var(--radius);background:transparent;border:1px solid var(--line);color:var(--muted);cursor:pointer}
    .cv-tbl .cv-del:hover{border-color:var(--red);color:var(--red)}
    .cv-cur{display:flex;flex-direction:column;line-height:1.3}
    .cv-cur b{color:var(--text-strong);font-variant-numeric:tabular-nums;font-weight:600}
    .cv-cur span{color:var(--muted);font-size:.74rem}
    .cv-cur span.bad{color:var(--c-bad-fg)}
    .cv-grp{border:1px solid var(--line);border-radius:12px;margin-bottom:.6rem;overflow:hidden}
    .cv-grp>summary{cursor:pointer;padding:.6rem .85rem;font-weight:600;font-size:.86rem;background:var(--bg2);list-style:none;display:flex;align-items:center;gap:.5rem}
    .cv-grp>summary::-webkit-details-marker{display:none}
    .cv-grp>summary .n{color:var(--muted);font-weight:500;font-size:.8rem}
    .cv-list{display:flex;flex-direction:column}
    .cv-item{display:flex;align-items:center;gap:.7rem;padding:.5rem .85rem;border-top:1px solid var(--line);font-size:.84rem}
    .cv-item .i-n{font-weight:600;color:var(--text-strong);min-width:0;flex:0 1 15rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .cv-item .i-r{color:var(--muted);font-size:.78rem;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;flex:1 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .cv-item .btn{height:30px;min-height:30px;padding:0 .7rem;font-size:.79rem;flex:0 0 auto}
    .cv-seen td .mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
    .cv-hl{box-shadow:inset 0 0 0 2px var(--accent)!important}
    @media(max-width:900px){.cv-item{flex-wrap:wrap}.cv-item .i-n{flex:1 1 100%}}
    </style>
    <div class="seg">
        <a href="?tab=reqlog">Запросы</a>
        <a class="on" href="?tab=reqlog&amp;view=clients">Клиенты<?= $rl_outdated ? ' · ' . (int) $rl_outdated . ' устаревших' : '' ?></a>
    </div>

    <div class="card">
        <div class="loghead">
            <h2>Версии клиентов <span class="muted" style="font-weight:400;font-size:.78rem">строк в каталоге: <?= count($cv_rows) ?></span></h2>
            <div class="loghead-r">
                <span class="muted" style="font-size:.78rem"><?= $cv_checked ? 'последняя проверка: <span class="cv-ts" data-ts="' . (int) $cv_checked . '" data-f="1">' . h(gmdate('d.m.Y H:i', $cv_checked)) . '</span>' : 'проверок ещё не было' ?></span>
                <form method="post" style="margin:0" onsubmit="return cvBusy(this,'Проверяю источники…')">
                    <input type="hidden" name="csrf" value="<?= h($token) ?>">
                    <input type="hidden" name="action" value="clientver_refresh">
                    <button class="btn ghost" type="submit">Проверить сейчас</button>
                </form>
            </div>
        </div>
        <p class="muted">Прослойка узнаёт версию клиента из <code>User-Agent</code> и сравнивает с актуальной из источника. В логе запросов у отставших версий появляется точка, а в карточке — строка «Версия». Проверка идёт лениво: не чаще одного источника за заход в админку и не чаще раза в сутки на строку. Подписку это не трогает — запросы уходят только со страниц админки.</p>
        <p class="muted">Версия из <code>User-Agent</code> — это то, что сказал клиент. Часть приложений позволяет задать свой <code>User-Agent</code>, а некоторые штатно представляются чужими, поэтому отметка «вышло обновление» — подсказка, а не диагноз.</p>

        <form method="post">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="save_clientver">
            <div class="set-row">
                <div class="set-info">
                    <div class="set-t">Проверять версии клиентов</div>
                    <div class="set-d">Выключите, если сервер не должен ходить наружу за версиями. Каталог сохранится, но сравнение и точки в логе пропадут.</div>
                </div>
                <label class="switch"><input type="checkbox" name="cv_enabled" <?= clientver_enabled() ? 'checked' : '' ?>><span class="sl"></span></label>
            </div>
            <div class="cv-wrap" style="margin:.9rem 0 .7rem">
                <table class="cv-tbl">
                    <colgroup><col class="cv-c-on"><col class="cv-c-key"><col class="cv-c-name"><col class="cv-c-os"><col class="cv-c-src"><col class="cv-c-ref"><col class="cv-c-how"><col class="cv-c-cmp"><col class="cv-c-cur"><col class="cv-c-del"></colgroup>
                    <thead><tr><th>Вкл</th><th>Ключ UA</th><th>Имя</th><th>Платформа</th><th>Источник</th><th>Адрес</th><th>Способ</th><th>Сравнение</th><th>Актуальная</th><th></th></tr></thead>
                    <tbody id="cvBody">
                    <?php foreach ($cv_rows as $cv_i => $cv_r): $cv_err = clientver_row_err($cv_r); $cv_t = clientver_row_checked($cv_r); ?>
                        <tr id="<?= h(clientver_anchor($cv_r['k'], $cv_r['os'])) ?>">
                            <td><label class="chk" style="margin:0"><input type="checkbox" name="cv_on[<?= (int) $cv_i ?>]" <?= $cv_r['on'] ? 'checked' : '' ?>></label></td>
                            <td><input type="text" name="cv_k[<?= (int) $cv_i ?>]" value="<?= h($cv_r['k']) ?>" spellcheck="false"></td>
                            <td><input type="text" name="cv_n[<?= (int) $cv_i ?>]" value="<?= h($cv_r['n']) ?>"></td>
                            <td><select name="cv_os[<?= (int) $cv_i ?>]"><?= clientver_options(clientver_platforms(), $cv_r['os']) ?></select></td>
                            <td><select name="cv_src[<?= (int) $cv_i ?>]"><?= clientver_options(clientver_sources(), $cv_r['src']) ?></select></td>
                            <td><input type="text" name="cv_ref[<?= (int) $cv_i ?>]" value="<?= h($cv_r['ref']) ?>" spellcheck="false" placeholder="owner/repo или ID"></td>
                            <td><input type="text" name="cv_how[<?= (int) $cv_i ?>]" value="<?= h($cv_r['how']) ?>" spellcheck="false"></td>
                            <td><select name="cv_cmp[<?= (int) $cv_i ?>]"><?= clientver_options(clientver_modes(), $cv_r['cmp']) ?></select></td>
                            <td>
                                <?php if ($cv_r['src'] === 'man'): ?>
                                    <input type="text" name="cv_man[<?= (int) $cv_i ?>]" value="<?= h($cv_r['man']) ?>" spellcheck="false" placeholder="вписать вручную">
                                <?php else: ?>
                                    <input type="hidden" name="cv_man[<?= (int) $cv_i ?>]" value="<?= h($cv_r['man']) ?>">
                                    <span class="cv-cur">
                                        <b><?= clientver_latest($cv_r) !== '' ? h(clientver_latest($cv_r)) : '—' ?></b>
                                        <span class="<?= $cv_err !== '' ? 'bad' : '' ?>"><?= $cv_err !== '' ? h(mb_substr($cv_err, 0, 60)) : ($cv_t ? '<span class="cv-ts" data-ts="' . (int) $cv_t . '">' . h(gmdate('d.m H:i', $cv_t)) . '</span>' : 'не проверялась') ?></span>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td><button type="button" class="cv-del" data-tip="Удалить строку" aria-label="Удалить строку">✕</button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="muted" style="margin:.2rem 0 .9rem;font-size:.8rem">
                <b>Ключ UA</b> — первое слово из <code>User-Agent</code> в нижнем регистре. <b>Адрес</b>: для GitHub и Codeberg — <code>owner/repo</code>, для App Store — числовой ID приложения; произвольные ссылки не принимаются.
                <b>Способ</b>: <code>latest</code> — последний релиз; <code>tag:префикс</code> — последний тег с этим префиксом (когда в репозитории несколько линий версий); <code>json:путь</code> — файл из релиза, например плавающий манифест обновлений (когда все релизы помечены пре-релизами и <code>latest</code> не годится).
                <b>Платформа</b> нужна там, где у приложения несколько линий версий: строка с точной платформой имеет приоритет, строка «любая» подходит всем.
            </p>
            <div style="display:flex;gap:.5rem;flex-wrap:wrap">
                <button type="submit">Сохранить каталог</button>
                <button type="button" class="btn ghost" id="cvAdd">Добавить строку</button>
            </div>
        </form>
        <form method="post" onsubmit="return uiConfirmForm(this,'Вернуть каталог к встроенному? Ваши правки будут потеряны.')" style="margin:.7rem 0 0">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="clientver_reset">
            <button class="btn ghost" type="submit">Сбросить к встроенному</button>
        </form>
    </div>

    <div class="card">
        <h2>Встроенный каталог <span class="muted" style="font-weight:400;font-size:.78rem">клиентов: <?= count($cv_builtin) ?></span></h2>
        <p class="muted">Готовые строки — нажмите «Добавить», строка встанет наверх таблицы выше, останется нажать «Сохранить каталог».</p>
        <?php foreach ($cv_groups as $cv_gl => $cv_gitems): if (!$cv_gitems) continue; ?>
        <details class="cv-grp">
            <summary><?= h($cv_gl) ?> <span class="n"><?= count($cv_gitems) ?></span></summary>
            <div class="cv-list">
                <?php foreach ($cv_gitems as $cv_b): ?>
                <div class="cv-item">
                    <span class="i-n"><?= h($cv_b['n']) ?></span>
                    <span class="i-r"><?= h($cv_b['k']) ?><?= $cv_b['os'] !== '' ? ' · ' . h($cv_b['os']) : '' ?> · <?= h(clientver_sources()[$cv_b['src']] ?? '') ?><?= $cv_b['ref'] !== '' ? ' ' . h($cv_b['ref']) : '' ?></span>
                    <button type="button" class="btn ghost cv-pick" data-row="<?= h(json_encode($cv_b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">Добавить</button>
                </div>
                <?php endforeach; ?>
            </div>
        </details>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <h2>Замечены в логе, но не в каталоге <span class="muted" style="font-weight:400;font-size:.78rem">за неделю</span></h2>
        <?php if (!$cv_seen): ?>
        <p class="muted">Пусто — все клиенты из лога уже заведены.</p>
        <?php else: ?>
        <p class="muted">Ключи, которых нет в каталоге. «Завести» добавит строку наверх таблицы: если клиент есть во встроенном каталоге — сразу с источником, если нет — с источником «вручную», дальше выберите нужный.</p>
        <table class="logtbl cv-seen">
            <thead><tr><th>Ключ</th><th>Клиент</th><th>Версии</th><th>Запросов</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($cv_seen as $cv_s): ?>
            <tr>
                <td><code><?= h($cv_s['key']) ?></code></td>
                <td><?= h($cv_s['app']) ?><?= $cv_s['os'] !== '' ? ' <span class="muted">· ' . h(clientver_platforms()[$cv_s['os']] ?? $cv_s['os']) . '</span>' : '' ?></td>
                <td class="mono"><?= $cv_s['vers'] ? h(implode(', ', $cv_s['vers'])) : '<span class="muted">без версии</span>' ?></td>
                <td><?= (int) $cv_s['n'] ?></td>
                <td><button type="button" class="btn ghost cv-pick" data-row="<?= h(json_encode($cv_s['pick'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">Завести</button></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <script>
    window.cvBusy = function(f, label){
        var b = f.querySelector('button[type=submit]');
        if(b){
            b.classList.add('busy');
            b.textContent = label || 'Работаю…';
            setTimeout(function(){ b.disabled = true; }, 0);
        }
        return true;
    };
    (function(){
        var body = document.getElementById('cvBody');
        if(!body) return;
        var seq = <?= count($cv_rows) ?> + 1000;
        var PLAT = <?= json_encode(clientver_platforms(), JSON_UNESCAPED_UNICODE) ?>;
        var SRC  = <?= json_encode(clientver_sources(), JSON_UNESCAPED_UNICODE) ?>;
        var CMP  = <?= json_encode(clientver_modes(), JSON_UNESCAPED_UNICODE) ?>;
        function esc(s){ return String(s == null ? '' : s).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
        function opts(map, cur){
            var out = '';
            for(var k in map){ if(!Object.prototype.hasOwnProperty.call(map, k)) continue;
                out += '<option value="'+esc(k)+'"'+(String(cur) === k ? ' selected' : '')+'>'+esc(map[k])+'</option>'; }
            return out;
        }
        function addRow(r){
            r = r || {};
            var i = seq++;
            var tr = document.createElement('tr');
            tr.innerHTML =
                '<td><label class="chk" style="margin:0"><input type="checkbox" name="cv_on['+i+']"'+(r.on === 0 ? '' : ' checked')+'></label></td>'+
                '<td><input type="text" name="cv_k['+i+']" value="'+esc(r.k)+'" spellcheck="false"></td>'+
                '<td><input type="text" name="cv_n['+i+']" value="'+esc(r.n)+'"></td>'+
                '<td><select name="cv_os['+i+']">'+opts(PLAT, r.os || '')+'</select></td>'+
                '<td><select name="cv_src['+i+']">'+opts(SRC, r.src || 'man')+'</select></td>'+
                '<td><input type="text" name="cv_ref['+i+']" value="'+esc(r.ref)+'" spellcheck="false" placeholder="owner/repo или ID"></td>'+
                '<td><input type="text" name="cv_how['+i+']" value="'+esc(r.how || 'latest')+'" spellcheck="false"></td>'+
                '<td><select name="cv_cmp['+i+']">'+opts(CMP, r.cmp || 'auto')+'</select></td>'+
                '<td><input type="text" name="cv_man['+i+']" value="'+esc(r.man)+'" spellcheck="false" placeholder="вписать вручную"></td>'+
                '<td><button type="button" class="cv-del" data-tip="Удалить строку" aria-label="Удалить строку">✕</button></td>';
            body.insertBefore(tr, body.firstChild);
            tr.classList.add('cv-hl');
            setTimeout(function(){ tr.classList.remove('cv-hl'); }, 1200);
            var f = tr.querySelector('input[name^="cv_k"]');
            if(f && !r.k) f.focus();
            return tr;
        }
        document.getElementById('cvAdd').addEventListener('click', function(){ addRow({}); });
        document.addEventListener('click', function(e){
            var p = e.target.closest('.cv-pick');
            if(p){
                var data = {};
                try { data = JSON.parse(p.getAttribute('data-row') || '{}'); } catch(err) { data = {}; }
                addRow(data);
                if(window.uiToast) uiToast('Строка добавлена наверх — проверьте и нажмите «Сохранить каталог»');
                var w = document.querySelector('.cv-wrap');
                if(w) w.scrollIntoView({behavior:'smooth', block:'center'});
                return;
            }
            var d = e.target.closest('.cv-del');
            if(d){
                var tr = d.closest('tr');
                if(tr) tr.parentNode.removeChild(tr);
            }
        });
        function p2(n){ return (n < 10 ? '0' : '') + n; }
        document.querySelectorAll('.cv-ts[data-ts]').forEach(function(el){
            var ep = parseInt(el.getAttribute('data-ts'), 10);
            if(!ep) return;
            var d = new Date(ep * 1000);
            if(isNaN(d.getTime())) return;
            var s = p2(d.getDate())+'.'+p2(d.getMonth()+1)+(el.getAttribute('data-f') ? '.'+d.getFullYear() : '')+' '+p2(d.getHours())+':'+p2(d.getMinutes());
            el.textContent = s;
        });
        if(location.hash && location.hash.length > 1){
            var t = document.getElementById(location.hash.slice(1));
            if(t){ t.classList.add('cv-hl'); t.scrollIntoView({block:'center'}); }
        }
    })();
    </script>
