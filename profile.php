<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/db.php';

ensure_session_started();
DB::init();

if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    header('Location: /auth.php?redirect=' . urlencode('/profile.php'));
    exit;
}

$username = $_SESSION['username'] ?? '';
$db = DB::getConnection();

// Загружаем текущие данные пользователя
$stmt = $db->prepare('SELECT id, username, full_name, avatar_path, avatar_data, avatar_mime, password_hash, is_admin, created_at, telegram, verified FROM users WHERE username = ?');
$stmt->execute([$username]);
$user = $stmt->fetch();

if (!$user) {
    http_response_code(404);
    exit('User not found');
}

$initial = strtoupper(substr($username, 0, 1) ?: 'G');
$isAdmin = (int)$user['is_admin'] === 1;
$csrf = csrf_token();
$telegramBotLink = getenv('TELEGRAM_BOT_LINK') ?: 'https://t.me/vinnienasta_bot'; // задайте TELEGRAM_BOT_LINK в окружении
$tgVerified = ($_SESSION['tg_verified_ok'] ?? false) || ((int)($user['verified'] ?? 0) === 1);

$message = '';
$messageType = '';

// Если есть незавершённая верификация, проверяем статус и при успехе обновляем пользователя
if (!empty($_SESSION['tg_verification_key']) && !($tgVerified)) {
    $key = $_SESSION['tg_verification_key'];
    $pendingTg = $_SESSION['tg_pending_username'] ?? null;
    $ch = curl_init('http://192.168.3.110:8080/check_verification');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['verification_key' => $key]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 6,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp ?: '', true);
    if (is_array($data) && ($data['status'] ?? '') === 'approved') {
        $tgUsernameToSet = $pendingTg ? '@' . ltrim($pendingTg, '@') : ($user['telegram'] ?? null);
        $stmt = $db->prepare('UPDATE users SET verified = 1, telegram = COALESCE(?, telegram) WHERE id = ?');
        $stmt->execute([$tgUsernameToSet, $user['id']]);
        $user['verified'] = 1;
        $user['telegram'] = $tgUsernameToSet;
        $_SESSION['tg_verified_ok'] = true;
        $tgVerified = true;
        unset($_SESSION['tg_verification_key'], $_SESSION['tg_pending_username']);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    // Запрос на верификацию Telegram
    if (isset($_POST['action']) && $_POST['action'] === 'telegram_verify') {
        header('Content-Type: application/json; charset=utf-8');
        $tgRaw = trim($_POST['telegram'] ?? '');
        $tgUser = ltrim($tgRaw, "@ \t\n\r\0\x0B");
        if ($tgUser === '') {
            echo json_encode(['ok' => false, 'error' => 'Укажите Telegram username']);
            exit;
        }
        $payload = [
            'telegram_username' => $tgUser,
            'user_id' => $user['id'] ?? null,
        ];
        $_SESSION['tg_pending_username'] = $tgUser;
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
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            echo json_encode([
                'ok' => false,
                'error' => 'Сервис верификации недоступен, попробуйте позже.',
                'bot_url' => $telegramBotLink
            ]);
            exit;
        }
        $data = json_decode($resp, true);
        if (!is_array($data) || ($data['status'] ?? '') !== 'success') {
            echo json_encode([
                'ok' => false,
                'error' => 'Бот вернул ошибку, попробуйте позже',
                'bot_url' => $telegramBotLink
            ]);
            exit;
        }
        $key = $data['verification_key'] ?? null;
        $botUrl = $data['bot_url'] ?? $telegramBotLink;
        if ($key) {
            $_SESSION['tg_verification_key'] = $key;
        }
        echo json_encode(['ok' => true, 'bot_url' => $botUrl]);
        exit;
    }

    // Обновление профиля: ФИО + аватар
    if (isset($_POST['action']) && $_POST['action'] === 'update_profile') {
        $fullName = trim($_POST['full_name'] ?? '');
        $telegramRaw = trim($_POST['telegram'] ?? '');
        $telegramSanitized = ltrim($telegramRaw, "@ \t\n\r\0\x0B");
        $telegramValue = $telegramSanitized !== '' ? '@' . $telegramSanitized : null;

        // Обработка аватара - сохраняем в БД
        $avatarData = $user['avatar_data'];
        $avatarMime = $user['avatar_mime'];
        
        // Проверка на ошибку 413 (файл слишком большой, не дошел до PHP)
        if (empty($_FILES) && isset($_POST['action']) && $_POST['action'] === 'update_profile' && isset($_POST['full_name'])) {
            // Если POST запрос есть, но $_FILES пуст, возможно файл слишком большой
            $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
            if ($contentLength > 1.5 * 1024 * 1024) {
                $message = 'Файл слишком большой. Максимальный размер: 1.5МБ.';
                $messageType = 'error';
            }
        }
        
        if (!empty($_FILES['avatar']['name'])) {
            $error = $_FILES['avatar']['error'];
            
            // Проверка ошибок загрузки
            if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
                $message = 'Файл слишком большой. Максимальный размер: 1.5МБ.';
                $messageType = 'error';
            } elseif ($error === UPLOAD_ERR_NO_FILE) {
                // Файл не был загружен - это нормально, используем текущий аватар
            } elseif ($error !== UPLOAD_ERR_OK) {
                $message = 'Ошибка при загрузке файла. Попробуйте выбрать другой файл.';
                $messageType = 'error';
            } else {
                // Файл загружен успешно
                $tmpName = $_FILES['avatar']['tmp_name'];
                $size = (int)$_FILES['avatar']['size'];
                $type = mime_content_type($tmpName);

            // Простая валидация: только изображения, до ~1.5МБ
            if ($size > 0 && $size <= 1.5 * 1024 * 1024 && in_array($type, ['image/jpeg','image/png','image/gif','image/webp'], true)) {
                    $fileData = file_get_contents($tmpName);
                    if ($fileData !== false) {
                        $avatarData = $fileData;
                        $avatarMime = $type;
                    } else {
                        $message = 'Не удалось прочитать файл аватара.';
                        $messageType = 'error';
                    }
                } else {
                    $message = 'Неверный формат или размер аватара (до 1.5МБ, jpg/png/gif/webp).';
                    $messageType = 'error';
                }
            }
        }

        // Проверка верификации Telegram
        if (empty($message) && $telegramValue !== null) {
            if (!$tgVerified) {
                $key = $_SESSION['tg_verification_key'] ?? null;
                if ($key) {
                    $ch = curl_init('http://192.168.3.110:8080/check_verification');
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => json_encode(['verification_key' => $key]),
                        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                        CURLOPT_CONNECTTIMEOUT => 3,
                        CURLOPT_TIMEOUT => 6,
                    ]);
                    $resp = curl_exec($ch);
                    $err = curl_error($ch);
                    curl_close($ch);
                    if ($resp !== false) {
                        $data = json_decode($resp, true);
                        if (is_array($data) && ($data['status'] ?? '') === 'approved') {
                            $_SESSION['tg_verified_ok'] = true;
                            $tgVerified = true;
                        }
                    }
                }
                if (!$tgVerified) {
                    $message = 'Чтобы добавить ваш Telegram аккаунт — его нужно верифицировать';
                    $messageType = 'error';
                }
            }
        }

        if (empty($message)) {
            $stmt = $db->prepare('UPDATE users SET full_name = ?, avatar_data = ?, avatar_mime = ?, telegram = ? WHERE id = ?');
            $stmt->execute([$fullName, $avatarData, $avatarMime, $telegramValue, $user['id']]);
            
            // Редирект для обновления страницы и обхода кеша аватара
            header('Location: /profile.php?updated=1');
            exit;
        }
    }

    // Смена пароля
    if (isset($_POST['action']) && $_POST['action'] === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!password_verify($current, $user['password_hash'])) {
            $message = 'Текущий пароль введён неверно.';
            $messageType = 'error';
        } elseif (strlen($new) < 6) {
            $message = 'Новый пароль должен быть не короче 6 символов.';
            $messageType = 'error';
        } elseif ($new !== $confirm) {
            $message = 'Новый пароль и подтверждение не совпадают.';
            $messageType = 'error';
        } else {
            $stmt = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $stmt->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            $message = 'Пароль успешно изменён.';
            $messageType = 'success';
        }
    }
}

$fullName = trim($user['full_name'] ?? '');
$displayName = $fullName !== '' ? $fullName : $username;
$hasAvatar = !empty($user['avatar_data']);
// Добавляем timestamp для обхода кеша браузера
$avatarUrl = $hasAvatar ? '/avatar.php?id=' . (int)$user['id'] . '&t=' . time() : null;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Мой профиль</title>
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
        * { box-sizing: border-box; }
        body { margin:0; font-family:'Inter',Arial,sans-serif; background:var(--bg-gradient); color:var(--text); height:100vh; overflow:hidden; }
        .layout { display:block; height:100vh; overflow-y:auto; }
        .sidebar { background:var(--panel); padding:1.2rem; border-right:1px solid var(--border); position:fixed; top:0; left:0; bottom:0; width:260px; overflow:auto; z-index:3000; }
        .brand { font-weight:700; letter-spacing:0.5px; color:var(--text); margin-bottom:1rem; display:flex; align-items:center; gap:0.5rem; }
        .nav { display:grid; gap:0.4rem; }
        .nav a { display:block; padding:0.75rem 0.9rem; border-radius:10px; color:var(--text); text-decoration:none; background:var(--card-bg); border:1px solid var(--border); transition:0.2s; position:relative; }
        .nav a:hover { background:color-mix(in srgb, var(--accent) 15%, transparent); }
        .nav a.active { background:color-mix(in srgb, var(--accent) 28%, transparent); border:1px solid color-mix(in srgb, var(--accent) 50%, transparent); box-shadow:0 6px 16px color-mix(in srgb, var(--accent) 20%, transparent); }
        .nav a.disabled { opacity:0.4; pointer-events:none; }
        .content { padding:1.5rem 2rem; margin-left:260px; }
        .user-block {
            display:flex;
            flex-direction:column;
            align-items:center;
            gap:0.3rem;
            margin-bottom:1.2rem;
        }
        .user-pill {
            width:40px;
            height:40px;
            border-radius:50%;
            background:var(--accent);
            color:#fff;
            display:grid;
            place-items:center;
            font-weight:700;
            text-transform:uppercase;
            cursor:pointer;
            overflow:hidden;
        }
        .user-pill img.user-avatar {
            width:100%;
            height:100%;
            object-fit:cover;
            display:block;
        }
        .user-name { font-size:0.9rem; color:var(--muted); text-align:center; max-width:100%; word-break:break-word; }
        .user-menu { margin-top:0.3rem; width:100%; background:var(--panel); border:1px solid var(--border); border-radius:10px; box-shadow:var(--shadow); padding:0.3rem 0; display:none; }
        .user-menu a { display:block; padding:0.6rem 0.9rem; color:var(--text); text-decoration:none; }
        .user-menu a:hover { background:color-mix(in srgb, var(--accent) 10%, transparent); }
        .card { background:var(--card-bg); border:1px solid var(--border); border-radius:14px; padding:1.4rem; margin-bottom:1rem; max-width:640px; }
        .profile-header {
            display:flex;
            justify-content:space-between;
            align-items:center;
            margin-bottom:0.5rem;
        }
        .profile-header h1 { margin:0; }
        .role-badge {
            padding:0.35rem 0.75rem;
            border-radius:8px;
            font-size:0.85rem;
            font-weight:500;
        }
        .role-badge-admin {
            background:rgba(245,197,66,0.15);
            border:1px solid rgba(245,197,66,0.5);
            color:#f5c542;
        }
        .role-badge-moderator {
            background:rgba(66,245,84,0.15);
            border:1px solid rgba(66,245,84,0.5);
            color:#42f554;
        }
        .role-badge-avatar {
            display:none;
            margin-top:0.5rem;
        }
        h1 { margin:0 0 0.5rem; font-size:2rem; }
        h2 { margin:1.2rem 0 0.6rem; font-size:1.3rem; }
        label, .meta, p { color:var(--muted); }
        .message { padding:0.9rem 1rem; border-radius:10px; margin-bottom:0.8rem; }
        .message.error { background:rgba(231,76,60,0.12); border:1px solid rgba(231,76,60,0.35); color:#f6a9a0; }
        .message.success { background:rgba(46,204,113,0.12); border:1px solid rgba(46,204,113,0.35); color:#7ef2b5; }
        .form-group { margin-bottom:1rem; }
        .form-group-compact { margin-bottom:0.6rem; }
        .input-inline { display:flex; align-items:center; gap:0.4rem; flex-wrap:wrap; }
        .input-inline .prefix { padding:0.72rem 0.65rem; border:1px solid var(--input-border); border-radius:10px; background:var(--input-bg); color:var(--muted); font-weight:700; }
        .input-inline input[type='text'] { flex:1; min-width:160px; }
        .input-inline .btn-verify { padding:0.65rem 0.9rem; border-radius:10px; border:1px solid var(--border); background:var(--panel); color:var(--text); cursor:pointer; font-weight:700; transition:0.2s; }
        .input-inline .btn-verify:hover { background:color-mix(in srgb, var(--accent) 12%, transparent); border-color:color-mix(in srgb, var(--accent) 35%, transparent); }
        .input-inline .btn-verify[disabled] { opacity:0.6; cursor:wait; }
        .tg-verified { display:flex; align-items:center; gap:0.5rem; padding:0.65rem 0.75rem; border:1px solid color-mix(in srgb, var(--accent) 55%, var(--border)); border-radius:10px; background:color-mix(in srgb, var(--accent) 12%, var(--panel)); color:var(--text); }
        .tg-verified .badge { padding:0.25rem 0.5rem; border-radius:8px; background:#21c45c; color:#fff; font-weight:700; font-size:0.9rem; }
        /* Модалка блокировки Telegram */
        .tg-modal-backdrop { position:fixed; inset:0; background:rgba(0,0,0,0.45); display:none; align-items:center; justify-content:center; z-index:5000; }
        .tg-modal-backdrop.show { display:flex; }
        .tg-modal { background:var(--card-bg); border:1px solid var(--border); border-radius:14px; padding:1.1rem 1.2rem; width: min(420px, 92vw); box-shadow:0 12px 34px rgba(0,0,0,0.25); }
        .tg-modal h3 { margin:0 0 0.35rem; }
        .tg-modal p { margin:0 0 1rem; color:var(--muted); }
        .tg-modal .actions { display:flex; gap:0.6rem; justify-content:flex-end; flex-wrap:wrap; }
        .tg-modal .btn { padding:0.55rem 1rem; border-radius:10px; border:1px solid var(--border); cursor:pointer; font-weight:700; }
        .tg-modal .btn.primary { background:var(--accent); color:#fff; border-color:color-mix(in srgb, var(--accent) 50%, transparent); }
        .tg-modal .btn.secondary { background:var(--panel); color:var(--text); }
        .password-change-section {
            background:var(--card-bg);
            border:1px solid var(--border);
            border-radius:10px;
            padding:1rem;
            margin-bottom:1rem;
        }
        .password-change-section .form-group-compact:last-child {
            margin-bottom:0;
        }
        input[type='text'], input[type='password'], input[type='file'] {
            width:100%;
            padding:0.75rem;
            border-radius:10px;
            border:1px solid var(--input-border);
            background:var(--input-bg);
            color:var(--text);
        }
        input:focus { outline:none; border-color:var(--accent); }
        .btn {
            padding:0.55rem 1rem;
            border-radius:999px;
            background:var(--accent);
            color:#fff;
            text-decoration:none;
            font-weight:600;
            border:none;
            cursor:pointer;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:0.35rem;
            font-size:0.9rem;
        }
        .btn.secondary {
            background:transparent;
            border:1px solid var(--btn-outline-border, var(--border));
            color:var(--btn-outline-text, var(--text));
        }
        .btn.secondary:hover {
            background:color-mix(in srgb, var(--accent) 10%, transparent);
            border-color:var(--accent);
            color:var(--accent);
        }
        .form-group:has(.avatar-preview) {
            display:flex;
            flex-direction:column;
            align-items:center;
        }
        .form-group:has(.avatar-preview) input[type="file"] {
            width:auto;
            margin-top:0.5rem;
        }
        .form-group:has(.avatar-preview) .meta {
            text-align:center;
        }
        .avatar-preview {
            width:80px;
            height:80px;
            border-radius:50%;
            overflow:hidden;
            background:var(--card-bg);
            border:1px solid var(--border);
            display:flex;
            align-items:center;
            justify-content:center;
            margin-bottom:0.75rem;
        }
        .avatar-preview img {
            width:100%;
            height:100%;
            object-fit:cover;
        }
        @media (max-width:960px) {
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
            .content { padding:1rem; margin-left:0; }
            .toggle {
                position:fixed;
                top:12px;
                left:12px;
                z-index:11;
                padding:0.6rem 0.9rem;
                border-radius:10px;
                border:1px solid var(--border);
                background:color-mix(in srgb, var(--panel) 90%, transparent);
                color:var(--text);
                cursor:pointer;
                font-size: 1.2rem;
                min-width: 44px;
                min-height: 44px;
                display: flex;
                align-items: center;
                justify-content: center;
                box-shadow: var(--shadow);
            }
            .card { padding:1.2rem; }
            h1 {
                text-align: center;
            }
            h2 {
                text-align: center;
            }
        }
        @media (max-width:480px) {
            .content { padding:0.75rem; }
            .card { padding:1rem; }
            .profile-header {
                flex-direction:column;
                align-items:center;
                gap:0.5rem;
            }
            .profile-header h1 { 
                font-size:1.6rem;
                text-align: center;
                margin:0;
            }
            .role-badge-header {
                display:none;
            }
            .role-badge-avatar {
                display:inline-block;
            }
            h2 {
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div class="layout">
        <!-- Sidebar будет загружен через JavaScript -->
        <main class="content">
            <div class="card">
                <div class="profile-header">
                    <h1>Мой профиль</h1>
                    <?php if ($user['is_admin'] == 1): ?>
                        <span class="role-badge role-badge-admin role-badge-header">Админ</span>
                    <?php elseif ($user['is_admin'] == 2): ?>
                        <span class="role-badge role-badge-moderator role-badge-header">Модератор</span>
                    <?php endif; ?>
                </div>
                <?php if (isset($_GET['updated'])): ?>
                    <div class="message success">
                        Профиль обновлён.
                    </div>
                <?php endif; ?>
                <?php if ($message): ?>
                    <div class="message <?php echo htmlspecialchars($messageType); ?>">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data" id="profile-form" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                    
                    <!-- Аватар и его выбор -->
                    <div class="form-group">
                        <div class="avatar-preview">
                            <?php if ($hasAvatar && $avatarUrl): ?>
                                <img src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="Avatar">
                            <?php else: ?>
                                <span class="meta"><?php echo htmlspecialchars($initial); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($user['is_admin'] == 1 || $user['is_admin'] == 2): ?>
                            <span class="role-badge <?php echo $user['is_admin'] == 1 ? 'role-badge-admin' : 'role-badge-moderator'; ?> role-badge-avatar">
                                <?php echo $user['is_admin'] == 1 ? 'Админ' : 'Модератор'; ?>
                            </span>
                        <?php endif; ?>
                        <input type="file" name="avatar" id="avatar-input" accept="image/*" onchange="checkAvatarSize(this)">
                        <p class="meta" id="avatar-hint">Поддерживаются изображения до 1.5МБ (JPEG, PNG, GIF, WebP).</p>
                    </div>

                    <!-- Основная информация -->
                    <h2>Основная информация</h2>
                    <input type="hidden" name="action" value="update_profile">
                    <div class="form-group">
                        <label>Логин</label>
                        <input type="text" value="<?php echo htmlspecialchars($username); ?>" disabled>
                    </div>
                    <div class="form-group">
                        <label>ФИО / Отображаемое имя</label>
                        <input type="text" name="full_name" value="<?php echo htmlspecialchars($fullName); ?>" placeholder="Как к вам обращаться">
                    </div>
                    <div class="form-group">
                        <label>Telegram</label>
                        <?php if ($tgVerified && ($user['telegram'] ?? '')): ?>
                            <div class="tg-verified">
                                <span><?php echo htmlspecialchars($user['telegram']); ?></span>
                                <span class="badge">✓</span>
                            </div>
                        <?php else: ?>
                            <div class="input-inline">
                                <span class="prefix">@</span>
                                <input type="text" name="telegram" id="telegram" value="<?php echo htmlspecialchars(ltrim($user['telegram'] ?? '', '@')); ?>" placeholder="username" autocomplete="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-form-type="other">
                                <button type="button" class="btn-verify" id="telegram-verify" data-bot-link="<?php echo htmlspecialchars($telegramBotLink); ?>">Верификация</button>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Смена пароля -->
                    <h2>Смена пароля</h2>
                    <div class="password-change-section">
                        <div class="form-group form-group-compact">
                            <label>Текущий пароль</label>
                            <input type="password" name="current_password" id="current_password" autocomplete="current-password" data-lpignore="true">
                        </div>
                        <div class="form-group form-group-compact">
                            <label>Новый пароль</label>
                            <input type="password" name="new_password" id="new_password" autocomplete="new-password">
                        </div>
                        <div class="form-group form-group-compact">
                            <label>Повторите новый пароль</label>
                            <input type="password" name="confirm_password" id="confirm_password" autocomplete="new-password">
                        </div>
                    </div>

                    <!-- Кнопка Сохранить -->
                    <button type="submit" class="btn" onclick="return handleFormSubmit(event)">Сохранить</button>
                </form>
            </div>
        </main>
    </div>
    <script>
        // Глобальные функции для проверки размера файла
        // Уменьшаем лимит до 1.5МБ, так как сервер может иметь ограничение меньше 2МБ
        const MAX_AVATAR_SIZE = 1.5 * 1024 * 1024; // 1.5MB
        const TG_VERIFIED = <?php echo $tgVerified ? 'true' : 'false'; ?>;
        
        function checkAvatarSize(input) {
            const file = input.files[0];
            const hint = document.getElementById('avatar-hint');
            
            if (file) {
                if (file.size > MAX_AVATAR_SIZE) {
                    const fileSizeMB = (file.size / 1024 / 1024).toFixed(2);
                    if (hint) {
                        hint.textContent = '❌ Файл слишком большой! Максимальный размер: 1.5МБ. Выбранный файл: ' + fileSizeMB + 'МБ';
                        hint.style.color = '#f6a9a0';
                        hint.style.fontWeight = 'bold';
                    }
                    input.value = ''; // Очищаем выбор
                    alert('Файл слишком большой! Максимальный размер: 1.5МБ.\nВыбранный файл: ' + fileSizeMB + 'МБ\n\nПожалуйста, выберите файл меньшего размера или сожмите изображение.');
                    return false;
                } else {
                    const fileSizeKB = (file.size / 1024).toFixed(0);
                    if (hint) {
                        hint.textContent = '✓ Файл подходит. Размер: ' + fileSizeKB + 'КБ';
                        hint.style.color = '#7ef2b5';
                        hint.style.fontWeight = 'normal';
                    }
                }
            }
            return true;
        }
        
        function checkFormBeforeSubmit(e) {
            const avatarInput = document.getElementById('avatar-input');
            if (avatarInput && avatarInput.files[0]) {
                const file = avatarInput.files[0];
                if (file.size > MAX_AVATAR_SIZE) {
                    e.preventDefault();
                    e.stopPropagation();
                    e.stopImmediatePropagation();
                    const fileSizeMB = (file.size / 1024 / 1024).toFixed(2);
                    alert('Файл слишком большой! Максимальный размер: 1.5МБ.\nВыбранный файл: ' + fileSizeMB + 'МБ\n\nПожалуйста, выберите файл меньшего размера или сожмите изображение.');
                    avatarInput.value = '';
                    const hint = document.getElementById('avatar-hint');
                    if (hint) {
                        hint.textContent = 'Поддерживаются изображения до 1.5МБ (JPEG, PNG, GIF, WebP).';
                        hint.style.color = '';
                        hint.style.fontWeight = 'normal';
                    }
                    return false;
                }
            }
            return true;
        }

        // Автоуборка лишних @ в телеграм нике
        (function() {
            const tgInput = document.getElementById('telegram');
            if (!tgInput) return;
            tgInput.addEventListener('input', () => {
                tgInput.value = (tgInput.value || '').replace(/^@+/, '');
            });
        })();

        // Открыть бота для верификации
        (function() {
            const btn = document.getElementById('telegram-verify');
            if (!btn) return;
            btn.addEventListener('click', async () => {
                const tgInput = document.getElementById('telegram');
                const linkFallback = btn.dataset.botLink || 'https://t.me/';
                const username = (tgInput?.value || '').trim();
                if (!username) {
                    alert('Введите Telegram username');
                    return;
                }
                // Открываем окно сразу по жесту пользователя, чтобы iOS не блокировал
                const popup = window.open('about:blank', '_blank');
                btn.disabled = true;
                try {
                    const fd = new FormData();
                    fd.append('csrf_token', '<?php echo htmlspecialchars($csrf); ?>');
                    fd.append('action', 'telegram_verify');
                    fd.append('telegram', username);
                    const resp = await fetch('/profile.php', { method: 'POST', body: fd });
                    const json = await resp.json().catch(() => null);
                    const botUrl = (json && json.bot_url) ? json.bot_url : linkFallback;
                    if (!json || !json.ok) {
                        alert(json?.error || 'Не удалось создать запрос на верификацию. Откроем бота, попробуйте там /start.');
                        if (popup) popup.location.href = botUrl;
                        else window.open(botUrl, '_blank');
                        return;
                    }
                    if (popup) popup.location.href = botUrl;
                    else window.open(botUrl, '_blank');
                } catch (err) {
                    console.error(err);
                    alert('Ошибка соединения. Попробуйте позже.');
                    if (popup) popup.close();
                } finally {
                    btn.disabled = false;
                }
            });
        })();

        // Модалка блокировки сохранения без верификации
        (function() {
            const form = document.getElementById('profile-form');
            if (!form) return;
            const modal = document.getElementById('tg-block-modal');
            const modalExit = document.getElementById('tg-block-exit');
            const modalVerify = document.getElementById('tg-block-verify');
            if (!modal || !modalExit || !modalVerify) return;
            form.addEventListener('submit', (e) => {
                const tgInput = document.getElementById('telegram');
                const hasTg = tgInput && ((tgInput.value || '').trim() !== '');
                if (hasTg && !TG_VERIFIED) {
                    e.preventDefault();
                    modal.classList.add('show');
                }
            });
            modalExit.addEventListener('click', () => {
                window.location.href = '/';
            });
            modalVerify.addEventListener('click', () => {
                const btn = document.getElementById('telegram-verify');
                if (btn && !btn.disabled) btn.click();
                modal.classList.remove('show');
            });
        })();
        
        function handleFormSubmit(e) {
            // Проверка размера аватара
            if (!checkFormBeforeSubmit(e)) {
                return false;
            }
            
            // Проверяем, заполнены ли поля пароля
            const currentPass = document.getElementById('current_password').value;
            const newPass = document.getElementById('new_password').value;
            const confirmPass = document.getElementById('confirm_password').value;
            
            // Если хотя бы одно поле пароля заполнено, нужно заполнить все
            if (currentPass || newPass || confirmPass) {
                if (!currentPass || !newPass || !confirmPass) {
                    e.preventDefault();
                    alert('Для смены пароля необходимо заполнить все три поля.');
                    return false;
                }
                if (newPass !== confirmPass) {
                    e.preventDefault();
                    alert('Новый пароль и подтверждение не совпадают.');
                    return false;
                }
                if (newPass.length < 6) {
                    e.preventDefault();
                    alert('Новый пароль должен быть не короче 6 символов.');
                    return false;
                }
                // Если пароль заполнен, отправляем два запроса
                e.preventDefault();
                submitProfileAndPassword();
                return false;
            }
            
            // Если пароль не заполнен, просто отправляем форму профиля
            return true;
        }
        
        function submitProfileAndPassword() {
            const form = document.getElementById('profile-form');
            const formData = new FormData(form);
            
            // Сначала отправляем смену пароля
            const passwordData = new FormData();
            passwordData.append('csrf_token', formData.get('csrf_token'));
            passwordData.append('action', 'change_password');
            passwordData.append('current_password', formData.get('current_password'));
            passwordData.append('new_password', formData.get('new_password'));
            passwordData.append('confirm_password', formData.get('confirm_password'));
            
            fetch(window.location.href, {
                method: 'POST',
                body: passwordData
            }).then(response => {
                if (response.ok) {
                    // Затем отправляем обновление профиля
                    return fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    });
                } else {
                    return response.text().then(text => {
                        throw new Error('Ошибка при смене пароля');
                    });
                }
            }).then(response => {
                if (response.ok) {
                    // Обновляем sidebar перед редиректом
                    if (window.SidebarCache && typeof window.SidebarCache.refresh === 'function') {
                        window.SidebarCache.refresh().then(() => {
                            window.location.href = '/profile.php?updated=1';
                        }).catch(() => {
                            // Если обновление не удалось, все равно делаем редирект
                            window.location.href = '/profile.php?updated=1';
                        });
                    } else {
                        window.location.href = '/profile.php?updated=1';
                    }
                } else {
                    return response.text().then(text => {
                        throw new Error('Ошибка при обновлении профиля');
                    });
                }
            }).catch(error => {
                alert('Произошла ошибка: ' + error.message);
            });
        }
        
        // Обновляем sidebar после успешного сохранения профиля
        <?php if (isset($_GET['updated'])): ?>
        (function() {
            // Небольшая задержка, чтобы убедиться, что sidebar-cache.js загружен
            setTimeout(() => {
                if (window.SidebarCache && typeof window.SidebarCache.refresh === 'function') {
                    window.SidebarCache.refresh();
                }
            }, 100);
        })();
        <?php endif; ?>
        
        // Проверка размера файла перед отправкой
        const avatarInput = document.getElementById('avatar-input');
        const avatarHint = document.getElementById('avatar-hint');
        const profileForm = document.getElementById('profile-form');
        
        if (avatarInput && profileForm && avatarHint) {
            // Дополнительная проверка при выборе файла (дублируем для надежности)
            avatarInput.addEventListener('change', function(e) {
                checkAvatarSize(e.target);
            });
            
            // Блокировка отправки формы, если файл слишком большой (capture phase для раннего перехвата)
            profileForm.addEventListener('submit', function(e) {
                if (!checkFormBeforeSubmit(e)) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    return false;
                }
            }, true); // Используем capture phase для раннего перехвата
        }
    </script>
    <div class="tg-modal-backdrop" id="tg-block-modal">
        <div class="tg-modal">
            <h3>Нужна верификация Telegram</h3>
            <p>Чтобы добавить ваш Telegram аккаунт — его нужно верифицировать.</p>
            <div class="actions">
                <button class="btn secondary" type="button" id="tg-block-exit">Выход</button>
                <button class="btn primary" type="button" id="tg-block-verify">Верифицировать</button>
            </div>
        </div>
    </div>
    <script src="/js/sidebar-cache.js"></script>
    <script src="/js/theme-switcher.js"></script>
    <script src="/js/app.js"></script>
</body>
</html>
