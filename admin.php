<?php
function getInitial(string $text): string {
    $text = trim($text);
    if ($text === '') return 'U';
    if (function_exists('mb_substr')) {
        $ch = mb_substr($text, 0, 1, 'UTF-8');
        if (function_exists('mb_strtoupper')) {
            $ch = mb_strtoupper($ch, 'UTF-8');
        } else {
            $ch = strtoupper($ch);
        }
        return $ch ?: 'U';
    }
    return strtoupper(substr($text, 0, 1));
}

$username = require __DIR__ . '/includes/auth_required.php';
require_once __DIR__ . '/includes/user_helper.php';
require_once __DIR__ . '/includes/layout_helper.php';

$userData = getCurrentUser($username);
extract($userData);
$db = DB::getConnection();

// Разрешаем доступ админам (is_admin == 1) и модераторам (is_admin == 2)
if (!$user || ((int)$user['is_admin'] != 1 && (int)$user['is_admin'] != 2)) {
    header('Location: /');
    exit;
}

$message = '';
$messageType = '';
$csrf = csrf_token();

function detectLocale($text) {
    $cyr = 0;
    if (preg_match_all('/[А-Яа-яЁё]+/u', $text, $m)) {
        $cyr = count($m[0]);
    }
    return $cyr > 10 ? 'ru' : 'foreign';
}

/**
 * Проверка дубликата песни:
 * 1) Совпадают название и исполнитель
 * 2) Или есть >=5 одинаковых непустых строк текста
 */
function isDuplicateSong(PDO $db, string $title, string $artist = null, string $normalizedLyrics = '', ?int $excludeId = null): bool {
    // Быстрая проверка по названию/исполнителю
    $stmt = $db->prepare("
        SELECT id FROM songs 
        WHERE title = ?
          AND ((artist IS NULL AND ? IS NULL) OR artist = ?)
          " . ($excludeId ? "AND id <> ?" : "") . "
        LIMIT 1
    ");
    $params = [$title, $artist, $artist];
    if ($excludeId) $params[] = $excludeId;
    $stmt->execute($params);
    if ($stmt->fetchColumn()) {
        return true;
    }

    // Подготовим множество строк новой песни
    $linesNew = [];
    $rawLines = preg_split('/\R/u', (string)$normalizedLyrics);
    foreach ($rawLines as $ln) {
        $ln = trim($ln);
        if ($ln === '') continue;
        $linesNew[$ln] = true;
    }
    if (count($linesNew) < 5) {
        return false;
    }

    // Проверяем пересечение строк с существующими песнями
    $stmt = $db->prepare("SELECT id, lyrics FROM songs " . ($excludeId ? "WHERE id <> ?" : ""));
    $stmt->execute($excludeId ? [$excludeId] : []);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $raw = $row['lyrics'] ?? '';
        if ($raw === '') continue;
        $match = 0;
        $seen = [];
        $rows = preg_split('/\R/u', $raw);
        foreach ($rows as $ln) {
            $ln = trim($ln);
            if ($ln === '') continue;
            if (isset($seen[$ln])) continue;
            $seen[$ln] = true;
            if (isset($linesNew[$ln])) {
                $match++;
                if ($match >= 5) return true;
            }
        }
    }
    return false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (isset($_POST['action']) && $_POST['action'] === 'analyze_song') {
        require_once __DIR__ . '/chord_parser.php';
        header('Content-Type: application/json');
        $text = $_POST['text'] ?? '';
        $lines = explode("\n", $text);
        $chords = ChordParser::extractAllChords($text);
        $stats = ['text' => 0, 'chords' => 0, 'empty' => 0];
        foreach ($lines as $line) {
            $type = ChordParser::getLineType($line);
            $stats[$type]++;
        }
        echo json_encode(['success' => true, 'chords' => $chords, 'stats' => $stats]);
        exit;
    }

    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_song':
                require_once __DIR__ . '/chord_parser.php';
                $title = trim($_POST['title'] ?? '');
                $artist = trim($_POST['artist'] ?? '');
                $cap = trim($_POST['cap'] ?? '');
                $firstNote = trim($_POST['first_note'] ?? '');
                $skill = (int)($_POST['skill_stars'] ?? 0);
                $pop = (int)($_POST['popularity_stars'] ?? 0);
                $lyrics = trim($_POST['lyrics'] ?? '');
                if (!empty($title)) {
                    $normalizedLyrics = ChordParser::replaceChordsWithBrackets($lyrics);
                    $locale = detectLocale($normalizedLyrics);
                    if (isDuplicateSong($db, $title, $artist, $normalizedLyrics, null)) {
                        $message = 'Кажется такая песня уже добавлена';
                        $messageType = 'error';
                        break;
                    }
                    try {
                        $db->beginTransaction();
                        $addedBy = isset($user['id']) ? (int)$user['id'] : null;
                        $stmt = $db->prepare('INSERT INTO songs (title, artist, cap, first_note, skill_stars, popularity_stars, locale, lyrics, added_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                        $stmt->execute([$title, $artist, $cap ?: null, $firstNote ?: null, $skill, $pop, $locale ?: null, $normalizedLyrics, $addedBy]);
                        $newSongId = $db->lastInsertId();
                        $chords = ChordParser::extractAllChords($normalizedLyrics);
                        if (!empty($chords)) {
                            $stmt = $db->prepare('INSERT INTO chords (song_id, chord_text, char_position) VALUES (?, ?, ?)');
                            foreach ($chords as $chord) {
                                $stmt->execute([
                                    $newSongId,
                                    ChordParser::normalizeChord($chord['text']),
                                    $chord['position']
                                ]);
                            }
                        }
                        $db->commit();
                        // Логируем создание песни
                        if (!function_exists('logHistory')) {
                            require_once __DIR__ . '/includes/history_helper.php';
                        }
                        $desc = $artist ? "добавил песню \"{$title}\" — {$artist}" : "добавил песню \"{$title}\"";
                        logHistory(
                            'CREATE',
                            'songs',
                            (int)$newSongId,
                            null,
                            [
                                'title' => $title,
                                'artist' => $artist,
                                'cap' => $cap ?: null,
                                'first_note' => $firstNote ?: null,
                                'skill_stars' => $skill,
                                'popularity_stars' => $pop,
                                'locale' => $locale ?: null,
                            ],
                            $desc,
                            $desc
                        );
                        $message = 'Песня добавлена успешно! Извлечено аккордов: ' . count($chords);
                        $messageType = 'success';
                    } catch (Throwable $e) {
                        if ($db->inTransaction()) { $db->rollBack(); }
                        $message = 'Ошибка при добавлении песни. Попробуйте снова.';
                        $messageType = 'error';
                    }
                }
                break;
            case 'delete_song':
                // Только админы могут удалять песни
                if ($user['is_admin'] != 1) {
                    $message = 'Доступ запрещен. Удаление песен доступно только администраторам.';
                    $messageType = 'error';
                    break;
                }
                $songId = (int)($_POST['song_id'] ?? 0);
                if ($songId > 0) {
                    // Получаем данные песни перед удалением для логирования
                    $songStmt = $db->prepare('SELECT title, artist FROM songs WHERE id = ?');
                    $songStmt->execute([$songId]);
                    $songData = $songStmt->fetch();
                    
                    $stmt = $db->prepare('DELETE FROM songs WHERE id = ?');
                    $stmt->execute([$songId]);
                    
                    // Логируем событие удаления песни
                    if ($songData) {
                        if (!function_exists('logHistory')) {
                            require_once __DIR__ . '/includes/history_helper.php';
                        }
                        $songTitle = $songData['title'];
                        $description = "удалил песню \"{$songTitle}\"";
                        logHistory(
                            'DELETE',
                            'songs',
                            $songId,
                            ['title' => $songData['title'], 'artist' => $songData['artist'] ?? ''],
                            null,
                            $description,
                            $description
                        );
                    }
                    
                    $message = 'Песня удалена успешно!';
                    $messageType = 'success';
                }
                break;
            case 'add_user':
                if ($user['is_admin'] != 1) {
                    $message = 'Доступ запрещен';
                    $messageType = 'error';
                    break;
                }
                $newUser = trim($_POST['new_username'] ?? '');
                $newPass = trim($_POST['new_password'] ?? '');
                $isAdmin = (int)($_POST['new_is_admin'] ?? 0);
                // Разрешаем только 0, 1, 2
                if (!in_array($isAdmin, [0, 1, 2])) {
                    $isAdmin = 0;
                }
                if ($newUser && $newPass) {
                    $stmt = $db->prepare('INSERT INTO users (username, password_hash, is_admin) VALUES (?, ?, ?)');
                    $stmt->execute([$newUser, password_hash($newPass, PASSWORD_DEFAULT), $isAdmin]);
                    $newUserId = (int)$db->lastInsertId();
                    
                    // Логируем событие создания пользователя
                    if ($newUserId > 0) {
                        if (!function_exists('logHistory')) {
                            require_once __DIR__ . '/includes/history_helper.php';
                        }
                        $roleNames = [0 => 'пользователя', 2 => 'модератора', 1 => 'админа'];
                        $roleName = $roleNames[$isAdmin] ?? 'пользователя';
                        $description = "создал пользователя {$newUser} с правами {$roleName}";
                        logHistory(
                            'CREATE',
                            'users',
                            $newUserId,
                            null,
                            ['username' => $newUser, 'is_admin' => $isAdmin],
                            $description,
                            $description
                        );
                    }
                    
                    header('Location: /admin.php#users');
                    exit;
                }
                break;
            case 'broadcast':
                header("Content-Type: application/json; charset=utf-8");
                $text = trim($_POST['broadcast_text'] ?? '');
                if ($text === '') {
                    echo json_encode(['ok' => false, 'error' => 'Введите текст для рассылки']);
                    exit;
                }
                $token = getenv('BROADCAST_TOKEN') ?: '';
                $payload = json_encode(['text' => $text, 'token' => $token], JSON_UNESCAPED_UNICODE);
                $ch = curl_init('http://192.168.3.110:8080/broadcast');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $payload,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                ]);
                $resp = curl_exec($ch);
                curl_close($ch);
                if ($resp === false) {
                    echo json_encode(['ok' => false, 'error' => 'Сервис рассылки недоступен']);
                    exit;
                }
                $data = json_decode($resp, true);
                if (!is_array($data) || ($data['status'] ?? '') !== 'success') {
                    $errMsg = $data['error'] ?? 'Ошибка рассылки';
                    echo json_encode(['ok' => false, 'error' => $errMsg]);
                    exit;
                }
                echo json_encode([
                    'ok' => true,
                    'sent' => $data['sent'] ?? 0,
                    'failed' => $data['failed'] ?? 0,
                    'total' => $data['total'] ?? 0,
                ]);
                exit;
                break;
            case 'delete_user':
                if ($user['is_admin'] != 1) {
                    $message = 'Доступ запрещен';
                    $messageType = 'error';
                    break;
                }
                $uid = (int)($_POST['user_id'] ?? 0);
                if ($uid > 0) {
                    if ($uid === (int)$user['id']) {
                        $message = 'Нельзя удалить самого себя.';
                        $messageType = 'error';
                        break;
                    }
                    
                    // Получаем данные пользователя перед удалением для логирования
                    $targetStmt = $db->prepare('SELECT username, full_name FROM users WHERE id = ?');
                    $targetStmt->execute([$uid]);
                    $targetUser = $targetStmt->fetch();
                    
                    $stmt = $db->prepare('DELETE FROM users WHERE id = ?');
                    $stmt->execute([$uid]);
                    
                    // Логируем событие удаления пользователя
                    if ($targetUser) {
                        if (!function_exists('logHistory')) {
                            require_once __DIR__ . '/includes/history_helper.php';
                        }
                        $targetName = trim($targetUser['full_name'] ?? '') !== '' ? $targetUser['full_name'] : $targetUser['username'];
                        $description = "удалил пользователя {$targetName}";
                        logHistory(
                            'DELETE',
                            'users',
                            $uid,
                            ['username' => $targetUser['username'], 'full_name' => $targetUser['full_name'] ?? ''],
                            null,
                            $description,
                            $description
                        );
                    }
                    
                    header('Location: /admin.php#users');
                    exit;
                }
                break;
            case 'toggle_admin':
                if ($user['is_admin'] != 1) {
                    $message = 'Доступ запрещен';
                    $messageType = 'error';
                    break;
                }
                $uid = (int)($_POST['user_id'] ?? 0);
                if ($uid > 0) {
                    if ($uid === (int)$user['id']) {
                        $message = 'Нельзя менять права самому себе.';
                        $messageType = 'error';
                        break;
                    }
                    
                    // Получаем данные целевого пользователя для логирования
                    $targetStmt = $db->prepare('SELECT username, full_name, is_admin FROM users WHERE id = ?');
                    $targetStmt->execute([$uid]);
                    $targetUser = $targetStmt->fetch();
                    
                    if ($targetUser) {
                        $oldRole = (int)$targetUser['is_admin'];
                        // Переключение: 0 (пользователь) -> 2 (модератор) -> 1 (админ) -> 0
                        $newRole = $oldRole === 0 ? 2 : ($oldRole === 2 ? 1 : 0);
                        $targetName = trim($targetUser['full_name'] ?? '') !== '' ? $targetUser['full_name'] : $targetUser['username'];
                        
                        $roleNames = [0 => 'пользователя', 2 => 'модератора', 1 => 'админа'];
                        $oldRoleName = $roleNames[$oldRole] ?? 'пользователя';
                        $newRoleName = $roleNames[$newRole] ?? 'пользователя';
                        
                        // Обновляем права
                        $stmt = $db->prepare('UPDATE users SET is_admin = ? WHERE id = ?');
                        $stmt->execute([$newRole, $uid]);
                        
                        // Логируем событие
                        if (!function_exists('logHistory')) {
                            require_once __DIR__ . '/includes/history_helper.php';
                        }
                        $changes = "изменил права {$targetName} с {$oldRoleName} на {$newRoleName}";
                        logHistory(
                            'UPDATE',
                            'users',
                            $uid,
                            ['is_admin' => $oldRole],
                            ['is_admin' => $newRole],
                            $changes,
                            $changes
                        );
                    }
                    
                    header('Location: /admin.php#users');
                    exit;
                }
                break;
            case 'reset_password':
                if ($user['is_admin'] != 1) {
                    $message = 'Доступ запрещен';
                    $messageType = 'error';
                    break;
                }
                $uid = (int)($_POST['user_id'] ?? 0);
                $newPass = trim($_POST['new_password'] ?? '');
                if ($uid > 0 && $newPass) {
                    // Получаем данные пользователя для логирования
                    $targetStmt = $db->prepare('SELECT username, full_name FROM users WHERE id = ?');
                    $targetStmt->execute([$uid]);
                    $targetUser = $targetStmt->fetch();
                    
                    $stmt = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                    $stmt->execute([password_hash($newPass, PASSWORD_DEFAULT), $uid]);
                    
                    // Логируем событие сброса пароля
                    if ($targetUser) {
                        if (!function_exists('logHistory')) {
                            require_once __DIR__ . '/includes/history_helper.php';
                        }
                        $targetName = trim($targetUser['full_name'] ?? '') !== '' ? $targetUser['full_name'] : $targetUser['username'];
                        $description = "сбросил пароль пользователя {$targetName}";
                        logHistory(
                            'UPDATE',
                            'users',
                            $uid,
                            ['password' => '***'],
                            ['password' => '***'],
                            $description,
                            $description
                        );
                    }
                    
                    header('Location: /admin.php#users');
                    exit;
                }
                break;
            case 'toggle_active':
                if ($user['is_admin'] != 1) {
                    $message = 'Доступ запрещен';
                    $messageType = 'error';
                    break;
                }
                $uid = (int)($_POST['user_id'] ?? 0);
                if ($uid > 0) {
                    if ($uid === (int)$user['id']) {
                        $message = 'Нельзя изменить статус активности самому себе.';
                        $messageType = 'error';
                        break;
                    }
                    // Получаем данные целевого пользователя для логирования
                    $targetStmt = $db->prepare('SELECT username, full_name, active FROM users WHERE id = ?');
                    $targetStmt->execute([$uid]);
                    $targetUser = $targetStmt->fetch();
                    
                    if ($targetUser) {
                        $oldStatus = (int)$targetUser['active'];
                        $newStatus = $oldStatus === 1 ? 0 : 1;
                        $targetName = trim($targetUser['full_name'] ?? '') !== '' ? $targetUser['full_name'] : $targetUser['username'];
                        
                        // Переключаем статус активности
                        $stmt = $db->prepare('UPDATE users SET active = ? WHERE id = ?');
                        $stmt->execute([$newStatus, $uid]);
                        
                        // Логируем событие
                        if (!function_exists('logHistory')) {
                            require_once __DIR__ . '/includes/history_helper.php';
                        }
                        $action = $newStatus === 1 ? 'ACTIVATE' : 'DEACTIVATE';
                        $changes = $newStatus === 1 ? "одобрил пользователя {$targetName}" : "отклонил пользователя {$targetName}";
                        logHistory(
                            $action,
                            'users',
                            $uid,
                            ['active' => $oldStatus],
                            ['active' => $newStatus],
                            $changes,
                            $changes
                        );
                    }
                    
                    header('Location: /admin.php#users');
                    exit;
                }
                break;
        }
    }
}

// Для модераторов показываем только их песни, для админов - все
if ((int)$user['is_admin'] === 2) {
    $stmt = $db->prepare('SELECT * FROM songs WHERE added_by = ? ORDER BY created_at DESC');
    $stmt->execute([(int)$user['id']]);
    $songs = $stmt->fetchAll();
} else {
    $songs = $db->query('SELECT * FROM songs ORDER BY created_at DESC')->fetchAll();
}
$usersList = $db->query('
    SELECT u.id, u.username, u.full_name, u.is_admin, u.active, u.avatar_data, u.telegram, u.created_at,
           COUNT(s.id) as songs_count
    FROM users u
    LEFT JOIN songs s ON s.added_by = u.id
    GROUP BY u.id
    ORDER BY
        CASE u.is_admin
            WHEN 1 THEN 0   -- админы первыми
            WHEN 2 THEN 1   -- модераторы
            ELSE 2          -- обычные
        END,
        u.created_at ASC
')->fetchAll();

// Загружаем историю только для админов
// Убеждаемся, что таблицы созданы
DB::init();
$history = [];
if ((int)$user['is_admin'] === 1) {
    try {
        require_once __DIR__ . '/includes/history_helper.php';
        $history = getHistory(100);
        error_log("HISTORY DEBUG: Loaded " . count($history) . " records in admin.php");
    } catch (Throwable $e) {
        error_log('HISTORY ERROR in admin.php: ' . $e->getMessage());
        error_log('HISTORY ERROR trace: ' . $e->getTraceAsString());
        $history = [];
    }
}
$selectedSongId = (int)($_GET['song_id'] ?? 0);
$chords = [];
if ($selectedSongId > 0) {
    $stmt = $db->prepare('SELECT * FROM chords WHERE song_id = ? ORDER BY char_position');
    $stmt->execute([$selectedSongId]);
    $chords = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang='ru'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Админка - Управление песнями</title>
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
        .user-pill { width:40px; height:40px; border-radius:50%; background:var(--accent); color:#fff; display:grid; place-items:center; font-weight:700; text-transform:uppercase; cursor:pointer; overflow:hidden; }
        .user-pill img.user-avatar { width:100%; height:100%; object-fit:cover; display:block; }
        .user-name { font-size:0.9rem; color:var(--muted); text-align:center; max-width:100%; word-break:break-word; }
        .user-menu { margin-top:0.3rem; width:100%; background:var(--panel); border:1px solid var(--border); border-radius:10px; box-shadow:var(--shadow); padding:0.3rem 0; display:none; }
        .user-menu a { display:block; padding:0.6rem 0.9rem; color:var(--text); text-decoration:none; }
        .user-menu a:hover { background:color-mix(in srgb, var(--accent) 10%, transparent); }
        .card { background:var(--card-bg); border:1px solid var(--border); border-radius:14px; padding:1.4rem; margin-bottom:1rem; }
        h1 { margin:0 0 0.5rem; font-size:2rem; }
        h2 { margin:0 0 0.5rem; }
        p, label, .meta { color: var(--muted); }
        .message { padding:0.9rem 1rem; border-radius:10px; margin-bottom:1rem; }
        .message.success { background:rgba(46,204,113,0.12); border:1px solid rgba(46,204,113,0.35); color:#7ef2b5; }
        .message.error { background:rgba(231,76,60,0.12); border:1px solid rgba(231,76,60,0.35); color:#f6a9a0; }
        .tabs {
            display:inline-flex;
            gap:0.25rem;
            padding:0.25rem;
            border-radius:999px;
            background:var(--card-bg);
            border:1px solid var(--border);
            margin-bottom:1rem;
        }
        .tab {
            padding:0.45rem 0.9rem;
            cursor:pointer;
            border:none;
            background:transparent;
            color:var(--muted);
            border-radius:999px;
            font-weight:600;
            font-size:0.9rem;
            transition: all 0.2s ease;
        }
        .tab:hover {
            color:var(--text);
            background:color-mix(in srgb, var(--accent) 5%, transparent);
        }
        .tab.active {
            background:color-mix(in srgb, var(--accent) 22%, transparent);
            color:var(--text);
        }
        /* Показываем вкладки по умолчанию — если JS не сработает, контент всё равно видно */
        .tab-content { display:block; }
        /* Когда JS инициализировал табы, скрываем лишние */
        body.tabs-ready .tab-content { display:none; }
        body.tabs-ready .tab-content:target { display:block; }
        body.tabs-ready .tab-content.active { display:block; }
        .form-group { margin-bottom:1rem; }
        #users .songs-list { margin-top:1.5rem; }
        input[type='text'], textarea { width:100%; padding:0.75rem; border-radius:10px; border:1px solid var(--input-border); background:var(--input-bg); color:var(--text); }
        textarea { min-height:360px; font-family: monospace; }
        input:focus, textarea:focus { outline:none; border-color:var(--accent); }
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
        .btn svg { color: currentColor; }
        .btn.secondary {
            background:transparent;
            border:1px solid var(--btn-outline-border, var(--border));
            color:var(--btn-outline-text, var(--text));
        }
        .btn.secondary svg { color:var(--btn-outline-text, var(--text)); }
        .btn.secondary:hover {
            background:color-mix(in srgb, var(--accent) 10%, transparent);
            border-color:var(--accent);
            color:var(--accent);
        }
        .btn.secondary:hover svg { color:var(--accent); }
        .btn.danger {
            background:#dc3545;
        }
        .btn.btn-icon{
            padding:0;
            width:40px;
            height:40px;
            min-width:40px;
            min-height:40px;
            border-radius:10px;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:0;
            overflow:hidden;
        }
        .btn.btn-icon svg{ width:18px; height:18px; }
        .btn:disabled{
            opacity:0.45;
            cursor:not-allowed;
        }
        .admin-header{
            display:flex;
            flex-direction:column;
            gap:0.25rem;
            margin-bottom:0.75rem;
        }
        .tabs-bar{
            display:flex;
            justify-content:space-between;
            align-items:center;
            flex-wrap:wrap;
            gap:0.75rem;
            margin-bottom:0.75rem;
        }
        .tab-toolbar{
            display:flex;
            justify-content:space-between;
            align-items:flex-end;
            flex-wrap:wrap;
            gap:0.75rem;
            margin-bottom:0.75rem;
        }
        #users .form-group form {
            display:flex;
            flex-wrap:wrap;
            gap:0.5rem;
            align-items:flex-end;
        }
        #users .form-group form > div {
            flex:1;
            min-width:180px;
        }
        #users .form-group form > button {
            flex-shrink:0;
            align-self:flex-end;
        }
        .tab-toolbar .filter{
            flex:1;
            min-width:220px;
            max-width:360px;
        }
        .songs-list { display:grid; gap:0.6rem; }
        .song-item {
            padding:0.9rem;
            border-radius:12px;
            background:rgba(255,255,255,0.03);
            border:1px solid var(--border);
            display:flex;
            align-items:center;
            gap:0.75rem;
        }
        .user-row {
            gap:0.9rem;
        }
        .user-row:hover {
            background:rgba(255,255,255,0.05);
        }
        .song-info { flex:1; min-width:0; }
        /* Модальное окно пользователя */
        .user-modal {
            position:fixed;
            inset:0;
            z-index:5000;
            display:flex;
            align-items:center;
            justify-content:center;
            padding:1rem;
        }
        .user-modal-backdrop {
            position:absolute;
            inset:0;
            background:rgba(0,0,0,0.6);
            backdrop-filter:blur(4px);
        }
        .user-modal-content {
            position:relative;
            background:var(--panel);
            border:1px solid var(--border);
            border-radius:16px;
            padding:2rem;
            max-width:408px;
            width:100%;
            box-shadow:0 20px 60px rgba(0,0,0,0.5);
            z-index:1;
            overflow:hidden;
        }
        .user-modal-content.role-moderator,
        .user-modal-content.role-admin {
            position:relative;
        }
        .user-modal-content.role-moderator::before,
        .user-modal-content.role-admin::before {
            content:'';
            position:absolute;
            top:0;
            left:0;
            width:200px;
            height:200px;
            pointer-events:none;
            z-index:0;
        }
        .user-modal-content.role-admin::before {
            background:radial-gradient(circle at top left, rgba(245,197,66,0.4), rgba(245,197,66,0.1), transparent 70%);
        }
        .user-modal-content.role-moderator::before {
            background:radial-gradient(circle at top left, rgba(66,245,84,0.4), rgba(66,245,84,0.1), transparent 70%);
        }
        .user-modal-close {
            position:relative;
            z-index:10;
        }
        .user-modal-close {
            position:absolute;
            top:1rem;
            right:1rem;
            width:32px;
            height:32px;
            border:none;
            background:var(--card-bg);
            border:1px solid var(--border);
            border-radius:8px;
            color:var(--text);
            cursor:pointer;
            display:flex;
            align-items:center;
            justify-content:center;
            transition:all 0.2s ease;
            padding:0;
        }
        .user-modal-close svg {
            width:16px;
            height:16px;
            stroke:currentColor;
        }
        .user-modal-close:hover {
            background:color-mix(in srgb, var(--accent) 10%, transparent);
            border-color:var(--accent);
        }
        .user-modal-body {
            display:flex;
            flex-direction:column;
            align-items:center;
            gap:1.5rem;
        }
        .user-modal-avatar {
            width:120px;
            height:120px;
            border-radius:50%;
            background:var(--accent);
            color:#fff;
            display:flex;
            align-items:center;
            justify-content:center;
            font-weight:700;
            font-size:3rem;
            text-transform:uppercase;
            overflow:hidden;
            flex-shrink:0;
        }
        .user-modal-info {
            width:100%;
            display:flex;
            flex-direction:column;
            gap:0;
        }
        .user-modal-field {
            display:flex;
            justify-content:space-between;
            align-items:center;
            padding:0.75rem 0;
            border-top:1px solid var(--border);
        }
        .user-modal-field:first-child { border-top:none; }
        .user-modal-label {
            color:var(--muted);
            font-size:0.95rem;
        }
        .user-modal-value {
            color:var(--text);
            font-weight:600;
            font-size:0.95rem;
        }
        .song-item h3 { margin:0 0 0.25rem; color:var(--text); }
        .song-actions {
            margin-top:0;
            margin-left:auto;
            display:flex;
            gap:0.5rem;
            flex-wrap:nowrap;
            justify-content:flex-end;
        }
        .song-actions .btn { font-size:0.85rem; }
        /* Songs tab: icon-only actions on desktop as well */
        .song-actions .btn .btn-text { display:none; }
        /* Users tab: icon-only actions on all breakpoints (desktop + mobile) */
        .user-actions .btn .btn-text { display:none; }
        .user-actions .btn.btn-icon { width:40px; height:40px; min-width:40px; min-height:40px; }
        .user-actions .btn.btn-icon svg { width:18px; height:18px; }
        /* Унифицированная тень для всех кнопок на вкладке users */
        #users .btn { box-shadow: 0 6px 16px rgba(0,0,0,0.18); }
        /* Графитовая тема: подсветить кнопки на вкладках songs и users */
        /* Графитовая тема: вторичные кнопки в users немного светлее, без изменения рамки/иконки относительно dark */
        [data-theme="dark2"] .user-actions .btn.secondary:not(.role-admin):not(.role-moderator):not(.btn-active-on) {
            color: var(--text);
            border-color: var(--btn-outline-border, var(--border));
            background: color-mix(in srgb, var(--accent) 8%, transparent);
        }
        [data-theme="dark2"] .user-actions .btn.secondary:not(.role-admin):not(.role-moderator):not(.btn-active-on):hover {
            color: var(--accent);
            border-color: var(--accent);
            background: color-mix(in srgb, var(--accent) 14%, transparent);
        }

        /* Утилиты вместо инлайн-стилей */
        .meta-compact { margin:0; }
        .form-inline-wrap { display:flex; flex-wrap:wrap; gap:0.5rem; align-items:flex-end; }
        .minw-180 { min-width:180px; }
        .minw-140 { min-width:140px; }
        .flex-1-0 { flex:1; min-width:0; }
        .flex-shrink-0 { flex-shrink:0; }
        .password-field-wrapper {
            display:flex;
            align-items:flex-end;
            gap:0.4rem;
            width:100%;
        }
        .password-field-wrapper .pw-field {
            flex:1;
            min-width:0;
        }
        .password-field-wrapper .pw-actions {
            display:flex;
            gap:0.4rem;
            align-items:flex-end;
            margin-left:auto;
        }
        .inline-form { display:inline; }
        .inline-flex { display:inline-flex; align-items:center; gap:0.3rem; }
        .cursor-pointer { cursor:pointer; }
        .avatar-img { width:100%; height:100%; object-fit:cover; display:block; }
        .mt-1 { margin-top:1rem; }
        .ml-075 { margin-left:0.75rem; }
        .btn.role-moderator {
            color: #22c55e; /* зелёный для модератора */
            border-color: rgba(34, 197, 94, 0.55);
            background: rgba(34, 197, 94, 0.12);
            box-shadow: 0 0 0 1px rgba(34, 197, 94, 0.18) inset;
        }
        .btn.role-moderator svg { color: #15803d; } /* темнее для контраста */
        .btn.role-admin {
            color: #f59e0b; /* золото для админа */
            border-color: rgba(245, 158, 11, 0.55);
            background: rgba(245, 158, 11, 0.14);
            box-shadow: 0 0 0 1px rgba(245, 158, 11, 0.18) inset;
        }
        .btn.role-admin svg { color: #b45309; } /* темнее для контраста */
        .btn.btn-active-on {
            color: #22c55e; /* зелёная галочка для активных */
            border-color: rgba(34, 197, 94, 0.55);
            background: rgba(34, 197, 94, 0.12);
            box-shadow: 0 0 0 1px rgba(34, 197, 94, 0.18) inset;
        }
        .btn.btn-active-on svg { color: #15803d; } /* темнее для контраста */
        }
        .btn.role-admin {
            color: #f59e0b; /* золото для админа */
            border-color: rgba(245, 158, 11, 0.55);
            background: rgba(245, 158, 11, 0.14);
            box-shadow: 0 0 0 1px rgba(245, 158, 11, 0.18) inset;
        }
        .btn.btn-active-on {
            color: #22c55e; /* зелёная галочка для активных */
            border-color: rgba(34, 197, 94, 0.55);
            background: rgba(34, 197, 94, 0.12);
            box-shadow: 0 0 0 1px rgba(34, 197, 94, 0.18) inset;
        }
        .toast-container { position:fixed; right:16px; bottom:16px; display:grid; gap:8px; z-index:2000; }
        .toast { min-width:200px; max-width:320px; background:var(--panel); color:var(--text); border:1px solid var(--border); border-radius:10px; padding:10px 12px; box-shadow:var(--shadow); font-size:0.95rem; }
        .toast.success { border-color: rgba(46,204,113,0.5); }
        .toast.error { border-color: rgba(231,76,60,0.5); }
        .toast .actions { margin-top:8px; display:flex; gap:8px; justify-content:flex-end; }
        .toast .btn-small { padding:0.35rem 0.7rem; border-radius:8px; border:none; cursor:pointer; font-weight:600; }
        .toast .btn-small.confirm { background:var(--accent); color:#fff; }
        .toast .btn-small.cancel { background:#4b5563; color:#fff; }
        .toast-container { position:fixed; right:16px; bottom:16px; display:grid; gap:8px; z-index:2000; }
        .toast { min-width:200px; max-width:320px; background:var(--panel); color:var(--text); border:1px solid var(--border); border-radius:10px; padding:10px 12px; box-shadow:var(--shadow); font-size:0.95rem; }
        .toast.success { border-color: rgba(46,204,113,0.5); }
        .toast.error { border-color: rgba(231,76,60,0.5); }
        .toast .actions { margin-top:8px; display:flex; gap:8px; justify-content:flex-end; }
        .toast .btn-small { padding:0.35rem 0.7rem; border-radius:8px; border:none; cursor:pointer; font-weight:600; }
        .toast .btn-small.confirm { background:var(--accent); color:#fff; }
        .toast .btn-small.cancel { background:#4b5563; color:#fff; }
        @media (max-width:960px) {
            .layout { display:block; }
            .sidebar {
                position:fixed;
                inset:0 auto 0 0;
                width:240px;
                transform:translateX(-260px);
                transition:transform 0.2s ease;
                z-index:3000; /* выше любых оверлеев на этой странице */
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
                background:color-mix(in srgb, var(--panel) 90%, transparent);
                color:var(--text);
                box-shadow:var(--shadow);
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
                padding: 1.1rem;
            }
            h1 {
                font-size: 1.75rem;
                text-align: center;
            }
            h2 {
                font-size: 1.5rem;
                text-align: center;
            }
            .admin-header{ text-align:center; }
            .tabs-bar{ justify-content:center; }
            .tab-toolbar{ justify-content:center; }
            .tab-toolbar .filter{ max-width:100%; }
            .tabs {
                flex-wrap: wrap;
            }
            .form-group {
                width: 100%;
            }
            #users .songs-list {
                margin-top: 1.75rem;
            }
            input[type='text'],
            textarea {
                font-size: 16px;
            }
            .song-item {
                flex-direction: row;
                align-items: center;
                gap: 0.5rem;
            }
            .song-actions {
                margin-left: auto;
                flex-wrap: nowrap;
            }
            .song-actions .btn .btn-text { display:none; }
            .song-actions .btn.btn-icon { width:40px; height:40px; }
            .song-actions .btn.btn-icon svg { width:18px; height:18px; }
            form[style*="display:flex"] {
                flex-direction: column;
            }
            form[style*="display:flex"] > * {
                width: 100%;
            }
            .user-row { width: 100%; }
            .user-actions { margin-left: auto; justify-content: flex-end; }
            .password-field-wrapper {
                display: flex !important;
                gap: 0.5rem;
                align-items: flex-end;
            }
            .password-field-wrapper > div:first-child {
                flex: 1;
                min-width: 0;
            }
            .password-field-wrapper #new-admin-toggle {
                flex-shrink: 0;
                margin-bottom: 0;
            }
            .toast-container {
                right: 8px;
                left: 8px;
                bottom: 8px;
            }
            .toast {
                max-width: 100%;
            }
        }
        
        @media (max-width: 480px) {
            .content { padding: 0.75rem; }
            .card {
                padding: 1rem;
            }
            h1 {
                font-size: 1.5rem;
                text-align: center;
            }
            h2 {
                font-size: 1.3rem;
                text-align: center;
            }
            .toggle {
                top: 8px;
                left: 8px;
                padding: 0.5rem 0.75rem;
            }
            .song-item {
                padding: 0.75rem;
            }
            .btn.btn-icon{ width:38px; height:38px; min-width:38px; min-height:38px; }
            .btn.btn-icon svg{ width:16px; height:16px; }
            .tab {
                padding: 0.6rem 0.8rem;
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body>
    <div class="layout">
        <!-- Sidebar будет загружен через JavaScript -->
        <main class="content">
            <div class="card">
                <div class="admin-header">
                    <h1>Админка</h1>
                    <p class="meta meta-compact">Управление песнями и пользователями</p>
                </div>
                <?php if ($message): ?><div class="message <?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>

                <div class="tabs-bar">
                    <div class="tabs">
                        <button class="tab active" type="button" data-tab="songs" onclick="showTab('songs', event)">Песни</button>
                        <?php if ($user['is_admin'] == 1): ?>
                            <button class="tab" type="button" data-tab="users" onclick="showTab('users', event)" id="users-tab">Пользователи</button>
                            <button class="tab" type="button" data-tab="history" onclick="showTab('history', event)" id="history-tab">История</button>
                            <button class="tab" type="button" data-tab="broadcast" onclick="showTab('broadcast', event)" id="broadcast-tab">Рассылка</button>
                            <button class="tab" type="button" data-tab="tg-bot" onclick="showTab('tg-bot', event)" id="tg-bot-tab">TG Bot</button>
                        <?php endif; ?>
                    </div>
                </div>

                <div id='songs' class='tab-content active'>
                    <h2>Песни</h2>
                    <?php if (empty($songs)): ?>
                        <p class='meta'>Песен пока нет. Добавьте первую песню!</p>
                        <div class="tab-toolbar">
                            <a href="/edit_song.php?new=1" class="btn">Добавить песню</a>
                        </div>
                    <?php else: ?>
                        <div class="tab-toolbar">
                            <div class="filter">
                                <label for="songs-filter" class="meta">Поиск по песням</label>
                                <input type="text" id="songs-filter" class="input" placeholder="Название или исполнитель">
                            </div>
                            <a href="/edit_song.php?new=1" class="btn">Добавить песню</a>
                        </div>
                        <div class='songs-list' id="songs-list">
                            <?php foreach ($songs as $song): ?>
                                <div class='song-item'>
                                    <div class="song-info">
                                        <h3><?php echo htmlspecialchars($song['title']); ?></h3>
                                        <?php if ($song['artist']): ?><div class='meta'>Исполнитель: <?php echo htmlspecialchars($song['artist']); ?></div><?php endif; ?>
                                    </div>
                                    <div class='song-actions'>
                                        <a href='/edit_song.php?id=<?php echo $song['id']; ?>' class='btn secondary btn-icon' title='Редактировать' aria-label='Редактировать'>
                                            <?php echo renderIcon('settings', 16, 16); ?>
                                            <span class='btn-text'>Редактировать</span>
                                        </a>
                                        <?php if ($user['is_admin'] == 1): ?>
                                        <form method='POST' style='display: inline;' data-confirm="Удалить песню?">
                                            <input type='hidden' name='csrf_token' value='<?php echo htmlspecialchars($csrf); ?>'>
                                            <input type='hidden' name='action' value='delete_song'>
                                            <input type='hidden' name='song_id' value='<?php echo $song['id']; ?>'>
                                            <button type='submit' class='btn danger btn-icon' title='Удалить' aria-label='Удалить'>
                                                <?php echo renderIcon('trash', 16, 16); ?>
                                                <span class='btn-text'>Удалить</span>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Вкладка добавления убрана: теперь добавление через /edit_song.php?new=1 -->
                <?php if ($user['is_admin'] == 1): ?>
                <!-- Вкладка История -->
                <div id='history' class='tab-content'>
                    <h2>История изменений</h2>
                    <?php if (empty($history)): ?>
                        <p class='meta'>История пока пуста</p>
                        <p class='meta' style="font-size:0.85rem; color:var(--muted); margin-top:0.5rem;">
                            Попробуйте выполнить действия: отредактировать песню, активировать пользователя или изменить его права.
                        </p>
                    <?php else: ?>
                        <div class="history-list mt-1">
                            <?php foreach ($history as $event): ?>
                                <?php
                                    $eventDate = new DateTime($event['created_at']);
                                    $formattedDate = $eventDate->format('d.m.Y');
                                    $formattedTime = $eventDate->format('H:i');
                                    
                                    // Определяем имя пользователя (может быть NULL если пользователь удален)
                                    $actorUsername = $event['username'] ?? 'Удаленный пользователь';
                                    $actorName = trim($event['full_name'] ?? '') !== '' ? htmlspecialchars($event['full_name']) : htmlspecialchars($actorUsername);
                                    $actorHasAvatar = !empty($event['avatar_data']);
                                    $actorAvatarUrl = $actorHasAvatar && !empty($event['user_id']) ? '/avatar.php?id=' . (int)$event['user_id'] . '&t=' . time() : null;
                                    $actorInitial = getInitial($actorUsername);
                                    $actorIsAdmin = (int)($event['is_admin'] ?? 0);
                                    
                                    // Подготовка данных для модального окна (только если пользователь существует)
                                    $actorData = null;
                                    if (!empty($event['user_id']) && !empty($event['username'])) {
                                        $actorData = [
                                            'id' => (int)$event['user_id'],
                                            'username' => $event['username'],
                                            'full_name' => trim($event['full_name'] ?? ''),
                                            'hasAvatar' => $actorHasAvatar,
                                            'avatarUrl' => $actorAvatarUrl,
                                            'initial' => $actorInitial,
                                            'is_admin' => $actorIsAdmin,
                                            'created_at' => $event['created_at'],
                                            'songs_count' => 0
                                        ];
                                    }
                                    
                                    // Если есть целевой пользователь (entity_type = users), подготавливаем данные для него
                                    $targetUserData = null;
                                    if (!empty($event['entity_type']) && $event['entity_type'] === 'users' && !empty($event['entity_id']) && !empty($event['entity_username'])) {
                                        $targetName = trim($event['entity_full_name'] ?? '') !== '' ? htmlspecialchars($event['entity_full_name']) : htmlspecialchars($event['entity_username']);
                                        $targetUserData = [
                                            'id' => (int)$event['entity_id'],
                                            'username' => $event['entity_username'],
                                            'full_name' => trim($event['entity_full_name'] ?? ''),
                                            'hasAvatar' => false,
                                            'avatarUrl' => null,
                                            'initial' => getInitial($event['entity_username']),
                                            'is_admin' => 0,
                                            'created_at' => '',
                                            'songs_count' => 0
                                        ];
                                    }
                                    
                                    // Формируем описание события
                                    $eventDescription = $event['changes'] ?? $event['description'] ?? '';
                                ?>
                                    <div class="history-item" style="padding:1rem; border-bottom:1px solid var(--border); display:flex; align-items:center; gap:1rem;">
                                        <div style="color:var(--muted); font-size:0.9rem; min-width:100px;">
                                        <?php echo $formattedDate; ?><br>
                                        <span style="font-size:0.85rem;"><?php echo $formattedTime; ?></span>
                                    </div>
                                        <div class="flex-1-0" style="color:var(--text);">
                                        <?php if ($actorData): ?>
                                            <span class="history-user cursor-pointer" style="color:var(--accent); font-weight:600;" onclick="event.stopPropagation(); showUserModal(<?php echo htmlspecialchars(json_encode($actorData, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>)">
                                                <?php echo $actorName; ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color:var(--muted);"><?php echo $actorName; ?></span>
                                        <?php endif; ?>
                                        <span style="margin-left:0.5rem;">
                                            <?php 
                                                $description = $eventDescription ?: '';
                                                // Если есть целевой пользователь, делаем его имя кликабельным
                                                if ($targetUserData) {
                                                    $targetName = trim($targetUserData['full_name'] ?? '') !== '' ? $targetUserData['full_name'] : $targetUserData['username'];
                                                    // Заменяем имя целевого пользователя на кликабельную ссылку
                                                    $description = str_replace($targetName, '<span class="history-user" style="cursor:pointer; color:var(--accent); font-weight:600;" onclick="event.stopPropagation(); showUserModal(' . htmlspecialchars(json_encode($targetUserData, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') . ')">' . htmlspecialchars($targetName) . '</span>', $description);
                                                }
                                                // Если description пуст, пробуем сформировать из action и entity
                                                if (empty($description)) {
                                                    $actionNames = [
                                                        'CREATE' => 'создал',
                                                        'UPDATE' => 'отредактировал',
                                                        'DELETE' => 'удалил',
                                                        'ACTIVATE' => 'активировал',
                                                        'DEACTIVATE' => 'деактивировал'
                                                    ];
                                                    $actionName = $actionNames[$event['action'] ?? ''] ?? $event['action'] ?? 'выполнил действие';
                                                    $entityTypeNames = ['songs' => 'песню', 'users' => 'пользователя'];
                                                    $entityTypeName = $entityTypeNames[$event['entity_type'] ?? ''] ?? $event['entity_type'] ?? '';
                                                    
                                                    // Если это песня и есть информация о ней, включаем в описание
                                                    if ($event['entity_type'] === 'songs' && !empty($event['song_title'])) {
                                                        $songTitle = htmlspecialchars($event['song_title']);
                                                        $songArtist = !empty($event['song_artist']) ? ' - ' . htmlspecialchars($event['song_artist']) : '';
                                                        $description = "{$actionName} {$entityTypeName} \"{$songTitle}\"{$songArtist}";
                                                    } else {
                                                        $description = "{$actionName} {$entityTypeName}";
                                                    }
                                                }
                                                echo $description;
                                            ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div id='users' class='tab-content'>
                    <h2>Пользователи</h2>
                <div class="form-group">
                    <form method="POST" class="form-inline-wrap">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                            <input type="hidden" name="action" value="add_user">
                        <div class="minw-140" style="flex:1;">
                                <label for="new_username">Логин</label>
                                <input type="text" id="new_username" name="new_username" required>
                            </div>
                        <div class="password-field-wrapper minw-140" style="flex:1;">
                            <div class="pw-field">
                                    <label for="new_password">Пароль</label>
                                    <input type="text" id="new_password" name="new_password" required>
                                </div>
                                <input type="hidden" name="new_is_admin" id="new_is_admin" value="0">
                            <div class="pw-actions">
                                <button class="btn secondary btn-icon flex-shrink-0" type="button" id="new-admin-toggle" title="Пользователь" aria-label="Роль" aria-pressed="false">
                                    <?php echo renderIcon('shield1', 16, 16); ?>
                                </button>
                                <button class="btn flex-shrink-0" type="submit">Добавить</button>
                            </div>
                        </form>
                    </div>
                    <div class="songs-list">
                        <?php foreach ($usersList as $u): ?>
                            <?php $isSelf = ((int)$u['id'] === (int)$user['id']); ?>
                            <div class="song-item user-row cursor-pointer" data-user-id="<?php echo $u['id']; ?>" data-user="<?php echo htmlspecialchars(json_encode([
                                    'id' => $u['id'],
                                    'username' => $u['username'],
                                    'full_name' => trim($u['full_name'] ?? ''),
                                    'hasAvatar' => !empty($u['avatar_data']),
                                    'avatarUrl' => !empty($u['avatar_data']) ? '/avatar.php?id=' . (int)$u['id'] . '&t=' . time() : null,
                                    'initial' => getInitial($u['username']),
                                    'created_at' => $u['created_at'],
                                    'songs_count' => (int)$u['songs_count'],
                                    'is_admin' => (int)$u['is_admin'],
                                    'telegram' => trim($u['telegram'] ?? '')
                                ], JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>" onclick="showUserModal(<?php echo htmlspecialchars(json_encode([
                                    'id' => $u['id'],
                                    'username' => $u['username'],
                                    'full_name' => trim($u['full_name'] ?? ''),
                                    'hasAvatar' => !empty($u['avatar_data']),
                                    'avatarUrl' => !empty($u['avatar_data']) ? '/avatar.php?id=' . (int)$u['id'] . '&t=' . time() : null,
                                    'initial' => getInitial($u['username']),
                                    'created_at' => $u['created_at'],
                                    'songs_count' => (int)$u['songs_count'],
                                    'is_admin' => (int)$u['is_admin'],
                                    'telegram' => trim($u['telegram'] ?? '')
                                ], JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>)">
                                <?php 
                                    $hasAvatar = !empty($u['avatar_data']);
                                    $avatarUrl = $hasAvatar ? '/avatar.php?id=' . (int)$u['id'] . '&t=' . time() : null;
                                    $initial = getInitial($u['username']);
                                    $fullName = trim($u['full_name'] ?? '');
                                    $displayName = $fullName !== '' ? $fullName : $u['username'];
                                ?>
                                <div class="user-avatar-wrapper" style="width:40px; height:40px; border-radius:50%; background:var(--accent); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; text-transform:uppercase; flex-shrink:0; overflow:hidden;">
                                    <?php if ($hasAvatar && $avatarUrl): ?>
                                        <img src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="Avatar" class="avatar-img">
                                    <?php else: ?>
                                        <?php echo htmlspecialchars($initial); ?>
                                    <?php endif; ?>
                                </div>
                                <div class="song-info flex-1-0 ml-075">
                                    <strong><?php echo htmlspecialchars($displayName); ?></strong>
                                </div>
                                <div class="song-actions user-actions" onclick="event.stopPropagation();">
                                    <?php 
                                        $isActive = isset($u['active']) && (int)$u['active'] === 1;
                                        $activeClass = $isActive ? 'btn-active-on' : '';
                                        $activeTitle = $isSelf ? 'Нельзя изменить статус активности самому себе' : 
                                            ($isActive ? 'Деактивировать пользователя' : 'Активировать пользователя');
                                    ?>
                                    <form method="POST" class="toggle-active-form inline-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                        <input type="hidden" name="action" value="toggle_active">
                                        <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                        <button class="btn secondary btn-icon <?php echo $activeClass; ?>" type="submit" title="<?php echo $activeTitle; ?>" aria-label="<?php echo $activeTitle; ?>" <?php echo $isSelf ? 'disabled' : ''; ?>>
                                            <?php echo renderIcon('check1', 16, 16); ?>
                                            <span class="btn-text"><?php echo $isActive ? 'Активен' : 'Неактивен'; ?></span>
                                        </button>
                                    </form>
                                    <form method="POST" data-reset-pass="1" class="inline-flex">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                        <input type="hidden" name="action" value="reset_password">
       								    <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                        <input type="hidden" name="new_password" value="">
                                        <button class="btn secondary btn-icon" type="submit" title="Сбросить пароль" aria-label="Сбросить пароль">
                                            <?php echo renderIcon('key', 16, 16); ?>
                                            <span class="btn-text">Сбросить пароль</span>
                                        </button>
                                    </form>
                                    <form method="POST" class="inline-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                        <input type="hidden" name="action" value="toggle_admin">
                                        <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                        <?php 
                                            $roleClass = $u['is_admin'] == 1 ? 'role-admin' : ($u['is_admin'] == 2 ? 'role-moderator' : '');
                                            $roleTitle = $isSelf ? 'Нельзя менять права себе' : 
                                                ($u['is_admin'] == 1 ? 'Админ → Пользователь' : 
                                                ($u['is_admin'] == 2 ? 'Модератор → Админ' : 'Пользователь → Модератор'));
                                            $roleText = $u['is_admin'] == 1 ? 'Админ' : ($u['is_admin'] == 2 ? 'Модератор' : 'Пользователь');
                                        ?>
                                        <button class="btn secondary btn-icon <?php echo $roleClass; ?>" type="submit" title="<?php echo $roleTitle; ?>" aria-label="<?php echo $roleTitle; ?>" <?php echo $isSelf ? 'disabled' : ''; ?>>
                                            <?php echo renderIcon('shield1', 16, 16); ?>
                                            <span class="btn-text"><?php echo $roleText; ?></span>
                                        </button>
                                    </form>
                                    <form method="POST" class="inline-form" data-confirm="Удалить пользователя?">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                        <input type="hidden" name="action" value="delete_user">
                                        <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                        <button class="btn danger btn-icon" type="submit" title="<?php echo $isSelf ? 'Нельзя удалить себя' : 'Удалить'; ?>" aria-label="<?php echo $isSelf ? 'Нельзя удалить себя' : 'Удалить'; ?>" <?php echo $isSelf ? 'disabled' : ''; ?>>
                                            <?php echo renderIcon('trash', 16, 16); ?>
                                            <span class="btn-text">Удалить</span>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                
                <!-- Вкладка Рассылка -->
                <div id='broadcast' class='tab-content'>
                    <h2>Рассылка</h2>
                    <div class="form-group">
                        <label for="broadcast-text">Текст рассылки</label>
                        <textarea id="broadcast-text" name="broadcast_text" rows="5" style="width:100%; padding:0.75rem; border-radius:10px; border:1px solid var(--border); background:var(--panel); color:var(--text);"></textarea>
                    </div>
                    <button class="btn" id="broadcast-start" type="button" data-csrf="<?php echo htmlspecialchars($csrf); ?>">Запустить рассылку</button>
                </div>

                <!-- Вкладка TG Bot -->
                <div id='tg-bot' class='tab-content'>
                    <h2>Telegram Bot</h2>
                    <div class="form-group">
                        <label>Endpoint бота</label>
                        <input type="text" value="http://192.168.3.110:8080" disabled>
                    </div>
                    <div class="form-group">
                        <label>Основной URL бота</label>
                        <input type="text" value="https://t.me/vinnienasta_bot" disabled>
                    </div>
                    <div class="form-group">
                        <label>Проверка доступности</label>
                        <button class="btn" type="button" id="tg-bot-check">Проверить</button>
                        <p class="meta" id="tg-bot-status">Не проверялось</p>
                    </div>
                </div>

<?php endif; ?>
            </div>
        </main>
    </div>
    <script>
        function showTab(tabName, ev) {
            if (ev) ev.preventDefault();
            // Блокируем доступ модераторов к разделам users и history
            <?php if ($user['is_admin'] != 1): ?>
            if (tabName === 'users' || tabName === 'history') {
                alert('Доступ к этому разделу ограничен');
                return;
            }
            <?php endif; ?>
            // Если нужной вкладки нет (например, для модераторов), откатываемся к песням
            const targetTab = document.querySelector('.tab[data-tab="' + tabName + '"]');
            const targetPane = document.getElementById(tabName);
            if (!targetTab || !targetPane) {
                if (tabName !== 'songs') {
                    showTab('songs', ev);
                }
                return;
            }
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            targetTab.classList.add('active');
            targetPane.classList.add('active');
            // Обновляем URL без перезагрузки страницы
            if (history.pushState) {
                const newUrl = window.location.pathname + '#' + tabName;
                window.history.pushState({ path: newUrl }, '', newUrl);
            }
        }
        
        // Автоматически открываем нужную вкладку при загрузке страницы
        (function() {
            const hash = window.location.hash.substring(1);
            <?php if ($user['is_admin'] != 1): ?>
            // Модераторы не могут открыть вкладки users и history
            if (hash === 'users' || hash === 'history') {
                window.location.hash = 'songs';
                showTab('songs');
                return;
            }
            <?php endif; ?>
            if (hash === 'users') {
                showTab('users');
            } else if (hash === 'history') {
                showTab('history');
            } else if (hash === 'broadcast') {
                showTab('broadcast');
            } else if (hash === 'tg-bot') {
                showTab('tg-bot');
            } else if (hash === 'songs') {
                showTab('songs');
            }
            // Навешиваем клики на табы на случай, если inline onclick не сработает
            document.querySelectorAll('.tabs .tab').forEach(btn => {
                btn.addEventListener('click', (ev) => {
                    const tab = btn.dataset.tab;
                    if (tab) showTab(tab, ev);
                });
            });
            
            // Фолбек: если по какой-то причине ни одна вкладка не активна, пробуем активировать по хэшу или songs
            (function ensureTabVisible() {
                const panes = Array.from(document.querySelectorAll('.tab-content'));
                if (!panes.length) return;
                const activePane = panes.find(p => p.classList.contains('active'));
                if (activePane) return;
                const hash = window.location.hash.substring(1);
                const paneByHash = hash ? document.getElementById(hash) : null;
                const tabName = paneByHash ? hash : 'songs';
                showTab(tabName);
            })();

            // После всех инициализаций помечаем, что табы готовы — можно скрывать неактивные
            document.body.classList.add('tabs-ready');
        })();
        const songsFilter = document.getElementById('songs-filter');
        const songsList = document.getElementById('songs-list');
        if (songsFilter && songsList) {
            const items = Array.from(songsList.querySelectorAll('.song-item'));
            songsFilter.addEventListener('input', () => {
                const q = songsFilter.value.toLowerCase();
                items.forEach(it => {
                    const txt = (it.textContent || '').toLowerCase();
                    it.style.display = txt.includes(q) ? '' : 'none';
                });
            });
        }
        // Toasts & confirm
        (function() {
            let container = document.querySelector('.toast-container');
            if (!container) {
                container = document.createElement('div');
                container.className = 'toast-container';
                document.body.appendChild(container);
            }
            window.showToast = function(message, type = 'success', duration = 3000) {
                const toast = document.createElement('div');
                toast.className = `toast ${type}`;
                toast.textContent = message;
                container.appendChild(toast);
                setTimeout(() => toast.remove(), duration);
            };
            window.showConfirm = function(message, onConfirm) {
                const toast = document.createElement('div');
                toast.className = 'toast';
                toast.innerHTML = `<div>${message}</div><div class="actions"><button class="btn-small cancel">Отмена</button><button class="btn-small confirm">Ок</button></div>`;
                container.appendChild(toast);
                const close = () => toast.remove();
                toast.querySelector('.cancel').addEventListener('click', close);
                toast.querySelector('.confirm').addEventListener('click', () => { close(); onConfirm && onConfirm(); });
            };
            document.querySelectorAll('form[data-confirm]').forEach(form => {
                if (form.dataset.confirmBound === '1') return;
                form.dataset.confirmBound = '1';
                const msg = form.getAttribute('data-confirm');
                form.addEventListener('submit', ev => {
                    if (form.dataset.confirming === '1') { form.dataset.confirming = ''; return; }
                    ev.preventDefault();
                    showConfirm(msg, () => {
                        form.dataset.confirming = '1';
                        if (form.requestSubmit) form.requestSubmit(); else form.submit();
                    });
                });
            });
        })();
        // Сброс пароля через prompt
        document.querySelectorAll('form[data-reset-pass]').forEach(form => {
            const btn = form.querySelector('button[type="submit"]');
            form.addEventListener('submit', (e) => {
                if (form.dataset.ready === '1') { form.dataset.ready=''; return; }
                e.preventDefault();
                const val = window.prompt('Новый пароль');
                if (val && val.trim().length > 0) {
                    const input = form.querySelector('input[name="new_password"]');
                    if (input) {
                        input.value = val.trim();
                        form.dataset.ready = '1';
                        form.submit();
                    }
                }
            });
            if (btn) {
                btn.addEventListener('click', (e) => {
                    // no-op; submit handler handles the flow
                });
            }
        });
        
        // Защита от множественных кликов на кнопки активации/деактивации
        document.querySelectorAll('form.toggle-active-form').forEach(form => {
            const btn = form.querySelector('button[type="submit"]');
            let isSubmitting = false;
            
            form.addEventListener('submit', function(e) {
                if (isSubmitting || form.dataset.submitting === '1') {
                    e.preventDefault();
                    e.stopPropagation();
                    e.stopImmediatePropagation();
                    return false;
                }
                
                form.dataset.submitting = '1';
                isSubmitting = true;
                
                // Блокируем кнопку
                if (btn) {
                    btn.disabled = true;
                    btn.style.opacity = '0.6';
                    btn.style.cursor = 'not-allowed';
                }
                
                // Разблокируем через 3 секунды, если что-то пошло не так (на случай ошибки)
                setTimeout(() => {
                    form.dataset.submitting = '';
                    isSubmitting = false;
                    if (btn) {
                        btn.disabled = false;
                        btn.style.opacity = '';
                        btn.style.cursor = '';
                    }
                }, 3000);
            });
        });

        // Рассылка
        (function() {
            const btn = document.getElementById('broadcast-start');
            const textArea = document.getElementById('broadcast-text');
            if (!btn || !textArea) return;
            btn.addEventListener('click', () => {
                const text = (textArea.value || '').trim();
                if (!text) {
                    alert('Введите текст для рассылки');
                    return;
                }
                if (!confirm('Запустить рассылку?')) return;
                alert('Рассылка будет реализована на бэкенде (пока заглушка)');
            });
        })();

        // Проверка TG бота
        (function() {
            const btn = document.getElementById('tg-bot-check');
            const statusEl = document.getElementById('tg-bot-status');
            if (!btn || !statusEl) return;
            btn.addEventListener('click', async () => {
                statusEl.textContent = 'Проверяем...';
                statusEl.style.color = '';
                try {
                    const resp = await fetch('http://192.168.3.110:8080/check_verification', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ ping: true }),
                    });
                    if (resp.ok) {
                        statusEl.textContent = 'OK: бот отвечает';
                        statusEl.style.color = '#22c55e';
                    } else {
                        statusEl.textContent = 'Ошибка: ' + resp.status;
                        statusEl.style.color = '#f87171';
                    }
                } catch (e) {
                    statusEl.textContent = 'Недоступен';
                    statusEl.style.color = '#f87171';
                }
            });
        })();
        
        // Создание пользователя: переключатель роли (щит)
        (function(){
            const btn = document.getElementById('new-admin-toggle');
            const input = document.getElementById('new_is_admin');
            if (!btn || !input) return;
            const sync = () => {
                const val = input.value;
                btn.classList.remove('role-moderator', 'role-admin');
                if (val === '2') {
                    btn.classList.add('role-moderator');
                    btn.setAttribute('title', 'Модератор');
                } else if (val === '1') {
                    btn.classList.add('role-admin');
                    btn.setAttribute('title', 'Админ');
                } else {
                    btn.setAttribute('title', 'Пользователь');
                }
                btn.setAttribute('aria-pressed', val !== '0' ? 'true' : 'false');
            };
            btn.addEventListener('click', () => {
                // Переключение: 0 -> 2 -> 1 -> 0
                const val = input.value;
                input.value = val === '0' ? '2' : (val === '2' ? '1' : '0');
                sync();
            });
            sync();
        })();
    </script>
    
    <!-- Модальное окно информации о пользователе -->
    <div id="user-modal" class="user-modal" style="display:none;">
        <div class="user-modal-backdrop" onclick="closeUserModal()"></div>
        <div class="user-modal-content">
            <button class="user-modal-close" onclick="closeUserModal()" aria-label="Закрыть">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 4L4 12M4 4L12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </button>
            <div class="user-modal-body">
                <div class="user-modal-avatar" id="user-modal-avatar"></div>
                <div class="user-modal-info">
                    <div class="user-modal-field">
                        <span class="user-modal-label">Логин:</span>
                        <span class="user-modal-value" id="user-modal-username"></span>
                    </div>
                    <div class="user-modal-field">
                        <span class="user-modal-label">Имя:</span>
                        <span class="user-modal-value" id="user-modal-fullname"></span>
                    </div>
                    <div class="user-modal-field">
                        <span class="user-modal-label">Telegram:</span>
                        <span class="user-modal-value" id="user-modal-telegram"></span>
                    </div>
                    <div class="user-modal-field">
                        <span class="user-modal-label">Дата регистрации:</span>
                        <span class="user-modal-value" id="user-modal-created"></span>
                    </div>
                    <div class="user-modal-field">
                        <span class="user-modal-label">Добавлено песен:</span>
                        <span class="user-modal-value" id="user-modal-songs"></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        function showUserModal(userData) {
            if (!userData) return;
            // Если пришла строка (из inline JSON), пытаемся распарсить
            if (typeof userData === 'string') {
                try {
                    userData = JSON.parse(userData);
                } catch (e) {
                    return;
                }
            }
            if (typeof userData !== 'object') return;
            const safeData = {
                id: null,
                username: '',
                full_name: '',
                hasAvatar: false,
                avatarUrl: null,
                initial: 'U',
                created_at: '',
                songs_count: 0,
                is_admin: 0,
                ...userData
            };
            const modal = document.getElementById('user-modal');
            const modalContent = modal.querySelector('.user-modal-content');
            const avatarEl = document.getElementById('user-modal-avatar');
            const usernameEl = document.getElementById('user-modal-username');
            const fullnameEl = document.getElementById('user-modal-fullname');
            const createdEl = document.getElementById('user-modal-created');
            const songsEl = document.getElementById('user-modal-songs');
            const tgEl = document.getElementById('user-modal-telegram');
            if (!modal || !modalContent || !avatarEl || !usernameEl || !fullnameEl || !createdEl || !songsEl || !tgEl) return;
            
            // Добавляем класс для роли
            modalContent.classList.remove('role-moderator', 'role-admin');
            if (safeData.is_admin == 1) {
                modalContent.classList.add('role-admin');
            } else if (safeData.is_admin == 2) {
                modalContent.classList.add('role-moderator');
            }
            
            // Аватар
            avatarEl.innerHTML = '';
            if (safeData.hasAvatar && safeData.avatarUrl) {
                const img = document.createElement('img');
                img.src = safeData.avatarUrl;
                img.alt = 'Avatar';
                img.style.cssText = 'width:100%; height:100%; object-fit:cover; display:block;';
                avatarEl.appendChild(img);
            } else {
                avatarEl.textContent = safeData.initial || 'U';
            }
            
            // Данные
            usernameEl.textContent = safeData.username || '—';
            fullnameEl.textContent = safeData.full_name || '—';
            
            // Дата регистрации
            let createdText = '—';
            if (safeData.created_at) {
                const createdDate = new Date(safeData.created_at);
                if (!Number.isNaN(createdDate.getTime())) {
                    createdText = createdDate.toLocaleDateString('ru-RU', {
                        year: 'numeric',
                        month: 'long',
                        day: 'numeric'
                    });
                }
            }
            createdEl.textContent = createdText;
            
            // Telegram (кликабельно)
            if (safeData.telegram) {
                const tgUser = String(safeData.telegram).replace(/^@+/, '');
                tgEl.innerHTML = '';
                const a = document.createElement('a');
                a.href = 'https://t.me/' + encodeURIComponent(tgUser);
                a.textContent = '@' + tgUser;
                a.target = '_blank';
                a.rel = 'noopener noreferrer';
                a.style.color = 'inherit';
                a.style.textDecoration = 'none';
                tgEl.appendChild(a);
            } else {
                tgEl.textContent = '—';
            }
            
            // Количество песен
            songsEl.textContent = safeData.songs_count ?? 0;
            
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }
        
        function closeUserModal() {
            const modal = document.getElementById('user-modal');
            modal.style.display = 'none';
            document.body.style.overflow = '';
        }
        
        // Закрытие по Escape
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                const modal = document.getElementById('user-modal');
                if (modal && modal.style.display !== 'none') {
                    closeUserModal();
                }
            }
        });

        // Подключаем клики на карточки пользователей через data-атрибут
        (function() {
            document.querySelectorAll('.user-row[data-user]').forEach(row => {
                row.addEventListener('click', () => {
                    const data = row.getAttribute('data-user');
                    if (data) {
                        showUserModal(data);
                    }
                });
            });
        })();
    </script>
    
    <script src="/js/sidebar-cache.js"></script>
    <script src="/js/theme-switcher.js"></script>
    <script src="/js/app.js"></script>
</body>
</html>
