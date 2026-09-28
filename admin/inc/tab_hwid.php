    <style>#hw_blocked{field-sizing:content;min-height:53px;max-height:70vh;resize:none;overflow-y:auto;padding-bottom:calc(.6rem + 1lh)}</style>
    <div class="card">
        <h2 style="margin-top:0;font-size:1rem">HWID / ручная блокировка</h2>
        <p class="muted">Что увидит юзер, заблокированный по HWID (вкладка «Пользователи» → Устройства → Блок) или вручную (вкладка <a href="?tab=overrides" style="color:var(--accent-text)">Оверрайды</a>, причина <b>blocked</b>). Снимается только там же. Жёсткая блокировка не зависит от грейс-периода.</p>
        <form method="post" data-autosave>
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="save_hwid">
            <div class="subwrap">
                <div class="subedit">
                    <label style="margin-top:0">Ремарки для ЗАБЛОКИРОВАННОЙ подписки</label>
                    <textarea id="hw_blocked" rows="1" name="blocked_remarks" oninput="hwRender()"><?= h($blocked_text) ?></textarea>
                    <p class="muted" style="margin-top:.4rem">Каждая строка = отдельный «сервер»-заглушка в списке клиента. Сюда обычно пишут контакт поддержки.</p>
                    <div style="margin-top:1rem"><button type="submit">💾 Сохранить</button></div>
                </div>
                <div class="subprev">
                    <label style="margin-top:0">Превью в клиенте</label>
                    <div class="phone">
                        <div class="ph-top">
                            <div class="ph-app">VPN-клиент · подписка</div>
                            <div class="ph-title" id="hw_pvtitle">—</div>
                            <div class="ph-sub" id="hw_pvsub"></div>
                        </div>
                        <div class="ph-list" id="hw_pvlist"></div>
                    </div>
                </div>
            </div>
        </form>
    </div>
    <script>
    function hwEsc(s){var d=document.createElement('div');d.textContent=(s==null?'':s);return d.innerHTML;}
    function hwRender(){
        var el=document.getElementById('hw_blocked');
        var rows=(el?el.value.split('\n'):[]).map(function(s){return s.trim();}).filter(function(s){return s.length;}).map(function(n){return '<div class="srow"><span class="dot"></span><span class="nm">'+hwEsc(n)+'</span><span class="pg">—</span></div>';});
        var te=document.getElementById('hw_pvtitle'); if(te) te.textContent='(как у origin)';
        var se=document.getElementById('hw_pvsub'); if(se) se.textContent='Сценарий: подписка заблокирована';
        var le=document.getElementById('hw_pvlist'); if(le) le.innerHTML=rows.length?rows.join(''):'<div class="ph-empty">пусто — добавьте строки слева</div>';
    }
    hwRender();
    (function(){var t=document.getElementById('hw_blocked');if(!t||(window.CSS&&CSS.supports&&CSS.supports('field-sizing','content')))return;function fit(){t.style.height='auto';t.style.height=(t.scrollHeight+t.offsetHeight-t.clientHeight)+'px';}t.addEventListener('input',fit);addEventListener('resize',fit);fit();})();
    </script>
