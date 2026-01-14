<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/db.php';
ensure_session_started();
DB::init();

if (isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true) {
    header('Location: /');
    exit;
}

$error = '';
$success = '';
$db = DB::getConnection();
$action = $_GET['action'] ?? 'login'; // 'login' или 'register'

// Простые запрещенные пароли
$botLink = getenv('TELEGRAM_BOT_LINK') ?: 'https://t.me/vinnienasta_bot';
$verificationKey = $_SESSION['tg_reg_key'] ?? null;
$verificationUsername = $_SESSION['tg_reg_username'] ?? null;
$verificationStatus = null;

function normalizeTelegramLogin($raw) {
    $s = trim($raw ?? '');
    // убираем t.me/ и https://t.me/
    $s = preg_replace('~^https?://t\\.me/~i', '', $s);
    $s = preg_replace('~^t\\.me/~i', '', $s);
    // убираем ведущие @
    $s = ltrim($s, "@ \t\n\r\0\x0B");
    return $s;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $redirect = $_POST['redirect'] ?? '/';
    if (!is_safe_redirect($redirect)) { $redirect = '/'; }
    
    $formAction = $_POST['form_action'] ?? 'login';
    
if ($formAction === 'register') {
        // Создаём запрос верификации через бота, без создания пользователя и без пароля
        $raw = $_POST['username'] ?? '';
        $username = normalizeTelegramLogin($raw);
        if (empty($username)) {
            $error = 'Telegram логин обязателен';
        } elseif (strlen($username) < 3) {
            $error = 'Имя пользователя должно быть не короче 3 символов';
        } else {
            // проверка занятости по username или telegram (учитываем варианты с @)
            $userNoAt = $username;
            $userWithAt = '@' . $username;
            $stmt = $db->prepare('SELECT id FROM users WHERE username IN (?, ?) OR telegram IN (?, ?)');
            $stmt->execute([$userNoAt, $userWithAt, $userWithAt, $userNoAt]);
            if ($stmt->fetch()) {
                $error = 'Данный пользователь уже зарегистрирован';
            } else {
                $payload = [
                    'telegram_username' => $username,
                    'require_password' => true,
                ];
                $ch = curl_init('http://192.168.3.110:8080/create_verification');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($payload),
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                    CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_TIMEOUT => 6,
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                ]);
                $resp = curl_exec($ch);
                curl_close($ch);
                $data = json_decode($resp ?: '', true);
                if (!is_array($data) || ($data['status'] ?? '') !== 'success') {
                    $error = 'Не удалось создать запрос верификации. Попробуйте позже.';
                } else {
                    $verificationKey = $data['verification_key'] ?? null;
                    $verificationUsername = $payload['telegram_username'];
                    $_SESSION['tg_reg_key'] = $verificationKey;
                    $_SESSION['tg_reg_username'] = $verificationUsername;
                    $verificationStatus = 'pending';
                    $success = 'Заявка отправлена. Откройте бота и следуйте инструкциям.';
                    $botLink = $data['bot_url'] ?? $botLink;
                }
            }
        }
        // AJAX ответ для фронта
        if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
            header('Content-Type: application/json');
            if ($error) {
                echo json_encode(['ok' => false, 'error' => $error]);
            } else {
                echo json_encode([
                    'ok' => true,
                    'status' => $verificationStatus,
                    'bot_url' => $botLink,
                ]);
            }
            exit;
        }
    } else {
        // Вход в систему (логин = Telegram логин или username)
        $usernameRaw = trim($_POST['username'] ?? '');
        $usernameNorm = normalizeTelegramLogin($usernameRaw);
        $password = $_POST['password'] ?? '';

        $stmt = $db->prepare('SELECT username, password_hash, active FROM users WHERE username IN (?, ?) OR telegram IN (?, ?)');
        $stmt->execute([$usernameNorm, '@' . $usernameNorm, '@' . $usernameNorm, $usernameNorm]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            // Проверяем активность пользователя
            if (!isset($user['active']) || (int)$user['active'] === 0) {
                // Пользователь не активирован - редиректим на страницу ожидания
                session_regenerate_id(true);
                $_SESSION['authenticated'] = true;
                $_SESSION['username'] = $user['username'];
                header('Location: /newer.php');
                exit;
            }
            
            session_regenerate_id(true);
            $_SESSION['authenticated'] = true;
            $_SESSION['username'] = $user['username'];
            header('Location: ' . $redirect);
            exit;
        } else {
            $error = 'Неверные учётные данные';
        }
    }
}
$redirect = $_GET['redirect'] ?? '/';
if (!is_safe_redirect($redirect)) { $redirect = '/'; }
$initial = 'G';
$statusText = '';
// Периодический опрос статуса, если есть ключ
if ($verificationKey) {
    $ch = curl_init('http://192.168.3.110:8080/check_verification');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['verification_key' => $verificationKey]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp ?: '', true);
    if (is_array($data) && isset($data['status'])) {
        $verificationStatus = $data['status'];
        if ($verificationStatus === 'awaiting_password') {
            $statusText = 'Откройте бота и введите пароль. Заявка в ожидании пароля.';
        } elseif ($verificationStatus === 'pending') {
            $statusText = 'Ожидание подтверждения в боте.';
        } elseif ($verificationStatus === 'approved') {
            $statusText = 'Готово! Введите свой Telegram логин и пароль (который задали в боте) для входа.';
            // Можно очистить сессию ключа
            unset($_SESSION['tg_reg_key'], $_SESSION['tg_reg_username']);
        } elseif ($verificationStatus === 'cancelled' || $verificationStatus === 'expired') {
            $statusText = 'Заявка отменена или истекла. Создайте новую.';
        }
    }
}
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang='ru'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Авторизация</title>
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
        body { margin:0; font-family:'Inter',Arial,sans-serif; background:var(--bg-gradient); color:var(--text); height:100vh; overflow:hidden; }
        .layout { display:flex; justify-content:center; align-items:center; height:100vh; padding:1.5rem; overflow-y:auto; }
        .sidebar, .brand, .nav { display:none; }
        .content { width:100%; display:flex; justify-content:center; align-items:center; }
        .card { background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.06); border-radius:14px; padding:1.6rem; width:100%; max-width:420px; }
        h1 { margin:0 0 1rem; text-align:center; }
        .form-group { margin-bottom:1rem; }
        label { display:block; margin-bottom:0.35rem; color:var(--muted); font-weight:600; }
        input[type='text'], input[type='password'] { width:100%; padding:0.75rem; border-radius:10px; border:1px solid rgba(255,255,255,0.12); background:rgba(255,255,255,0.04); color:var(--text); }
        input:focus { outline:none; border-color:rgba(102,126,234,0.7); }
        .error { background:rgba(231,76,60,0.12); border:1px solid rgba(231,76,60,0.35); color:#f6a9a0; padding:0.8rem 1rem; border-radius:10px; margin-bottom:1rem; }
        button { width:100%; padding:0.8rem; border-radius:10px; background:var(--accent); color:#fff; border:none; font-weight:700; cursor:pointer; }
        .topbar, .user-pill { display:none; }
        @media (max-width:960px) {
            .layout { padding:1rem; }
            .content { padding:0; }
            .card {
                padding: 1.4rem;
            }
            h1 {
                font-size: 1.75rem;
            }
            input[type='text'],
            input[type='password'] {
                font-size: 16px;
            }
        }
        
        @media (max-width: 480px) {
            .layout { padding: 0.75rem; }
            .card {
                padding: 1.2rem;
            }
            h1 {
                font-size: 1.5rem;
            }
            button {
                padding: 0.75rem;
                font-size: 1rem;
            }
        }
        
        .tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .tab-btn {
            flex: 1;
            padding: 0.75rem;
            background: transparent;
            border: none;
            border-bottom: 2px solid transparent;
            color: var(--muted);
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            transition: all 0.2s;
        }
        .tab-btn:hover {
            color: var(--text);
        }
        .tab-btn.active {
            color: var(--accent);
            border-bottom-color: var(--accent);
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        .success {
            background: rgba(46,204,113,0.12);
            border: 1px solid rgba(46,204,113,0.35);
            color: #7ef2b5;
            padding: 0.8rem 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
        }
        small {
            display: block;
            margin-top: 0.25rem;
            color: var(--muted);
            font-size: 0.85rem;
        }
        .password-hint-icon {
            display: inline-block;
            width: 18px;
            height: 18px;
            line-height: 18px;
            text-align: center;
            border-radius: 50%;
            background: rgba(102,126,234,0.2);
            border: 1px solid rgba(102,126,234,0.4);
            font-size: 0.85rem;
            font-weight: bold;
            margin-left: 0.5rem;
            cursor: help;
            color: var(--accent);
            vertical-align: middle;
        }
        .password-hint-icon:hover {
            background: rgba(102,126,234,0.3);
            border-color: rgba(102,126,234,0.6);
        }
    </style>
</head>
<body>
    <div class="layout">
        <main class="content">
            <div class="card">
                <h1>Авторизация</h1>
                
                <div class="tabs">
                    <button type="button" class="tab-btn <?php echo $action === 'login' ? 'active' : ''; ?>" onclick="showTab('login')">Вход</button>
                    <button type="button" class="tab-btn <?php echo $action === 'register' ? 'active' : ''; ?>" onclick="showTab('register')">Регистрация</button>
                </div>
                
                <?php if ($error): ?><div class='error'><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
                <?php if ($success): ?><div class='success'><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
                
                <!-- Форма входа -->
                <div id="login-tab" class="tab-content <?php echo $action === 'login' ? 'active' : ''; ?>">
                    <form method='POST' action=''>
                        <input type='hidden' name='redirect' value='<?php echo htmlspecialchars($redirect); ?>'>
                        <input type='hidden' name='csrf_token' value='<?php echo htmlspecialchars($csrf); ?>'>
                        <input type='hidden' name='form_action' value='login'>
                        <div class='form-group'>
                            <label for='username'>Telegram или логин:</label>
                            <input type='text' id='username' name='username' required autofocus autocomplete="username">
                        </div>
                        <div class='form-group'>
                            <label for='password'>Пароль:</label>
                            <input type='password' id='password' name='password' required autocomplete="current-password">
                        </div>
                        <button type='submit'>Войти</button>
                    </form>
                </div>
                
                <!-- Форма регистрации -->
                <div id="register-tab" class="tab-content <?php echo $action === 'register' ? 'active' : ''; ?>">
                    <form method='POST' action='' id="register-form">
                        <input type='hidden' name='redirect' value='<?php echo htmlspecialchars($redirect); ?>'>
                        <input type='hidden' name='csrf_token' value='<?php echo htmlspecialchars($csrf); ?>'>
                        <input type='hidden' name='form_action' value='register'>
                        <input type='hidden' name='ajax' value='0' id="reg_ajax_flag">
                        <div class='form-group'>
                            <label for='reg_username'>Telegram логин:</label>
                            <input type='text' id='reg_username' name='username' required minlength="3" <?php echo $action === 'register' ? 'autofocus' : ''; ?> autocomplete="off">
                            <small id="reg_username_hint">Минимум 3 символа</small>
                        </div>
                        <button type='submit'>Верифицировать в боте</button>
                    </form>
                </div>
            </div>
        </main>
    </div>
    <script>
        function showTab(tab) {
            // Обновляем URL без перезагрузки страницы
            const url = new URL(window.location);
            url.searchParams.set('action', tab);
            window.history.pushState({}, '', url);
            
            // Переключаем табы
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            
            const activeBtn = document.querySelector(`.tab-btn[onclick="showTab('${tab}')"]`);
            const activeContent = document.getElementById(`${tab}-tab`);
            
            if (activeBtn) activeBtn.classList.add('active');
            if (activeContent) activeContent.classList.add('active');
            
            // Переключаем autofocus
            const inputs = activeContent?.querySelectorAll('input[type="text"], input[type="password"]');
            if (inputs && inputs.length > 0) {
                inputs[0].focus();
            }
        }
        
        function normalizeTg(val) {
            if (!val) return '';
            let s = val.trim();
            s = s.replace(/^https?:\/\/t\.me\//i, '');
            s = s.replace(/^t\.me\//i, '');
            s = s.replace(/^@+/, '');
            return s;
        }

        // Проверка существования пользователя при вводе
        document.addEventListener('DOMContentLoaded', function() {
            const regUserInput = document.getElementById('reg_username');
            const regUserHint = document.getElementById('reg_username_hint');
            let regUserTimer = null;
            function checkUser(val) {
                const norm = normalizeTg(val);
                regUserInput.value = norm;
                if (norm.length < 3) {
                    regUserHint.textContent = 'Минимум 3 символа';
                    regUserHint.style.color = 'var(--muted)';
                    return;
                }
                fetch('/api/users_search.php?mode=check_exact&q=' + encodeURIComponent(norm), { credentials: 'same-origin' })
                    .then(r => r.ok ? r.json() : [])
                    .then(arr => {
                        if (Array.isArray(arr) && arr.length > 0) {
                            regUserHint.textContent = 'Уже зарегистрирован (попробуйте войти)';
                            regUserHint.style.color = '#f87171';
                        } else {
                            regUserHint.textContent = 'Нажмите «Верифицировать в боте»';
                            regUserHint.style.color = 'var(--muted)';
                        }
                    })
                    .catch(() => {
                        regUserHint.textContent = 'Проверка недоступна';
                        regUserHint.style.color = '#f59e0b';
                    });
            }

            if (regUserInput && regUserHint) {
                regUserInput.addEventListener('input', () => {
                    clearTimeout(regUserTimer);
                    const val = regUserInput.value;
                    regUserTimer = setTimeout(() => checkUser(val), 200);
                });
                // стартовая проверка, если что-то введено
                if (regUserInput.value.trim().length >= 3) {
                    checkUser(regUserInput.value);
                }
            }
            
            // При загрузке страницы устанавливаем правильный таб
            const action = new URLSearchParams(window.location.search).get('action') || 'login';
            if (action === 'register') {
                showTab('register');
            }

            // Перехват отправки регистрации для AJAX + мгновенного открытия бота
            const regForm = document.getElementById('register-form');
            if (regForm) {
                regForm.addEventListener('submit', async (e) => {
                    e.preventDefault();
                    const userInput = document.getElementById('reg_username');
                    if (!userInput) return;
                    const norm = normalizeTg(userInput.value);
                    if (norm.length < 3) {
                        alert('Минимум 3 символа в Telegram логине');
                        return;
                    }
                    userInput.value = norm;
                    const fd = new FormData(regForm);
                    fd.set('username', norm);
                    fd.set('ajax', '1');
                    const submitBtn = regForm.querySelector('button[type="submit"]');
                    if (submitBtn) submitBtn.disabled = true;
                    try {
                    const resp = await fetch('', { method: 'POST', body: fd });
                    const json = await resp.json().catch(() => null);
                    if (!json || !json.ok) {
                        alert(json?.error || 'Не удалось отправить заявку.');
                        return;
                    }
                    const botUrl = json.bot_url || '<?php echo htmlspecialchars($botLink); ?>';
                    window.open(botUrl, '_blank');
                    } catch (err) {
                        console.error(err);
                        alert('Ошибка соединения. Попробуйте позже.');
                    } finally {
                        if (submitBtn) submitBtn.disabled = false;
                    }
                });
            }
        });
    </script>
    <script src="/js/theme-switcher.js"></script>
</body>
</html>
