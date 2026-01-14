<?php
$username = require __DIR__ . '/includes/auth_required.php';
require_once __DIR__ . '/includes/user_helper.php';
require_once __DIR__ . '/chord_parser.php';

$userData = getCurrentUser($username);
extract($userData);
$db = DB::getConnection();

// Разрешаем доступ админам и модераторам
if (!$user || ((int)$user['is_admin'] != 1 && (int)$user['is_admin'] != 2)) {
    header('Location: /');
    exit;
}
$isAdmin = (int)$user['is_admin'] === 1;
$isModerator = (int)$user['is_admin'] === 2;

$errors = [];
$success = '';
$csrf = csrf_token();

function detectLocale($text) {
    $cyr = 0;
    if (preg_match_all('/[А-Яа-яЁё]+/u', $text, $m)) { $cyr = count($m[0]); }
    return $cyr > 10 ? 'ru' : 'foreign';
}

/**
 * Проверка дубликата песни:
 * 1) Совпадают название и исполнитель
 * 2) Или есть >=5 одинаковых непустых строк текста
 */
function isDuplicateSong(PDO $db, string $title, string $artist = null, string $normalizedLyrics = '', ?int $excludeId = null): bool {
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
    if ($stmt->fetchColumn()) return true;

    $linesNew = [];
    $rawLines = preg_split('/\R/u', (string)$normalizedLyrics);
    foreach ($rawLines as $ln) {
        $ln = trim($ln);
        if ($ln === '') continue;
        $linesNew[$ln] = true;
    }
    if (count($linesNew) < 5) return false;

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

if (isset($_GET['analyze']) && $_GET['analyze'] == '1') {
    $title = trim($_GET['title'] ?? '');
    $artist = trim($_GET['artist'] ?? '');
    $lyrics = trim($_GET['lyrics'] ?? '');
    if ($title && $lyrics) {
        $normalizedLyrics = ChordParser::replaceChordsWithBrackets($lyrics);
        $db->beginTransaction();
        $addedBy = isset($user['id']) ? (int)$user['id'] : null;
        $stmt = $db->prepare('INSERT INTO songs (title, artist, cap, first_note, lyrics, added_by) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$title, $artist, null, null, $normalizedLyrics, $addedBy]);
        $songId = $db->lastInsertId();
        $chords = ChordParser::extractAllChords($normalizedLyrics);
        if (!empty($chords)) {
            $insertChord = $db->prepare('INSERT INTO chords (song_id, chord_text, char_position) VALUES (?, ?, ?)');
            foreach ($chords as $chord) {
                $insertChord->execute([$songId, $chord['text'], $chord['position']]);
            }
        }
        $db->commit();
        header('Location: /edit_song.php?id=' . $songId . '&created=1');
        exit;
    } else {
        $errors[] = 'Для создания песни нужны название и текст.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'analyze_song') {
    require_csrf();
    $lyrics = trim($_POST['lyrics'] ?? '');
    $stats = ['text' => 0, 'chords' => 0, 'empty' => 0];
    $lines = explode("\n", $lyrics);
    foreach ($lines as $line) {
        $type = ChordParser::getLineType($line);
        $stats[$type] = ($stats[$type] ?? 0) + 1;
    }
    $chords = ChordParser::extractAllChords($lyrics);
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'chords' => $chords, 'stats' => $stats]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_song') {
    require_csrf();
    $songId = (int)($_POST['song_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $artist = trim($_POST['artist'] ?? '');
    $lyrics = trim($_POST['lyrics'] ?? '');
    $cap = trim($_POST['cap'] ?? '');
    $firstNote = trim($_POST['first_note'] ?? '');
    $skill = (int)($_POST['skill_stars'] ?? 0);
    $pop = (int)($_POST['popularity_stars'] ?? 0);
    $comment = trim($_POST['comment'] ?? '');
    
    // Проверка доступа для модераторов: могут редактировать только свои песни
    if ($songId > 0 && $isModerator) {
        $checkStmt = $db->prepare('SELECT added_by FROM songs WHERE id = ?');
        $checkStmt->execute([$songId]);
        $checkSong = $checkStmt->fetch();
        if (!$checkSong || (int)$checkSong['added_by'] !== (int)$user['id']) {
            $errors[] = 'Вы можете редактировать только свои песни.';
        }
    }
    
    if ($title === '') { $errors[] = 'Название обязательно.'; }
    if ($songId === 0 && empty($errors)) {
        // Ранняя проверка на дубликат перед транзакцией
        $normalizedLyricsTmp = ChordParser::replaceChordsWithBrackets($lyrics);
        if (isDuplicateSong($db, $title, $artist, $normalizedLyricsTmp, null)) {
            $errors[] = 'Кажется такая песня уже добавлена';
        }
    }
    if (empty($errors)) {
        $normalizedLyrics = ChordParser::replaceChordsWithBrackets($lyrics);
        $locale = detectLocale($normalizedLyrics);
        $addedBy = isset($user['id']) ? (int)$user['id'] : null;
        
        try {
            $db->beginTransaction();
            
            if ($songId > 0) {
                // Получаем данные песни для логирования
                $songStmt = $db->prepare('SELECT title, artist FROM songs WHERE id = ?');
                $songStmt->execute([$songId]);
                $songData = $songStmt->fetch();
                
                // Для модераторов добавляем проверку added_by в WHERE
                $updateSuccess = false;
                if ($isModerator) {
                    $stmt = $db->prepare('UPDATE songs SET title = ?, artist = ?, cap = ?, first_note = ?, skill_stars = ?, popularity_stars = ?, locale = ?, lyrics = ?, comment = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND added_by = ?');
                    $updateSuccess = $stmt->execute([$title, $artist, $cap ?: null, $firstNote ?: null, $skill, $pop, $locale ?: null, $normalizedLyrics, $comment ?: null, $songId, (int)$user['id']]);
                    $updateSuccess = $updateSuccess && $stmt->rowCount() > 0;
                } else {
                    $stmt = $db->prepare('UPDATE songs SET title = ?, artist = ?, cap = ?, first_note = ?, skill_stars = ?, popularity_stars = ?, locale = ?, lyrics = ?, comment = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
                    $updateSuccess = $stmt->execute([$title, $artist, $cap ?: null, $firstNote ?: null, $skill, $pop, $locale ?: null, $normalizedLyrics, $comment ?: null, $songId]);
                    $updateSuccess = $updateSuccess && $stmt->rowCount() > 0;
                }
                
                if (!$updateSuccess) {
                    $db->rollBack();
                    $errors[] = 'Не удалось обновить песню.';
                } else {
                    // Удаляем старые аккорды
                    $deleteChordsStmt = $db->prepare('DELETE FROM chords WHERE song_id = ?');
                    $deleteChordsStmt->execute([$songId]);
                    
                    // Добавляем новые аккорды
                    $chords = ChordParser::extractAllChords($normalizedLyrics);
                    if (!empty($chords)) {
                        $insertChord = $db->prepare('INSERT INTO chords (song_id, chord_text, char_position) VALUES (?, ?, ?)');
                        foreach ($chords as $chord) {
                            $insertChord->execute([
                                $songId,
                                ChordParser::normalizeChord($chord['text']),
                                $chord['position']
                            ]);
                        }
                    }
                    
                    $db->commit();
                    
                    // Логируем событие редактирования песни (только для админов) - ПОСЛЕ commit
                    if ($songData && $isAdmin) {
                        try {
                            if (!function_exists('logHistory')) {
                                require_once __DIR__ . '/includes/history_helper.php';
                            }
                            $songTitle = $songData['title'];
                            $description = "отредактировал песню \"{$songTitle}\"";
                            $oldValues = [
                                'title' => $songData['title'] ?? '',
                                'artist' => $songData['artist'] ?? ''
                            ];
                            $newValues = [
                                'title' => $title,
                                'artist' => $artist ?? ''
                            ];
                            logHistory(
                                'UPDATE',
                                'songs',
                                $songId,
                                $oldValues,
                                $newValues,
                                $description,
                                $description
                            );
                        } catch (Throwable $logError) {
                            // Логируем ошибку логирования, но не прерываем выполнение
                            error_log('Error logging history in edit_song: ' . $logError->getMessage());
                            error_log('Error trace: ' . $logError->getTraceAsString());
                        }
                    }
                    
                    header('Location: /songs.php?saved=1');
                    exit;
                }
            } else {
                // Создание новой песни
                $stmt = $db->prepare('INSERT INTO songs (title, artist, cap, first_note, skill_stars, popularity_stars, locale, lyrics, comment, added_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$title, $artist, $cap ?: null, $firstNote ?: null, $skill, $pop, $locale ?: null, $normalizedLyrics, $comment ?: null, $addedBy]);
                $songId = (int)$db->lastInsertId();
                
                // Добавляем аккорды
                $chords = ChordParser::extractAllChords($normalizedLyrics);
                if (!empty($chords)) {
                    $insertChord = $db->prepare('INSERT INTO chords (song_id, chord_text, char_position) VALUES (?, ?, ?)');
                    foreach ($chords as $chord) {
                        $insertChord->execute([
                            $songId,
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
                $descCreate = $artist ? "создал песню \"{$title}\" — {$artist}" : "создал песню \"{$title}\"";
                logHistory(
                    'CREATE',
                    'songs',
                    (int)$songId,
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
                    $descCreate,
                    $descCreate
                );
                header('Location: /songs.php?saved=1');
                exit;
            }
        } catch (Throwable $e) {
            // Откатываем транзакцию при ошибке
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $errors[] = 'Ошибка при сохранении: ' . $e->getMessage();
            error_log('Error in edit_song save: ' . $e->getMessage());
            error_log('Error trace: ' . $e->getTraceAsString());
        }
    }
}

$songId = (int)($_GET['id'] ?? ($_POST['song_id'] ?? 0));
$isNew = (isset($_GET['new']) && !$songId);
$song = null; $chords = [];
if ($songId > 0) {
    $stmt = $db->prepare('SELECT * FROM songs WHERE id = ?');
    $stmt->execute([$songId]);
    $song = $stmt->fetch();
    if ($song) {
        // Проверка доступа для модераторов: могут редактировать только свои песни
        if ($isModerator && (int)$song['added_by'] !== (int)$user['id']) {
            $errors[] = 'Вы можете редактировать только свои песни.';
            $song = null;
        } else {
            $stmt = $db->prepare('SELECT * FROM chords WHERE song_id = ? ORDER BY char_position');
            $stmt->execute([$songId]);
            $chords = $stmt->fetchAll();
        }
    } else { $errors[] = 'Песня не найдена.'; }
} else {
    $song = [
        'id' => 0,
        'title' => '',
        'artist' => '',
        'cap' => '',
        'first_note' => '',
        'skill_stars' => '',
        'popularity_stars' => '',
        'lyrics' => '',
        'comment' => ''
    ];
}
?>
<!DOCTYPE html>
<html lang='ru'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Редактирование песни</title>
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
        .sidebar { background:var(--panel); padding:1.2rem; border-right:1px solid var(--border); position:fixed; top:0; left:0; bottom:0; width:260px; overflow:auto; }
        .brand { font-weight:700; letter-spacing:0.5px; color:var(--text); margin-bottom:1rem; display:flex; align-items:center; gap:0.5rem; }
        .nav { display:grid; gap:0.4rem; }
        .nav a { display:block; padding:0.75rem 0.9rem; border-radius:10px; color:var(--text); text-decoration:none; background:var(--card-bg); border:1px solid var(--border); transition:0.2s; }
        .nav a:hover { background:color-mix(in srgb, var(--accent) 15%, transparent); }
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
        .user-menu { margin-top:0.3rem; width:100%; background:var(--panel); border:1px solid rgba(255,255,255,0.12); border-radius:10px; box-shadow:0 10px 24px rgba(0,0,0,0.35); padding:0.3rem 0; display:none; }
        .user-menu a { display:block; padding:0.6rem 0.9rem; color:var(--text); text-decoration:none; }
        .user-menu a:hover { background:rgba(255,255,255,0.06); }
        .backdrop {
            position:fixed;
            inset:0;
            background:rgba(0,0,0,0.5);
            z-index:2999;
            display:none;
        }
        .backdrop.show {
            display:block;
        }
        .topbar { display:flex; justify-content:flex-end; align-items:center; margin-bottom:1rem; }
        .card { background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.06); border-radius:14px; padding:1.4rem; margin-bottom:1rem; }
        h1 { margin:0 0 0.5rem; font-size:2rem; }
        label, .meta, p { color:var(--muted); }
        .message { padding:0.9rem 1rem; border-radius:10px; margin-bottom:0.8rem; }
        .message.error { background:rgba(231,76,60,0.12); border:1px solid rgba(231,76,60,0.35); color:#f6a9a0; }
        .message.success { background:rgba(46,204,113,0.12); border:1px solid rgba(46,204,113,0.35); color:#7ef2b5; }
        .form-group { margin-bottom:1rem; }
        input[type='text'], textarea, input[type='number'] { width:100%; padding:0.75rem; border-radius:10px; border:1px solid var(--input-border); background:var(--input-bg); color:var(--text); }
        textarea { min-height:120px; }
        /* Комментарий по умолчанию — компактный, как почти однострочное поле */
        #comment {
            min-height: 2.8rem;
            height: auto;
            resize: vertical;
        }
        input:focus, textarea:focus { outline:none; border-color:rgba(102,126,234,0.7); }
        .btn { padding:0.7rem 1.2rem; border-radius:10px; background:var(--accent); color:#fff; text-decoration:none; font-weight:600; border:none; cursor:pointer; display:inline-block; }
        .btn.secondary { background:transparent; border:1px solid var(--btn-outline-border, var(--border)); color:var(--btn-outline-text, var(--text)); }
        .btn.secondary:hover { background:color-mix(in srgb, var(--accent) 10%, transparent); border-color:var(--accent); color:var(--accent); }
        .chord-editor { position:relative; font-family:'Courier New', monospace; background:#0f172a; border:1px dashed #2f3c6e; border-radius:12px; padding:1rem; min-height:220px; white-space:pre; }
        .editor-line { position:relative; min-height:28px; padding:4px 0; }
        .editor-line + .editor-line { border-top:1px solid rgba(255,255,255,0.06); }
        .editor-text { padding:4px 2px; outline:none; min-height:24px; }
        .editor-chords { position:relative; min-height:24px; cursor:text; }
        .chord-block { position:absolute; top:0; padding:2px 4px; background:var(--accent); color:#fff; border-radius:4px; cursor:grab; user-select:none; font-weight:bold; }
        .chord-block:active { cursor:grabbing; opacity:0.9; }
        .chord-input { position:absolute; top:-2px; left:0; padding:2px 4px; font-family:inherit; border:1px solid var(--accent); border-radius:4px; outline:none; min-width:60px; }
        .hint { margin-top:0.5rem; color:var(--muted); font-size:0.9rem; }
        .editor-actions { margin-top:0.5rem; display:flex; gap:0.5rem; flex-wrap:wrap; }
        .context-menu { position:fixed; background:#fff; color:#111; border:1px solid #ddd; border-radius:6px; box-shadow:0 6px 20px rgba(0,0,0,0.15); padding:0.25rem 0; z-index:1000; display:none; min-width:180px; }
        .context-menu .item { padding:0.5rem 0.75rem; cursor:pointer; font-size:0.95rem; }
        .context-menu .item:hover { background:#f0f3ff; color:#3b4cca; }
        .visually-hidden { display:none; }
        .stars { display:flex; gap:6px; align-items:center; }
        .star { font-size:1.8rem; color:#4b5563; cursor:pointer; transition:color 0.15s; }
        .star.active { color:#fbbf24; }
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
            /* Изменение порядка полей для мобильной версии */
            .form-grid {
                display: flex !important;
                flex-direction: column;
            }
            .form-grid > .form-group {
                width: 100%;
            }
            /* Название - порядок 1 */
            .field-title {
                order: 1;
            }
            /* Исполнитель - порядок 2 */
            .field-artist {
                order: 2;
            }
            /* Cap - порядок 3 */
            .field-cap {
                order: 3;
            }
            /* Первая вокальная нота - порядок 4 */
            .field-first-note {
                order: 4;
            }
            /* Популярность - порядок 5 */
            .field-popularity {
                order: 5;
            }
            /* Навык - порядок 6 */
            .field-skill {
                order: 6;
            }
            /* Комментарий - порядок 7 */
            .field-comment {
                order: 7;
                grid-column: 1 !important;
                grid-row: auto !important;
            }
            /* Редактор - порядок 8 */
            #raw-container {
                order: 8;
            }
            #editor-container {
                order: 9;
            }
            .sidebar.open { transform:translateX(0); }
            .content { padding:1rem; margin-left:0; }
            /* Заголовок по центру на мобиле */
            h1 {
                text-align: center;
            }
            .toggle {
                position:fixed;
                top:12px;
                left:12px;
                z-index:11;
                padding:0.6rem 0.9rem;
                border-radius:10px;
                border:1px solid rgba(255,255,255,0.2);
                background:rgba(0,0,0,0.4);
                color:#fff;
                cursor:pointer;
                font-size: 1.2rem;
                min-width: 44px;
                min-height: 44px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .topbar {
                margin-top: 3rem;
            }
            .card {
                padding: 1.2rem;
            }
            h1 {
                font-size: 1.75rem;
            }
            div[style*="grid-template-columns"] {
                grid-template-columns: 1fr !important;
                gap: 1rem !important;
            }
            div[style*="grid-column"] {
                grid-column: 1 !important;
            }
            input[type='text'],
            textarea,
            input[type='number'] {
                font-size: 16px;
            }
            /* Текстовые поля на мобиле */
            textarea {
                min-height: 100px;
            }
            /* Комментарий — компактнее на мобильных, почти как одна строка */
            #comment {
                min-height: 2.6rem;
                height: auto;
                resize: vertical;
            }
            /* Поле вставки текста песни — адаптация под высоту экрана */
            #raw-lyrics {
                min-height: 40vh;
                max-height: 65vh;
                height: 40vh;
                resize: none;
                overflow-y: auto;
                -webkit-overflow-scrolling: touch;
            }
            .chord-editor {
                min-height: 200px;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            .editor-line {
                min-height: 32px;
            }
            .editor-actions {
                flex-direction: column;
            }
            .editor-actions .btn {
                width: 100%;
            }
            .stars {
                justify-content: flex-start;
            }
            .star {
                font-size: 1.6rem;
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
            .toggle {
                top: 8px;
                left: 8px;
                padding: 0.5rem 0.75rem;
            }
            .chord-editor {
                min-height: 180px;
                padding: 0.75rem;
            }
            /* На очень маленьких экранах ещё аккуратнее поле комментария и текста */
            #comment {
                min-height: 2.4rem;
            }
            #raw-lyrics {
                min-height: 45vh;
                max-height: 70vh;
                height: 45vh;
            }
            .editor-line {
                min-height: 28px;
            }
            .star {
                font-size: 1.4rem;
            }
            .hint {
                font-size: 0.85rem;
            }
        }
    </style>
</head>
<body>
    <div class="layout">
        <!-- Sidebar будет загружен через JavaScript -->
        <main class="content">
            <div class="card">
                <h1>Редактирование песни</h1>
                <?php if (!empty($errors)): foreach ($errors as $error): ?><div class='message error'><?php echo htmlspecialchars($error); ?></div><?php endforeach; endif; ?>
                <?php if ($success): ?><div class='message success'><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
                <?php if ($song): ?>
                    <?php if (isset($_GET['created'])): ?><div class='message success'>Песня создана автоматически</div><?php endif; ?>
                    <form method='POST' id="song-form" data-song-id="<?php echo (int)$song['id']; ?>">
                        <input type='hidden' name='action' value='update_song'>
                        <input type='hidden' name='song_id' value='<?php echo $song['id']; ?>'>
                        <input type='hidden' name='csrf_token' value='<?php echo htmlspecialchars($csrf); ?>'>
                        <div class="form-grid" style="display:grid; grid-template-columns:repeat(2,minmax(260px,1fr)); gap:1rem; grid-auto-rows:minmax(0,auto);">
                            <div class='form-group field-title'>
                                <label for='title'>Название *</label>
                                <input type='text' id='title' name='title' value='<?php echo htmlspecialchars($song['title'] ?? ''); ?>' required>
                            </div>
                            <div class='form-group field-popularity'>
                                <label for='popularity_stars'>Популярность (1-5)</label>
                                <div class="stars" data-target="popularity_stars">
                                    <?php for($i=1;$i<=5;$i++): ?>
                                        <span class="star" data-value="<?php echo $i; ?>">★</span>
                                    <?php endfor; ?>
                                </div>
                                <input type='hidden' id='popularity_stars' name='popularity_stars' value='<?php echo htmlspecialchars($song['popularity_stars'] ?? ''); ?>'>
                            </div>
                            <div class='form-group field-artist'>
                                <label for='artist'>Исполнитель</label>
                                <input type='text' id='artist' name='artist' value='<?php echo htmlspecialchars($song['artist'] ?? ''); ?>'>
                            </div>
                            <div class='form-group field-skill'>
                                <label for='skill_stars'>Навык (1-5)</label>
                                <div class="stars" data-target="skill_stars">
                                    <?php for($i=1;$i<=5;$i++): ?>
                                        <span class="star" data-value="<?php echo $i; ?>">★</span>
                                    <?php endfor; ?>
                                </div>
                                <input type='hidden' id='skill_stars' name='skill_stars' value='<?php echo htmlspecialchars($song['skill_stars'] ?? ''); ?>'>
                            </div>
                            <div class='form-group field-cap'>
                                <label for='cap'>Cap (каподастр)</label>
                                <input type='text' id='cap' name='cap' value='<?php echo htmlspecialchars($song['cap'] ?? ''); ?>'>
                            </div>
                            <div class='form-group field-comment' style="grid-column:2; grid-row:3 / span 2;">
                                <label for='comment'>Комментарий</label>
                                <textarea id='comment' name='comment' rows='1' style="background:rgba(23,30,50,0.9); color:var(--text); border:1px solid rgba(255,255,255,0.12);"><?php echo htmlspecialchars($song['comment'] ?? ''); ?></textarea>
                            </div>
                            <div class='form-group field-first-note'>
                                <label for='first_note'>Первая вокальная нота</label>
                                <input type='text' id='first_note' name='first_note' value='<?php echo htmlspecialchars($song['first_note'] ?? ''); ?>'>
                            </div>
                        </div>
                        <div class='form-group' id="raw-container">
                            <label for='raw-lyrics'>Текст песни (вставка)</label>
                            <textarea id='raw-lyrics' rows='10' style="min-height:180px; font-family:monospace; resize:vertical;"><?php echo htmlspecialchars($song['lyrics'] ?? ''); ?></textarea>
                            <div class='hint'>Вставьте текст с аккордами. Нажмите “Анализ (текст)” чтобы обновить интерактивный редактор ниже.</div>
                            <div style="margin-top:0.5rem; display:flex; gap:0.5rem; flex-wrap:wrap;">
                                <button type='button' class='btn secondary' id="analyze-btn">Анализ</button>
                            </div>
                        </div>
                        <textarea id='lyrics' name='lyrics' class='visually-hidden' rows='20'><?php echo htmlspecialchars($song['lyrics'] ?? ''); ?></textarea>
                        <div class='form-group' id="editor-container">
                            <label>Интерактивный редактор аккордов</label>
                            <div id='editor' class='chord-editor'></div>
                            <div class='hint'>Двойной клик по аккорду — редактировать; двойной клик по пустому месту строки аккордов — добавить; перетаскивайте аккорды мышью.</div>
                            <div style="margin-top:0.5rem; display:flex; gap:0.5rem; flex-wrap:wrap;">
                                <button type='submit' class='btn'>Сохранить</button>
                                <button type='button' class='btn secondary' id="text-editor-btn">Текстовый редактор</button>
                            </div>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </main>
    </div>
    <script>
        (function() {
            const textarea = document.getElementById('lyrics');
            const editor = document.getElementById('editor');
            if (!textarea || !editor) return;
            // stars init
            document.querySelectorAll('.stars').forEach(box => {
                const targetId = box.dataset.target;
                const hidden = document.getElementById(targetId);
                const stars = box.querySelectorAll('.star');
                const apply = (val) => {
                    stars.forEach(st => st.classList.toggle('active', Number(st.dataset.value) <= val));
                    if (hidden) hidden.value = val;
                };
                const initial = parseInt(hidden?.value || '0', 10) || 0;
                apply(initial);
                stars.forEach(st => {
                    st.addEventListener('click', () => apply(Number(st.dataset.value)));
                });
            });
            const chordRegex = /\b([A-GH])([#b]?)(?:(?:maj|maj7|maj9|maj11|maj13|min|m|min7|min9|min11|min13|m7|m9|m11|m13|dim|dim7|°|aug|\+|sus|sus2|sus4|add|add9|add11|add13|6|69|7|9|11|13|7b5|7#5|7b9|7#9|7#11|7b13|m6|m69|m7b5|m7#5|b5|#5|b9|#9|b11|#11|b13|#13)?[0-9+#b]*)(?:\/([A-GH][#b]?))?\b/i;
            const measureEl = document.createElement('span');
            measureEl.style.visibility = 'hidden'; measureEl.style.position = 'absolute'; measureEl.style.whiteSpace = 'pre'; measureEl.style.fontFamily = "'Courier New', monospace"; measureEl.textContent = 'M'; document.body.appendChild(measureEl); const CHAR_WIDTH = measureEl.getBoundingClientRect().width || 8; document.body.removeChild(measureEl);
            const contextMenu = document.createElement('div'); contextMenu.className = 'context-menu'; document.body.appendChild(contextMenu); let contextTarget = null;
            function normalize(text) { if (!text) return ''; text = text.replace(/[()]/g, '').trim(); 
                // Заменяем H на B (немецкая/русская нотация: H = B)
                if (text.match(/^[Hh]/)) {
                    text = 'B' + text.substring(1);
                }
                // Также заменяем H в басовых нотах
                text = text.replace(/\/([Hh])([#b]?)$/i, '/B$2');
                const m = text.match(/^([A-Ga-g])([#b]?)(.*)$/); if (!m) return text.toUpperCase(); const root = m[1].toUpperCase(); const acc = m[2] === '#' ? '#' : (m[2].toLowerCase() === 'b' ? 'b' : ''); let rest = m[3] || ''; let bass = ''; const bassMatch = rest.match(/^(.*)\/([A-Ga-g][#b]?)$/); if (bassMatch) { rest = bassMatch[1]; const b = bassMatch[2]; const bRoot = b[0].toUpperCase(); const bAcc = b[1] === '#' ? '#' : (b[1] && b[1].toLowerCase() === 'b' ? 'b' : ''); bass = '/' + bRoot + bAcc; } 
                // Важно: обрабатываем m7 до toLowerCase, чтобы избежать проблем
                // Заменяем возможные опечатки majj на maj
                rest = rest.replace(/majj/g, 'maj');
                rest = rest.toLowerCase(); 
                return root + acc + rest + bass; }
            function isChordToken(token) { return chordRegex.test(token.replace(/[()]/g, '').trim()); }
            function isChordLine(line) { const tokens = line.trim().split(/\s+/).filter(Boolean); if (!tokens.length) return false; return tokens.every(isChordToken); }
            function parseChordPositions(line) { const blocks = []; const regex = /\([^()]*\)|[A-GH][#b]?(?:(?:maj|maj7|maj9|maj11|maj13|min|m|min7|min9|min11|min13|m7|m9|m11|m13|dim|dim7|°|aug|\+|sus|sus2|sus4|add|add9|add11|add13|6|69|7|9|11|13|7b5|7#5|7b9|7#9|7#11|7b13|m6|m69|m7b5|m7#5|b5|#5|b9|#9|b11|#11|b13|#13)?[0-9+#b]*)(?:\/[A-GH][#b]?)?/gi; let match; while ((match = regex.exec(line)) !== null) { const raw = match[0]; let text = raw.replace(/[()]/g, ''); 
                // Заменяем H на B сразу при парсинге
                if (text.match(/^[Hh]/)) {
                    text = 'B' + text.substring(1);
                }
                text = text.replace(/\/([Hh])([#b]?)$/i, '/B$2');
                blocks.push({ text, pos: match.index }); } return blocks; }
            function buildEditor(lines) { editor.innerHTML = ''; lines.forEach((line, idx) => { if (isChordLine(line)) { editor.appendChild(createChordLine(idx, parseChordPositions(line))); } else { editor.appendChild(createTextLine(idx, line)); } }); }
            function rebuildEditorFromText(text) {
                const norm = (text || '').replace(/\r\n/g, '\n');
                const lines = norm.split('\n');
                buildEditor(lines);
                textarea.value = norm;
            }
            function attachLineContext(lineEl) { lineEl.addEventListener('contextmenu', ev => { if (ev.defaultPrevented) return; ev.preventDefault(); showContextMenu(ev, { type: 'line', target: lineEl }); }); }
            function createChordLine(idx, blocks = []) { const lineEl = document.createElement('div'); lineEl.className = 'editor-line'; lineEl.dataset.index = idx; const chordsWrap = document.createElement('div'); chordsWrap.className = 'editor-chords'; chordsWrap.dataset.type = 'chords'; chordsWrap.dataset.index = idx; chordsWrap.style.height = '28px'; chordsWrap.addEventListener('dblclick', onEmptyDblClick); chordsWrap.addEventListener('contextmenu', ev => { if (ev.target !== chordsWrap) return; ev.preventDefault(); ev.stopPropagation(); const rect = chordsWrap.getBoundingClientRect(); const offset = ev.clientX - rect.left; const pos = Math.max(0, Math.round(offset / CHAR_WIDTH)); showContextMenu(ev, { type: 'add-chord', target: chordsWrap, pos }); }); blocks.forEach(block => chordsWrap.appendChild(makeChordBlock(block.text, block.pos))); lineEl.appendChild(chordsWrap); attachLineContext(lineEl); return lineEl; }
            function createTextLine(idx, text = '') { const lineEl = document.createElement('div'); lineEl.className = 'editor-line'; lineEl.dataset.index = idx; const textEl = document.createElement('div'); textEl.className = 'editor-text'; textEl.contentEditable = 'true'; textEl.dataset.type = 'text'; textEl.dataset.index = idx; textEl.textContent = text; lineEl.appendChild(textEl); attachLineContext(lineEl); return lineEl; }
            function makeChordBlock(text, pos) { const el = document.createElement('div'); el.className = 'chord-block'; el.textContent = normalize(text); el.dataset.pos = String(pos); positionChord(el, pos); el.addEventListener('dblclick', ev => { ev.stopPropagation(); beginEditChord(el); }); el.addEventListener('contextmenu', ev => { ev.preventDefault(); ev.stopPropagation(); showContextMenu(ev, { type: 'chord', target: el }); }); let dragging = false; let startX = 0; let startPos = pos; el.addEventListener('mousedown', ev => { dragging = true; startX = ev.clientX; startPos = parseInt(el.dataset.pos || '0', 10); document.addEventListener('mousemove', onMove); document.addEventListener('mouseup', onUp, { once: true }); }); function onMove(ev) { if (!dragging) return; const delta = ev.clientX - startX; const newPos = Math.max(0, Math.round((startPos * CHAR_WIDTH + delta) / CHAR_WIDTH)); positionChord(el, newPos); el.dataset.pos = String(newPos); } function onUp() { dragging = false; document.removeEventListener('mousemove', onMove); } return el; }
            function positionChord(el, pos) { el.style.left = `${pos * CHAR_WIDTH}px`; }
            function onEmptyDblClick(ev) { if (!(ev.currentTarget instanceof HTMLElement)) return; if (ev.target !== ev.currentTarget) return; const rect = ev.currentTarget.getBoundingClientRect(); const offset = ev.clientX - rect.left; const pos = Math.max(0, Math.round(offset / CHAR_WIDTH)); const block = makeChordBlock('C', pos); ev.currentTarget.appendChild(block); beginEditChord(block); }
            function serializeEditor() { const lines = []; editor.querySelectorAll('.editor-line').forEach(lineEl => { const textEl = lineEl.querySelector('.editor-text'); if (textEl) { lines.push(textEl.textContent || ''); return; } const chordsWrap = lineEl.querySelector('.editor-chords'); if (chordsWrap) { const blocks = Array.from(chordsWrap.querySelectorAll('.chord-block')).map(b => ({ pos: parseInt(b.dataset.pos || '0', 10), text: normalize(b.textContent || '') })).filter(b => b.text && isChordToken(b.text)).sort((a, b) => a.pos - b.pos); let line = ''; blocks.forEach(b => { while (line.length < b.pos) line += ' '; line += b.text; }); lines.push(line.replace(/\s+$/, '')); } }); textarea.value = lines.join('\n'); }
            function beginEditChord(blockEl) { const current = blockEl.textContent.trim(); const input = document.createElement('input'); input.type = 'text'; input.className = 'chord-input'; input.value = current; const width = Math.max(60, current.length * CHAR_WIDTH + 12); input.style.minWidth = `${width}px`; blockEl.appendChild(input); input.focus(); input.select(); const commit = () => { const val = input.value.trim(); blockEl.removeChild(input); if (!val || !isChordToken(val)) return; blockEl.textContent = normalize(val); }; const cancel = () => { blockEl.removeChild(input); }; input.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); commit(); } else if (e.key === 'Escape') { e.preventDefault(); cancel(); } }); input.addEventListener('blur', commit); }
            function showContextMenu(ev, state) { contextTarget = state; const actions = buildMenuItems(state); contextMenu.innerHTML = actions.map(a => `<div class="item" data-action="${a.action}">${a.label}</div>`).join(''); contextMenu.style.left = `${ev.clientX}px`; contextMenu.style.top = `${ev.clientY}px`; contextMenu.style.display = 'block'; }
            function hideContextMenu() { contextMenu.style.display = 'none'; contextTarget = null; }
            function buildMenuItems(state) {
                const base = [
                    { action: 'delete-line', label: 'Удалить строку' },
                    { action: 'add-text-below', label: 'Добавить строку снизу — Текст' },
                    { action: 'add-chords-below', label: 'Добавить строку снизу — Аккорды' },
                    { action: 'add-chords-above', label: 'Добавить строку сверху — Аккорды' },
                ];
                if (state.type === 'chord') {
                    return [
                        { action: 'edit-chord', label: 'Изменить аккорд' },
                        { action: 'delete-chord', label: 'Удалить аккорд' },
                        ...base
                    ];
                }
                if (state.type === 'add-chord') {
                    return [
                        { action: 'add-chord', label: 'Добавить аккорд' },
                        ...base
                    ];
                }
                return base;
            }
            function init() { const lines = textarea.value.replace(/\r\n/g, '\n').split('\n'); buildEditor(lines); const form = textarea.closest('form'); if (form) { form.addEventListener('submit', () => serializeEditor()); }
                window.serializeLyrics = serializeEditor;
                window.rebuildEditorFromText = rebuildEditorFromText;
                contextMenu.addEventListener('click', ev => {
                    const item = ev.target.closest('.item');
                    if (!item || !contextTarget) return;
                    const action = item.dataset.action;
                    const state = contextTarget;
                    const lineEl = (state.target instanceof HTMLElement) ? state.target.closest('.editor-line') : null;
                    if (action === 'delete-line' && lineEl) {
                        lineEl.remove();
                    } else if (action === 'add-chords-above' && lineEl) {
                        const newLine = createChordLine(editor.children.length);
                        lineEl.parentNode.insertBefore(newLine, lineEl);
                    } else if (action === 'add-text-below' && lineEl) {
                        const newLine = createTextLine(editor.children.length);
                        lineEl.parentNode.insertBefore(newLine, lineEl.nextSibling);
                    } else if (action === 'add-chords-below' && lineEl) {
                        const newLine = createChordLine(editor.children.length);
                        lineEl.parentNode.insertBefore(newLine, lineEl.nextSibling);
                    } else if (action === 'add-chord' && state.target) {
                        const wrap = state.target; const pos = state.pos ?? 0; const block = makeChordBlock('C', pos); wrap.appendChild(block); beginEditChord(block);
                    } else if (action === 'delete-chord' && state.target) {
                        state.target.remove();
                    } else if (action === 'edit-chord' && state.target) {
                        beginEditChord(state.target);
                    }
                    hideContextMenu();
                });
                document.addEventListener('click', ev => { if (!contextMenu.contains(ev.target)) hideContextMenu(); });
                document.addEventListener('scroll', hideContextMenu, true);
                window.addEventListener('resize', hideContextMenu);
                document.addEventListener('keydown', ev => { if (ev.key === 'Escape') hideContextMenu(); });
            }
            init();
            // Удаление пустой строки по Delete/Backspace, если строка пустая
            editor.addEventListener('keydown', (ev) => {
                if (ev.key !== 'Delete' && ev.key !== 'Backspace') return;
                const lineEl = ev.target.closest?.('.editor-line');
                if (!lineEl) return;
                const hasText = lineEl.querySelector('.editor-text')?.textContent.trim();
                const hasChords = lineEl.querySelector('.chord-block');
                const totalLines = editor.querySelectorAll('.editor-line').length;
                if (!hasText && !hasChords && totalLines > 1) {
                    lineEl.remove();
                    ev.preventDefault();
                }
            });
        })();
        const userPill = document.getElementById('user-pill');
        const userMenu = document.getElementById('user-menu');
        if (userPill && userMenu) {
            userPill.addEventListener('click', (e) => {
                e.stopPropagation();
                userMenu.style.display = userMenu.style.display === 'block' ? 'none' : 'block';
            });
            document.addEventListener('click', (e) => {
                if (!userMenu.contains(e.target) && e.target !== userPill) userMenu.style.display = 'none';
            });
        }
        (function() {
            const analyzeBtn = document.getElementById('analyze-btn');
            const textEditorBtn = document.getElementById('text-editor-btn');
            const form = document.getElementById('song-form');
            if (!form) return;
            const rawContainer = document.getElementById('raw-container');
            const editorContainer = document.getElementById('editor-container');
            const songId = Number(form.dataset.songId || 0);

            // стартовое состояние: для новых песен показываем только простой ввод
            if (songId === 0 && rawContainer && editorContainer) {
                rawContainer.style.display = 'block';
                editorContainer.style.display = 'none';
            } else {
                if (rawContainer) rawContainer.style.display = 'none';
                if (editorContainer) editorContainer.style.display = 'block';
            }

            // Анализ: если сейчас показан простой ввод — переносим его в интерактивный;
            // если уже интерактивный — сериализуем и перерисовываем без сохранения
            if (analyzeBtn) {
                analyzeBtn.addEventListener('click', () => {
                    if (rawContainer && rawContainer.style.display !== 'none') {
                        moveRawToEditor();
                        return;
                    }
                    if (window.serializeLyrics) window.serializeLyrics();
                    const lyrics = document.getElementById('lyrics')?.value || '';
                    if (!lyrics.trim()) { alert('Введите текст песни для анализа'); return; }
                    if (window.rebuildEditorFromText) window.rebuildEditorFromText(lyrics);
                });
            }

            // Перенос из простого ввода в интерактивный при первом анализе
            const rawText = document.getElementById('raw-lyrics');
            const autoResize = () => {
                if (!rawText) return;
                if (window.innerWidth <= 960) return;
                requestAnimationFrame(() => {
                    rawText.style.height = 'auto';
                    const next = Math.max(180, rawText.scrollHeight);
                    rawText.style.height = next + 'px';
                });
            };

            if (rawText && analyzeBtn) {
                rawText.addEventListener('keydown', (e) => {
                    if (e.ctrlKey && e.key === 'Enter') {
                        e.preventDefault();
                        moveRawToEditor();
                    }
                });
                // авто-ресайз по вводу/вставке — только на десктопе,
                // на мобильных используем фиксированную высоту и скролл
                rawText.addEventListener('input', () => {
                    autoResize();
                    // на мобильных следим, чтобы курсор был виден
                    if (window.innerWidth <= 960) {
                        rawText.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                    }
                });
                rawText.addEventListener('focus', () => {
                    // небольшая задержка, чтобы учесть появление экранной клавиатуры
                    setTimeout(() => {
                        rawText.scrollIntoView({ block: 'center', behavior: 'smooth' });
                    }, 300);
                });
                autoResize();
            }

            function moveRawToEditor() {
                const raw = document.getElementById('raw-lyrics')?.value || '';
                if (!raw.trim()) { alert('Введите текст песни для анализа'); return; }
                if (window.rebuildEditorFromText) window.rebuildEditorFromText(raw);
                if (rawContainer) rawContainer.style.display = 'none';
                if (editorContainer) editorContainer.style.display = 'block';
                if (analyzeBtn) analyzeBtn.style.display = 'inline-flex';
            }

            // Переход из интерактивного редактора обратно в текстовый
            if (textEditorBtn) {
                textEditorBtn.addEventListener('click', () => {
                    // Сериализуем текущий интерактивный редактор в скрытое поле lyrics
                    if (window.serializeLyrics) window.serializeLyrics();
                    const lyricsEl = document.getElementById('lyrics');
                    const rawArea = document.getElementById('raw-lyrics');
                    if (!lyricsEl || !rawArea) return;
                    const val = lyricsEl.value || '';
                    rawArea.value = val;
                    // Подгоняем высоту текстового поля под содержимое
                    if (rawText) {
                        autoResize();
                    }
                    if (editorContainer) editorContainer.style.display = 'none';
                    if (rawContainer) rawContainer.style.display = 'block';
                    if (analyzeBtn) analyzeBtn.style.display = 'inline-flex';
                });
            }
        })();
    </script>
    <script src="/js/sidebar-cache.js"></script>
    <script src="/js/theme-switcher.js"></script>
    <script src="/js/app.js"></script>
</body>
</html>
