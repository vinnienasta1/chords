<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/db.php';
ensure_session_started();
DB::init();

// Проверяем, авторизован ли пользователь
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    header('Location: /auth.php?redirect=' . urlencode('/newer.php'));
    exit;
}

$username = $_SESSION['username'] ?? '';
$db = DB::getConnection();

// Проверяем активность пользователя
$stmt = $db->prepare('SELECT username, full_name, active, is_admin FROM users WHERE username = ?');
$stmt->execute([$username]);
$user = $stmt->fetch();

// Если пользователь активирован, редиректим на главную
if ($user && isset($user['active']) && (int)$user['active'] === 1) {
    header('Location: /');
    exit;
}

$displayName = trim($user['full_name'] ?? '') !== '' ? trim($user['full_name']) : $username;
?>
<!DOCTYPE html>
<html lang='ru'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Ожидание активации</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="alternate icon" href="/favicon.svg">
    <script>
        // Ранняя инициализация темы до загрузки CSS
        (function() {
            try {
                const saved = localStorage.getItem('vinnie_chords_theme');
                const theme = saved && ['dark', 'dark2', 'light1', 'light2'].includes(saved) ? saved : 'dark';
                document.documentElement.setAttribute('data-theme', theme);
            } catch(e) {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
        })();
    </script>
    <link rel="stylesheet" href="/common.css">
    <style>
        /* Цветовые переменные берутся из common.css через data-theme */
        * { box-sizing:border-box; }
        body { margin:0; font-family:'Inter',Arial,sans-serif; background:var(--bg-gradient); color:var(--text); min-height:100vh; display:flex; align-items:center; justify-content:center; padding:1.5rem; }
        .sidebar, .brand, .nav { display:none; }
        .content { width:100%; max-width:600px; }
        .card { background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.06); border-radius:14px; padding:2rem; text-align:center; }
        h1 { margin:0 0 1.5rem; font-size:2rem; color:var(--accent); }
        .message { background:rgba(102,126,234,0.15); border:1px solid rgba(102,126,234,0.3); border-radius:10px; padding:1.5rem; margin-bottom:1.5rem; }
        .message-icon { font-size:3rem; margin-bottom:1rem; }
        .message-text { font-size:1.1rem; line-height:1.6; color:var(--text); }
        .info-section { margin-top:2rem; padding-top:2rem; border-top:1px solid rgba(255,255,255,0.1); text-align:left; }
        .info-section h2 { font-size:1.3rem; margin:0 0 1rem; color:var(--accent); }
        .info-section p { color:var(--muted); line-height:1.6; margin:0 0 1rem; }
        .info-section ul { color:var(--muted); line-height:1.8; margin:0; padding-left:1.5rem; }
        .info-section li { margin-bottom:0.5rem; }
        .logout-link { display:inline-block; margin-top:1.5rem; padding:0.75rem 1.5rem; background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.15); border-radius:10px; color:var(--text); text-decoration:none; transition:all 0.2s; }
        .logout-link:hover { background:rgba(255,255,255,0.12); border-color:rgba(255,255,255,0.25); }
        @media (max-width: 480px) {
            .card { padding: 1.5rem; }
            h1 { font-size: 1.6rem; }
            .message-text { font-size: 1rem; }
        }
    </style>
</head>
<body>
    <div class="layout">
        <main class="content">
            <div class="card">
                <h1>Добро пожаловать, <?php echo htmlspecialchars($displayName); ?>!</h1>
                
                <div class="message">
                    <div class="message-icon">⏳</div>
                    <div class="message-text">
                        Ваш аккаунт ожидает активации администратором.<br>
                        После проверки вы получите полный доступ к сайту.
                    </div>
                </div>

                <div class="info-section">
                    <h2>О сайте</h2>
                    <p>Это платформа для работы с аккордами и песнями. Здесь вы сможете:</p>
                    <ul>
                        <li>Просматривать коллекцию песен с аккордами</li>
                        <li>Создавать и редактировать сет-листы</li>
                        <li>Управлять своим профилем</li>
                    </ul>
                    <p style="margin-top: 1rem; color: var(--muted); font-size: 0.95rem;">
                        Мы проверяем каждый новый аккаунт для обеспечения безопасности. 
                        Обычно активация занимает несколько минут.
                    </p>
                </div>

                <a href="/logout.php" class="logout-link">Выйти из аккаунта</a>
            </div>
        </main>
    </div>
    <script src="/js/theme-switcher.js"></script>
</body>
</html>
