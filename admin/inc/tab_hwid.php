    <div class="card">
        <h2 style="margin-top:0;font-size:1rem">HWID / ручная блокировка</h2>
        <p class="muted">Что увидит юзер, заблокированный по HWID (вкладка «Пользователи» → Устройства → Блок) или вручную (вкладка <a href="?tab=overrides" style="color:var(--accent-text)">Оверрайды</a>, причина <b>blocked</b>). Снимается только там же. Жёсткая блокировка не зависит от грейс-периода.</p>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="save_hwid">
            <div class="subwrap">
                <div class="subedit">
                    <label style="margin-top:0">Ремарки для ЗАБЛОКИРОВАННОЙ подписки</label>
                    <textarea id="hw_blocked" name="blocked_remarks" oninput="hwRender()"><?= h($blocked_text) ?></textarea>
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
    function hwRender(){ phRender({list:['hw_blocked'], titleText:'(как у origin)', titleEl:'hw_pvtitle', subEl:'hw_pvsub', listEl:'hw_pvlist', sub:'Сценарий: подписка заблокирована'}); }
    hwRender();
    </script>
