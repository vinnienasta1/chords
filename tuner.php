<?php
$username = require __DIR__ . '/includes/auth_required.php';
require_once __DIR__ . '/includes/user_helper.php';
require_once __DIR__ . '/includes/layout_helper.php';

$userData = getCurrentUser($username);

renderHead('Тюнер');
?>
<body>
    <style>
        .layout { display:block; min-height:100vh; height:100vh; overflow-y:auto; }
        .sidebar { background:var(--panel); padding:1.2rem; border-right:1px solid var(--border); position:fixed; top:0; left:0; bottom:0; width:260px; overflow:auto; z-index:3000; }
        .content { padding:1rem 1.25rem 1.5rem; margin-left:260px; max-width:980px; width:100%; }
        .card { background:var(--card-bg); border:1px solid var(--border); border-radius:14px; padding:1rem 1.1rem; margin-bottom:0.85rem; }
        h1 { margin:0 0 0.5rem; font-size:2rem; }
        .muted { color:var(--muted); }
        .tuner-grid { display:grid; gap:1rem; grid-template-columns:1fr; }
        .tuner-main { display:grid; gap:0.9rem; grid-template-columns:1fr; align-items:center; }
        .meter { background:var(--panel); border:1px solid var(--border); border-radius:14px; padding:0.85rem; position:relative; overflow:hidden; transition:background 0.15s ease, border-color 0.15s ease; }
        .meter.tuner-locked { background:#12381c; border-color:#21c45c; }
        .meter-needle-wrap { position:relative; height:190px; display:flex; align-items:flex-end; justify-content:center; }
        .meter-needle-bg { position:absolute; inset:0; display:flex; align-items:flex-end; justify-content:center; pointer-events:none; }
        .meter-needle { width:7px; height:140px; background:var(--accent); border-radius:999px; transform-origin:50% 100%; box-shadow:0 6px 18px rgba(0,0,0,0.3); transition:transform 0.12s ease-out; }
        .meter-note {
            position:absolute;
            top:50%;
            left:50%;
            transform:translate(-50%,-50%);
            width:100%;
            text-align:center;
            font-size:4.6rem !important;
            font-weight:900;
            color:var(--text);
            text-shadow:0 4px 18px rgba(0,0,0,0.35);
            pointer-events:none;
            letter-spacing:0.04em;
            z-index:2;
            line-height:1;
        }
        .meter-scale { display:flex; justify-content:space-between; font-size:0.9rem; color:var(--muted); margin-top:0.35rem; }
        .readings { display:grid; gap:0.6rem; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); }
        .pill { padding:0.7rem 0.85rem; border-radius:12px; border:1px solid var(--border); background:var(--panel); }
        .pill .label { color:var(--muted); font-size:0.9rem; }
        .pill .value { font-size:1.4rem; font-weight:700; color:var(--text); }
        .controls { display:flex; gap:0.6rem; flex-wrap:wrap; }
        .btn { padding:0.65rem 1.1rem; border-radius:10px; border:1px solid var(--border); background:var(--accent); color:#fff; font-weight:700; cursor:pointer; box-shadow:0 6px 16px rgba(0,0,0,0.25); }
        .btn.secondary { background:transparent; color:var(--text); }
        .status { padding:0.7rem 0.9rem; border-radius:12px; border:1px solid var(--border); background:var(--panel); color:var(--muted); }
        @media (max-width: 960px) {
            .sidebar { position:fixed; inset:0 auto 0 0; width:240px; transform:translateX(-260px); transition:transform 0.2s ease; z-index:3000; }
            .content { padding:0.85rem 0.9rem 1rem; margin-left:0; }
            .tuner-main { grid-template-columns:1fr; }
            .meter-needle-wrap { height:180px; }
        }

        /* Pro-тюнер (стиль GitarTuna-like) */
        .pro-card { display:grid; gap:0.85rem; grid-template-columns:1fr; }
        .pro-top { display:flex; gap:0.8rem; align-items:stretch; flex-wrap:wrap; }
        .pro-graph { position:relative; width:280px; height:170px; border-radius:14px; border:1px solid var(--border); background:linear-gradient(180deg, color-mix(in srgb, var(--panel) 60%, transparent), color-mix(in srgb, var(--panel) 80%, transparent)); overflow:hidden; box-shadow:0 12px 30px color-mix(in srgb, var(--accent) 14%, transparent); }
        .pro-graph canvas { position:absolute; inset:0; width:100%; height:100%; }
        .pro-graph .midline { position:absolute; left:0; right:0; top:50%; height:1px; background:var(--border); opacity:0.5; }
        .pro-graph-note { position:absolute; left:50%; top:50%; transform:translate(-50%,-50%); font-size:2.6rem; font-weight:900; letter-spacing:0.05em; color:var(--text); text-shadow:0 4px 14px rgba(0,0,0,0.38); pointer-events:none; z-index:2; }
        .pro-info { flex:1; min-width:200px; border:1px solid var(--border); border-radius:14px; padding:0.9rem; background:var(--panel); display:grid; gap:0.35rem; }
        .pro-note { font-size:2.8rem; font-weight:900; letter-spacing:0.04em; }
        .pro-delta { color:var(--muted); font-size:0.95rem; }
        .pro-target { font-weight:700; color:var(--text); }
        .headstock { border:1px solid var(--border); border-radius:14px; padding:0.75rem 0.9rem; background:color-mix(in srgb, var(--panel) 80%, transparent); }
        .headstock h3 { margin:0 0 0.35rem; font-size:0.95rem; color:var(--muted); }
        .strings { display:grid; gap:0.35rem; }
        .string-row { display:flex; align-items:center; justify-content:space-between; padding:0.5rem 0.7rem; border-radius:10px; border:1px dashed var(--border); background:color-mix(in srgb, var(--panel) 75%, transparent); }
        .string-row.active { border-color:color-mix(in srgb, var(--accent) 70%, var(--border)); background:color-mix(in srgb, var(--accent) 12%, var(--panel)); box-shadow:0 6px 16px color-mix(in srgb, var(--accent) 22%, transparent); }
        .string-row.tuned { border-color:#21c45c; background:color-mix(in srgb, #21c45c 18%, var(--panel)); box-shadow:0 6px 16px rgba(33,196,92,0.25); }
        .string-name { font-weight:800; font-size:1.1rem; }
        .string-target { color:var(--muted); font-size:0.95rem; }
        /* Tabs */
        .tabs { display:flex; gap:0.5rem; margin-bottom:0.8rem; flex-wrap:wrap; }
        .tab-btn { padding:0.65rem 0.9rem; border-radius:10px; border:1px solid var(--border); background:var(--panel); color:var(--text); cursor:pointer; transition:0.2s; font-weight:700; }
        .tab-btn.active { background:color-mix(in srgb, var(--accent) 28%, transparent); border-color:color-mix(in srgb, var(--accent) 55%, transparent); box-shadow:0 10px 24px color-mix(in srgb, var(--accent) 20%, transparent); }
        .tab-panel { display:none; }
        .tab-panel.active { display:block; }
        @media (max-width: 960px) {
            .pro-top { flex-direction:column; }
            .pro-graph { width:100%; max-width:220px; margin:0 auto; }
            .pro-info { width:100%; }
            h1 { text-align:center; }
        }
    </style>
    <div class="layout">
        <?php renderSidebar($userData, 'tuner'); ?>
        <main class="content">
            <div class="card">
                <h1>Тюнер</h1>
            </div>
            <div class="card">
                <div class="tabs">
                    <button class="tab-btn active" data-tab="pro">Тюнер Pro</button>
                    <button class="tab-btn" data-tab="chromatic">Хроматический</button>
                </div>
                <div class="tab-panel active" data-tab-panel="pro">
                    <h2 style="margin:0 0 0.5rem;">Тюнер Pro</h2>
                    <p class="muted" style="margin-top:0;">Автоопределение струны. Наведи ноту к центру полосы.</p>
                    <div class="pro-card">
                        <div class="pro-top">
                            <div class="pro-graph">
                                <div class="midline"></div>
                                <canvas id="pro-osc"></canvas>
                                <div class="pro-graph-note" id="pro-note">—</div>
                            </div>
                            <div class="pro-info">
                                <div class="pro-delta" id="pro-delta">—</div>
                                <div class="pro-target">Струна: <span id="pro-string">—</span></div>
                            </div>
                        </div>
                        <div class="headstock">
                            <h3>Струны (EADGBE)</h3>
                            <div class="strings" id="pro-strings">
                                <div class="string-row" data-string="E4"><span class="string-name">1 (E4)</span><span class="string-target">329.63 Hz</span></div>
                                <div class="string-row" data-string="B3"><span class="string-name">2 (B3)</span><span class="string-target">246.94 Hz</span></div>
                                <div class="string-row" data-string="G3"><span class="string-name">3 (G3)</span><span class="string-target">196.00 Hz</span></div>
                                <div class="string-row" data-string="D3"><span class="string-name">4 (D3)</span><span class="string-target">146.83 Hz</span></div>
                                <div class="string-row" data-string="A2"><span class="string-name">5 (A2)</span><span class="string-target">110.00 Hz</span></div>
                                <div class="string-row" data-string="E2"><span class="string-name">6 (E2)</span><span class="string-target">82.41 Hz</span></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="tab-panel" data-tab-panel="chromatic">
                    <h2 style="margin:0 0 0.5rem;">Хроматический</h2>
                    <p class="muted" style="margin-top:0;">Работает со всеми нотами, без фиксации струны.</p>
                    <div class="tuner-grid">
                        <div class="tuner-main">
                            <div class="meter">
                                <div class="meter-needle-wrap">
                                    <div class="meter-note" id="tuner-note-main">—</div>
                                    <div class="meter-needle-bg"></div>
                                    <div class="meter-needle" id="tuner-needle"></div>
                                </div>
                                <div class="meter-scale"><span>-50¢</span><span>0¢</span><span>+50¢</span></div>
                            </div>
                            <div class="readings">
                                <div class="pill">
                                    <div class="label">Отклонение</div>
                                    <div class="value" id="tuner-cents">— ¢</div>
                                </div>
                                <div class="pill">
                                    <div class="label">Сигнал</div>
                                    <div class="value" id="tuner-level">—</div>
                                </div>
                            </div>
                        </div>
                        <div class="status" id="tuner-status">Готов к прослушиванию</div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    <script src="/js/sidebar-cache.js"></script>
    <?php renderLayoutScripts(); ?>
    <script src="/js/tuner.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const tabs = document.querySelectorAll('.tab-btn');
            const panels = document.querySelectorAll('.tab-panel');
            tabs.forEach(btn => {
                btn.addEventListener('click', () => {
                    const tab = btn.dataset.tab;
                    tabs.forEach(b => b.classList.toggle('active', b === btn));
                    panels.forEach(p => p.classList.toggle('active', p.dataset.tabPanel === tab));
                });
            });
        });
    </script>
</body>
</html>
