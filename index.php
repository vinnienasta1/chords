<?php
$username = require __DIR__ . '/includes/auth_required.php';
require_once __DIR__ . '/includes/user_helper.php';
require_once __DIR__ . '/includes/layout_helper.php';

$userData = getCurrentUser($username);
?>
<?php renderHead('Vinnie chords — Коллекция песен и аккордов'); ?>
<body>
    <div class="layout">
        <!-- Sidebar будет загружен через JavaScript -->
        <main class="content">
            <div class="card">
                <h1>Vinnie chords</h1>
                <p style="font-size: 1.1rem; margin-top: 0.8rem; line-height: 1.7;">
                    Коллекция песен с аккордами для гитары. Система для хранения, поиска и организации репертуара.
                </p>
                
                <div style="margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid var(--border);">
                    <h2 style="font-size: 1.3rem; margin: 0 0 0.8rem; color: var(--text);">Возможности:</h2>
                    <ul class="features-list">
                        <li>Просмотр песен с аккордами и текстами</li>
                        <li>Транспонирование аккордов для удобной тональности</li>
                        <li>Автопрокрутка при исполнении песен</li>
                        <li>Создание и управление сет-листами для концертов</li>
                        <li>Поиск и фильтрация по названию, исполнителю, сложности</li>
                        <li>Настройка отображения: размер шрифта, стиль аккордов, двухколоночный режим</li>
                    </ul>
                </div>
                
                <div class="links">
                    <a href="/songs.php" class="btn">Песни</a>
                    <a href="/setlists.php" class="btn outline">Сет-листы</a>
                </div>
            </div>
        </main>
    </div>
    
    <style>
        /* Стили определены в common.css и components.css */
        .layout { display:block; height:100vh; overflow-y:auto; }
        .content { padding:1.5rem 2rem; margin-left:260px; }
        .card { background:var(--card-bg, rgba(255,255,255,0.04)); border:1px solid var(--border); border-radius:14px; padding:1.4rem; margin-bottom:1rem; }
        h1 {
            margin: 0 0 0.5rem;
            font-size: 2.2rem;
        }
        p {
            margin: 0.4rem 0 0;
            color: var(--muted);
            line-height: 1.6;
        }
        .links {
            margin-top: 1.2rem;
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        .links .btn {
            text-decoration: none;
        }
        .btn.outline {
            background: transparent;
            border: 1px solid var(--btn-outline-border, var(--border));
            color: var(--btn-outline-text, var(--text));
        }
        .btn.outline:hover {
            background: color-mix(in srgb, var(--accent) 10%, transparent);
            border-color: var(--accent);
            color: var(--accent);
        }
        .features-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .features-list li {
            padding: 0.4rem 0;
            padding-left: 1.5rem;
            position: relative;
            color: var(--muted);
            line-height: 1.8;
        }
        .features-list li::before {
            content: "→";
            position: absolute;
            left: 0;
            color: var(--accent);
            font-weight: bold;
        }
        @media (max-width: 960px) {
            .layout { display:block; }
            .sidebar {
                position:fixed;
                inset:0 auto 0 0;
                width:240px;
                transform:translateX(-260px);
                transition:transform 0.2s ease;
                z-index:3000;
            }
            .sidebar.open { transform:translateX(0); }
            .content { padding:0.85rem 0.9rem 1rem; margin-left:0; }
            .toggle {
                position:fixed;
                top:10px;
                left:10px;
                z-index:11;
                padding:0.55rem 0.85rem;
                border-radius:10px;
                border:1px solid var(--border);
                background:color-mix(in srgb, var(--panel) 80%, transparent);
                color:var(--text);
                cursor:pointer;
                font-size: 1.1rem;
                min-width: 40px;
                min-height: 40px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .topbar {
                margin-top: 0.75rem;
            }
            .card {
                padding: 1.05rem;
            }
            h1 {
                font-size: 1.75rem;
                text-align: center;
            }
            .links {
                flex-direction: column;
            }
            .links .btn {
                width: 100%;
                text-align: center;
            }
        }
        @media (max-width: 480px) {
            .content { padding: 0.75rem; }
            .card {
                padding: 0.95rem;
            }
            h1 {
                font-size: 1.5rem;
                text-align: center;
            }
            .toggle {
                top: 8px;
                left: 8px;
                padding: 0.5rem 0.75rem;
            }
        }
    </style>
    
    <script src="/js/sidebar-cache.js"></script>
    <?php renderLayoutScripts(); ?>
</body>
</html>
