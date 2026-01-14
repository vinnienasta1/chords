<?php
$username = require __DIR__ . '/includes/auth_required.php';
require_once __DIR__ . '/includes/user_helper.php';
require_once __DIR__ . '/chord_parser.php';

$userData = getCurrentUser($username);
extract($userData); // $isAdmin, $hasAvatar, $avatarUrl, $displayName, $initial, $user
$csrf = csrf_token();
$db = DB::getConnection();
$setlistsAll = $db->query("SELECT id, name FROM setlists ORDER BY created_at DESC")->fetchAll();
$setlistBlocks = [];
$rows = $db->query("SELECT setlist_id, MAX(block_index) as max_block FROM setlist_items GROUP BY setlist_id")->fetchAll();
foreach ($rows as $r) { $setlistBlocks[$r['setlist_id']] = (int)$r['max_block']; }

$search = trim($_GET['search'] ?? '');
$skillFilter = (int)($_GET['skill'] ?? 0);
$popFilter = (int)($_GET['pop'] ?? 0);
$localeFilter = $_GET['locale'] ?? '';
$sort = $_GET['sort'] ?? 'date_desc';
// Фильтр по пользователю, добавившему песню
$addedByFilter = (int)($_GET['added_by'] ?? 0);

// Список пользователей, которые добавляли песни
$addedByUsers = $db->query("
    SELECT DISTINCT u.id, u.username,
           COALESCE(NULLIF(TRIM(u.full_name), ''), u.username) AS display_name
    FROM songs s
    JOIN users u ON u.id = s.added_by
    WHERE s.added_by IS NOT NULL
    ORDER BY display_name
")->fetchAll();

$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(title LIKE ? OR artist LIKE ? OR lyrics LIKE ?)';
    $s = '%' . $search . '%';
    $params[] = $s; $params[] = $s; $params[] = $s;
}
if ($skillFilter > 0) { $where[] = 'skill_stars >= ?'; $params[] = $skillFilter; }
if ($popFilter > 0) { $where[] = 'popularity_stars >= ?'; $params[] = $popFilter; }
if ($localeFilter === 'ru') { $where[] = "locale = 'ru'"; }
if ($localeFilter === 'foreign') { $where[] = "locale = 'foreign'"; }
if ($addedByFilter > 0) { $where[] = 'added_by = ?'; $params[] = $addedByFilter; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
$orderSql = 'ORDER BY created_at DESC';
if ($sort === 'date_asc') $orderSql = 'ORDER BY created_at ASC';
else if ($sort === 'skill') $orderSql = 'ORDER BY skill_stars DESC, popularity_stars DESC, created_at DESC';
else if ($sort === 'pop') $orderSql = 'ORDER BY popularity_stars DESC, skill_stars DESC, created_at DESC';
else if ($sort === 'title') $orderSql = 'ORDER BY title COLLATE NOCASE ASC';
$stmt = $db->prepare("SELECT * FROM songs $whereSql $orderSql");
$stmt->execute($params);
$songs = $stmt->fetchAll();

$selectedSongId = (int)($_GET['song_id'] ?? 0);
$fromSetlistId = isset($_GET['setlist_id']) ? (int)$_GET['setlist_id'] : 0;
$fromSetlistPos = isset($_GET['setlist_pos']) ? (int)$_GET['setlist_pos'] : -1;
$setlistItems = [];
$selectedSong = null;
$chords = [];

if ($selectedSongId > 0) {
    $stmt = $db->prepare('SELECT * FROM songs WHERE id = ?');
    $stmt->execute([$selectedSongId]);
    $selectedSong = $stmt->fetch();
    if ($selectedSong) {
        $stmt = $db->prepare('SELECT * FROM chords WHERE song_id = ? ORDER BY char_position');
        $stmt->execute([$selectedSongId]);
        $chords = $stmt->fetchAll();
                        if ($fromSetlistId > 0) {
            $stmt = $db->prepare('SELECT song_id FROM setlist_items WHERE setlist_id = ? AND song_id IS NOT NULL ORDER BY block_index, position');
            $stmt->execute([$fromSetlistId]);
            $setlistItems = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
    }
}

function renderSongWithChords($lyrics, $chords) {
    if (empty($chords)) {
        $lines = explode("\n", $lyrics);
        $out = '<div class="song-content">';
        foreach ($lines as $line) {
            $out .= '<div class="text-line">' . htmlspecialchars($line) . '</div>';
        }
        $out .= '</div>';
        return $out;
    }
    usort($chords, fn($a, $b) => $a['char_position'] <=> $b['char_position']);
    $lines = explode("\n", $lyrics);
    $output = '<div class="song-content">';
    $lineStart = 0; $chordIndex = 0; $totalChords = count($chords);
    foreach ($lines as $line) {
        $lineLength = strlen($line); $lineEnd = $lineStart + $lineLength;
        while ($chordIndex < $totalChords && (int)$chords[$chordIndex]['char_position'] < $lineStart) { $chordIndex++; }
        $lineChords = []; $tempIndex = $chordIndex;
        while ($tempIndex < $totalChords) {
            $pos = (int)$chords[$tempIndex]['char_position'];
            if ($pos >= $lineEnd) break;
            $lineChords[] = [ 'pos' => $pos - $lineStart, 'text' => $chords[$tempIndex]['chord_text'] ];
            $tempIndex++;
        }
        $chordIndex = $tempIndex;
        if (!empty($lineChords)) {
            // Компенсируем скобки, добавленные при сохранении аккордов "(C)"
            foreach ($lineChords as &$chordData) {
                $prefix = substr($line, 0, $chordData['pos']);
                $removed = substr_count($prefix, '(') + substr_count($prefix, ')');
                $chordData['pos'] = max(0, $chordData['pos'] - $removed);
                $chordData['text'] = ChordParser::normalizeChord($chordData['text']);
            }
            unset($chordData);
            usort($lineChords, fn($a, $b) => $a['pos'] <=> $b['pos']);
            $textLineRaw = preg_replace('/\([^()]*\)/', '', $line);

            // Если строка состоит только из аккордов (пустой текст), строим ширину по позиции аккордов
            if (trim($textLineRaw) === '') {
                $maxPos = 0;
                foreach ($lineChords as $chordData) {
                    $maxPos = max($maxPos, (int)$chordData['pos']);
                }
                $charCount = max(1, $maxPos + 1);
                $slots = array_fill(0, $charCount, '');
                foreach ($lineChords as $chordData) {
                    $posChars = min($charCount - 1, (int)$chordData['pos']);
                    if ($slots[$posChars] === '') {
                        $slots[$posChars] = htmlspecialchars($chordData['text']);
                    } else {
                        // Если позиция занята, ищем ближайший пустой справа
                        $p = $posChars + 1;
                        while ($p < $charCount && $slots[$p] !== '') { $p++; }
                        if ($p < $charCount) {
                            $slots[$p] = htmlspecialchars($chordData['text']);
                        }
                    }
                }
            } else {
                $chars = preg_split('//u', $textLineRaw, -1, PREG_SPLIT_NO_EMPTY);
                $charCount = count($chars);
                if ($charCount === 0) { $chars = [' ']; $charCount = 1; }
                $slots = array_fill(0, $charCount, '');
                foreach ($lineChords as $chordData) {
                    $substr = substr($textLineRaw, 0, $chordData['pos']);
                    $posChars = function_exists('mb_strlen') ? mb_strlen($substr, 'UTF-8') : strlen($substr);
                    if ($posChars >= $charCount) { $posChars = $charCount - 1; }
                    if ($slots[$posChars] === '') {
                        $slots[$posChars] = htmlspecialchars($chordData['text']);
                    }
                }
            }

            $chordLine = '<div class="chord-line">';
            foreach ($slots as $slot) {
                if ($slot === '') {
                    $chordLine .= '&nbsp;';
                } else {
                    $chordLine .= '<span class="chord" data-chord="' . $slot . '">' . $slot . '</span>';
                }
            }
            $chordLine .= '</div>';
            $output .= $chordLine;
        } else {
            $output .= '<div class="chord-line empty"></div>';
        }
        $textLine = preg_replace('/\([^()]*\)/', '', $line);
        // Если строка содержит только аккорды (без текста) – не дублируем её как текстовую
        $lineType = ChordParser::getLineType($line);
        if ($lineType !== 'chords' && (trim($textLine) !== '' || empty($lineChords))) {
            $output .= '<div class="text-line">' . htmlspecialchars($textLine) . '</div>';
        }
        $lineStart += $lineLength + 1;
    }
    $output .= '</div>'; return $output;
}
function detectLocale($text) {
    $cyr = 0;
    if (preg_match_all('/[А-Яа-яЁё]+/u', $text, $m)) { $cyr = count($m[0]); }
    return $cyr > 10 ? 'ru' : 'foreign';
}
require_once __DIR__ . '/includes/layout_helper.php';
renderHead('Все песни');
?>
<body>
    <style>
        :root { --lyrics-font:'Arial', sans-serif; --chord-font:'Arial', sans-serif; --lyrics-size:20px; --chord-size:18px; --lyrics-line:1.8; }
        * { box-sizing: border-box; }
        body { margin:0; font-family:'Inter',Arial,sans-serif; background:var(--bg-gradient); color:var(--text); height:100vh; overflow:hidden; }
        .layout { display:block; min-height:100vh; height:100vh; overflow-y:auto; }
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
        p { margin:0.4rem 0 0; color:var(--muted); line-height:1.6; }
        .search-box { margin-bottom:0.75rem; }
        .search-box-inner {
            display:flex;
            gap:0.5rem;
            align-items:center;
        }
        .search-box input {
            flex:1;
            padding:0.65rem;
            border-radius:10px;
            border:1px solid var(--input-border);
            background:var(--input-bg);
            color:var(--text);
        }
        .filters-toggle {
            display:inline-flex;
            align-items:center;
            justify-content:center;
            width:52px;
            height:52px;
            border-radius:999px;
            border:1px solid var(--border);
            background:var(--card-bg);
            color:var(--text);
            cursor:pointer;
            flex-shrink:0;
            transition: all 0.2s ease;
        }
        .filters-toggle:hover {
            background:color-mix(in srgb, var(--accent) 10%, transparent);
            border-color:var(--accent);
        }
        .filters-toggle svg {
            width:30px;
            height:30px;
        }
        /* Единый стиль селектов на странице */
        select, .sel {
            color: var(--text);
            background: var(--input-bg);
            border: 1px solid var(--input-border);
        }
        select option,
        .sel option {
            color: var(--text);
            background: var(--panel);
        }
        .filters-panel {
            margin-top:0.25rem;
        }
        .filters-panel[hidden] {
            display:none;
        }
        .songs-list { display:flex; flex-direction:column; gap:0.6rem; }
        .song-card { padding:0.9rem; background:var(--card-bg); border:1px solid var(--border); border-radius:12px; cursor:pointer; transition:0.2s; }
        .song-card:hover { background:color-mix(in srgb, var(--accent) 10%, transparent); transform:translateY(-1px); }
        .song-card h3 { margin:0 0 0.3rem; color:var(--text); }
        .song-card .meta { color:var(--muted); font-size:0.95rem; }
        .song-detail { width:100%; }
        .song-header {
            display:flex;
            flex-direction:column;
            gap:0.75rem;
            margin-bottom:1rem;
        }
        .song-header-top {
            display:flex;
            align-items:flex-start;
            justify-content:space-between;
            gap:1.5rem;
            flex-wrap:wrap;
        }
        .song-title-block {
            min-width:220px;
            flex:1;
        }
        .song-detail h2 {
            margin:0;
            color:var(--text);
            font-size:1.8rem;
        }
        .song-artist {
            margin-top:0.25rem;
            color:var(--muted);
            font-size:1rem;
        }
        .controls-bar {
            display:flex;
            flex-direction:column;
            gap:0.4rem;
            flex-shrink:0;
            min-width:190px;
        }
        .song-header-meta {
            display:flex;
            flex-direction:column;
            gap:0.25rem;
        }
        .song-meta-row {
            display:flex;
            gap:10px;
            flex-wrap:wrap;
            align-items:center;
            color:var(--muted);
            font-size:0.9rem;
        }
        .song-meta-row .meta {
            color:var(--muted);
        }
        .song-comment {
            color:var(--muted);
            font-size:0.9rem;
            white-space:pre-wrap;
        }
        .song-body { transform-origin: top center; width:100%; }
        .song-content { font-family: var(--lyrics-font); font-size: var(--lyrics-size); line-height: var(--lyrics-line); color:var(--text); white-space:pre; }
        .chord-line { min-height:1.1em; color:var(--accent); font-weight:bold; margin-bottom:0.04em; font-family: var(--lyrics-font); font-size: var(--lyrics-size); white-space:pre; }
        .chord-line.empty { min-height:0.35em; }
        .chord { display:inline-block; margin-right:0.2em; font-family: var(--chord-font); font-size: var(--chord-size); line-height:1.1; transform-origin:left bottom; }
        .chord.style-frame { padding:0.15em 0.35em; border:1px solid var(--border); border-radius:0.35em; background:var(--card-bg); }
        .chord.style-bold { font-weight:800; }
        /* Графитовая тема: делаем аккорды светлее для читаемости */
        [data-theme="dark2"] .song-detail .chord-line { color:rgb(159, 166, 180); }
        [data-theme="dark2"] .song-detail .chord.style-frame { 
            border-color: rgba(255,255,255,0.24); 
            background: rgba(255,255,255,0.06); 
        }
        .text-line { margin-bottom:0.25em; white-space:pre; font-family: var(--lyrics-font); }
        .no-songs { color:var(--muted); }
        .control-block {
            background:transparent;
            border:none;
            border-radius:6px;
            padding:0.4rem 0.55rem;
            display:flex;
            align-items:center;
            gap:0.35rem;
            width:190px;
        }
        .control-block label {
            color:var(--muted);
            font-weight:600;
            font-size:0.75rem; /* 12px */
            white-space:nowrap;
        }
        .tp-btn {
            width:24px;
            height:24px;
            border:none;
            border-radius:4px;
            background:var(--accent);
            color:#fff;
            font-weight:700;
            cursor:pointer;
            flex-shrink:0;
            font-size:0.75rem; /* 12px */
            display:flex;
            align-items:center;
            justify-content:center;
            padding:0;
            line-height:1;
            transition:background 0.2s;
        }
        .tp-btn:hover {
            background:rgba(102,126,234,0.8);
        }
        .tp-value {
            min-width:32px;
            text-align:center;
            font-weight:700;
            font-size:0.75rem; /* 12px */
        }
        .scroll-range {
            flex:1;
            min-width:70px;
            max-width:120px;
        }
        .play-btn {
            padding:0.25rem 0.5rem;
            min-width:26px;
            border:none;
            border-radius:4px;
            cursor:pointer;
            color:#fff;
            background:#4b5563;
            flex-shrink:0;
            font-size:0.75rem; /* 12px */
        }
        .play-btn.active { background:#dc3545; }
        .context-setlist { position:fixed; background:var(--panel); color:var(--text); border:1px solid var(--border); border-radius:8px; box-shadow:var(--shadow); padding:0.4rem 0; display:none; z-index:1000; min-width:200px; }
        .context-setlist .item { padding:0.45rem 0.75rem; cursor:pointer; }
        .context-setlist .item:hover { background:color-mix(in srgb, var(--accent) 20%, transparent); color:#fff; }
        .toast-container { position:fixed; right:16px; bottom:16px; display:grid; gap:8px; z-index:2000; }
        .toast { min-width:200px; max-width:320px; background:var(--panel); color:var(--text); border:1px solid var(--border); border-radius:10px; padding:10px 12px; box-shadow:var(--shadow); font-size:0.95rem; }
        .toast.success { border-color: rgba(46,204,113,0.5); }
        .toast.error { border-color: rgba(231,76,60,0.5); }
        .fret-pre {
            font-family: 'Consolas', 'Courier New', monospace;
            background: rgba(255,255,255,0.04);
            padding: 6px 8px;
            border-radius: 8px;
            border: 1px solid rgba(255,255,255,0.08);
            display: inline-block;
            margin-top: 6px;
            white-space: pre;
        }
        .view-menu { position:relative; z-index: 3100; }
        .view-toggle { padding:0.35rem 0.6rem; border-radius:5px; border:1px solid var(--border); background:var(--card-bg); color:var(--text); cursor:pointer; font-weight:700; letter-spacing:0.2px; font-size:0.8rem; }
        .view-panel { position:absolute; right:0; top:110%; background:var(--panel); border:1px solid var(--border); border-radius:12px; padding:0.9rem; min-width:280px; max-width:320px; box-shadow:var(--shadow); display:none; z-index:3100; }
        .view-panel.open { display:block; }
        .view-row { display:flex; align-items:center; gap:0.6rem; margin-bottom:0.6rem; }
        .view-row label { min-width:90px; color:var(--muted); font-size:0.95rem; }
        .view-row select, .view-row input[type="range"] { flex:1; }
        .view-row .btn { margin-left:0; margin-right:auto; }
        .view-row select { padding:0.45rem; border-radius:10px; border:1px solid var(--input-border); background:var(--input-bg); color:var(--text); }
        .view-val { width:38px; text-align:right; color:var(--muted); font-size:0.95rem; }
        .view-right { margin-left:auto; display:flex; align-items:center; }
        .view-right input[type="checkbox"] { width:18px; height:18px; }
        .view-row-buttons { gap:0; margin-bottom:0.6rem; }
        .view-toggle-buttons { display:flex; gap:0.5rem; justify-content:flex-end; margin-left:auto; }
        .toggle-btn { padding:0.5rem 0.75rem; border-radius:8px; border:1px solid var(--border); background:var(--card-bg); color:var(--muted); cursor:pointer; font-weight:600; font-size:0.9rem; transition:all 0.2s ease; white-space:nowrap; display:flex; align-items:center; justify-content:center; min-width:36px; height:36px; }
        .toggle-btn svg { width:16px; height:16px; flex-shrink:0; }
        .toggle-btn:hover { background:color-mix(in srgb, var(--accent) 10%, transparent); border-color:var(--accent); color:var(--text); }
        .toggle-btn.active { background:var(--accent); border-color:var(--accent); color:#fff; box-shadow:0 4px 12px color-mix(in srgb, var(--accent) 30%, transparent); }
        .toggle-btn.active:hover { background:color-mix(in srgb, var(--accent) 90%, black); box-shadow:0 6px 16px color-mix(in srgb, var(--accent) 40%, transparent); }
        .toggle-btn-reset { background:var(--card-bg); border-color:var(--border); }
        .toggle-btn-reset:hover { background:color-mix(in srgb, var(--accent) 10%, transparent); border-color:var(--accent); }
        .toggle-btn-text { display:inline-block; }
        @media (max-width:768px) {
            .toggle-btn-auto { display:none !important; }
        }
        .two-col { display:grid; grid-template-columns:1fr 1fr; gap:1rem 2rem; }
        .stanza { margin-bottom:0.6rem; }
        .sel { padding:0.45rem; border-radius:10px; border:1px solid var(--input-border); background:var(--input-bg); color:var(--text); }
        .cols-controls { display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap; }
        .cols-option { display:flex; align-items:center; gap:0.25rem; font-size:0.9rem; color:var(--muted); }
        .btn { padding:0.6rem 1rem; border-radius:10px; border:1px solid rgba(255,255,255,0.15); background:linear-gradient(135deg, rgba(102,126,234,0.8), rgba(79,70,229,0.8)); color:#fff; font-weight:700; cursor:pointer; box-shadow:0 6px 16px rgba(0,0,0,0.25); transition:transform 0.12s ease, box-shadow 0.12s ease; }
        .btn:hover { transform:translateY(-1px); box-shadow:0 10px 20px rgba(0,0,0,0.3); }
        .btn:active { transform:translateY(0); box-shadow:0 6px 12px rgba(0,0,0,0.25); }
        @media (max-width:960px) {
            body { height:auto; min-height:100vh; overflow:visible; }
            .layout { display:block; height:auto; min-height:100vh; overflow:visible; }
            .sidebar {
                position:fixed;
                inset:0 auto 0 0;
                width:240px;
                transform:translateX(-260px);
                transition:transform 0.2s ease;
                z-index:3000; /* выше любых оверлеев на этой странице */
            }
            .view-menu {
                z-index: 1201;
            }
            .view-panel {
                z-index: 1200;
                min-width:240px !important;
                max-width:280px !important;
                right:0 !important;
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
                border:1px solid rgba(255,255,255,0.2);
                background:rgba(0,0,0,0.4);
                color:#fff;
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
            /* Заголовок и исполнитель песни по центру на мобиле */
            .song-detail h2 {
                text-align: center;
            }
            .song-artist {
                text-align: center;
            }
            h1 {
                font-size: 1.75rem;
                text-align: center;
            }
            .song-header {
                flex-direction: column;
                gap: 1rem;
            }
            .song-title-section {
                width: 100%;
            }
            .song-meta-section {
                width: 100%;
            }
            .controls-bar {
                flex-direction: column;
                gap: 0.4rem;
                width: 100%;
            }
            .control-block {
                width: 100%;
                min-width: 0;
                flex:0 1 auto;
                flex-wrap: nowrap;
                justify-content: flex-start;
                padding:0.4rem 0.55rem;
                border-radius:6px;
                gap:0.35rem;
                border:none;
            }
            .control-block label {
                flex-shrink: 0;
                margin-bottom: 0;
                margin-right: 0.3rem;
                font-size:0.75rem;
            }
            .tp-btn {
                width:24px;
                height:24px;
                border-radius:4px;
                font-size:0.75rem;
                flex-shrink: 0;
            }
            .tp-value {
                min-width:32px;
                max-width:32px;
                font-size:0.75rem;
                flex-shrink: 0;
            }
            .scroll-range {
                flex: 1;
                min-width: 60px;
                max-width: 120px;
            }
            .play-btn {
                padding:0.25rem 0.5rem;
                min-width:26px;
                max-width: none;
                font-size:0.75rem;
                flex-shrink: 0;
            }
            .search-box {
                margin-bottom: 0.75rem;
            }
            .songs-list {
                gap: 0.5rem;
            }
            .song-card {
                padding: 0.75rem;
            }
            .song-detail {
                width: 100%;
            }
            .song-content {
                overflow-x: hidden;
            }
            .chord-line {
                overflow-x: hidden;
            }
            .text-line {
                word-wrap: break-word;
                overflow-wrap: break-word;
            }
            .two-col {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            .view-panel {
                right: 0 !important;
                left: auto !important;
                min-width: 240px !important;
                max-width: 280px !important;
            }
            .cols-auto-only {
                display:none;
            }
            .view-row-cols {
                display: none !important;
            }
            .song-header {
                gap:0.8rem;
            }
            .song-detail h2 {
                font-size:1.5rem;
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
                padding: 0.95rem;
            }
            h1 {
                font-size: 1.5rem;
                text-align: center;
            }
            h2 {
                font-size: 1.3rem;
                text-align: center;
            }
            .song-header {
                gap:0.8rem;
            }
            .song-detail h2 {
                font-size:1.5rem;
                text-align: center;
            }
            .song-artist {
                text-align: center;
            }
            .controls-bar {
                gap:0.35rem;
                width: 100%;
            }
            .control-block {
                padding:0.4rem 0.55rem;
                border:none;
                width: 100%;
                flex-wrap: nowrap;
                justify-content: flex-start;
            }
            .control-block label {
                font-size:0.75rem;
                flex-shrink: 0;
                margin-right: 0.3rem;
            }
            .tp-btn, .tp-value, .play-btn {
                font-size:0.75rem;
                flex-shrink: 0;
            }
            .tp-value {
                min-width:32px;
                max-width:32px;
            }
            .scroll-range {
                flex: 1;
                min-width: 60px;
                max-width: 120px;
            }
            .toggle {
                top: 8px;
                left: 8px;
                padding: 0.5rem 0.75rem;
            }
            .control-block {
                padding: 0.5rem 0.75rem;
            }
            .song-card h3 {
                font-size: 1.1rem;
            }
            .song-card .meta {
                font-size: 0.85rem;
            }
            .card > div[style*="display:flex"] {
                flex-direction: column !important;
            }
            .card > div[style*="display:flex"] > div {
                width: 100% !important;
                min-width: unset !important;
            }
            .sel {
                width: 100%;
            }
        }
    </style>
    <div class="layout">
        <!-- Sidebar будет загружен через JavaScript -->
        <main class="content">
            <?php if ($selectedSong): ?>
                <div class="card song-detail scaled" id="song-card">
                    <div class="song-header">
                        <div class="song-title-section">
                            <h2><?php echo htmlspecialchars($selectedSong['title']); ?></h2>
                            <?php if ($selectedSong['artist']): ?>
                                <div class="song-artist"><?php echo htmlspecialchars($selectedSong['artist']); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="song-meta-section">
                            <div class="song-meta-row">
                                <?php if (!empty($selectedSong['cap'])): ?><span style="color:var(--text); font-weight:600;">Cap: <?php echo htmlspecialchars($selectedSong['cap']); ?></span><?php endif; ?>
                                <?php if (!empty($selectedSong['first_note'])): ?><span style="color:var(--text); font-weight:600;">Первая нота: <?php echo htmlspecialchars($selectedSong['first_note']); ?></span><?php endif; ?>
                                <?php if (!empty($selectedSong['skill_stars'])): ?><span class="meta">Навык: <?php echo str_repeat('★', (int)$selectedSong['skill_stars']); ?></span><?php endif; ?>
                                <?php if (!empty($selectedSong['popularity_stars'])): ?><span class="meta">Поп: <?php echo str_repeat('★', (int)$selectedSong['popularity_stars']); ?></span><?php endif; ?>
                                <?php
                                    $loc = $selectedSong['locale'] ?: detectLocale($selectedSong['lyrics']);
                                ?>
                                <?php if (!empty($loc)): ?><span class="meta"><?php echo $loc==='ru'?'Русское':'Иностранное'; ?></span><?php endif; ?>
                            </div>
                            <?php if (!empty($selectedSong['comment'])): ?>
                                <div class="song-comment"><?php echo htmlspecialchars($selectedSong['comment']); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="controls-bar">
                            <div class="control-block">
                                <label>Транспонирование:</label>
                                <button class="tp-btn" id="tp-minus" title="Понизить на полтона">♭</button>
                                <span class="tp-value" id="tp-value">0</span>
                                <button class="tp-btn" id="tp-plus" title="Повысить на полтона">♯</button>
                                <button class="tp-btn" id="tp-reset" title="Сбросить">↺</button>
                            </div>
                            <div class="control-block">
                                <label>Автопрокрутка:</label>
                                <button class="play-btn" id="scroll-toggle">▶</button>
                                <input type="range" min="0" max="100" value="40" id="scroll-speed" class="scroll-range" step="1">
                            </div>
                            <div class="control-block view-menu">
                                <button class="view-toggle" id="view-toggle">Вид ▾</button>
                                <div class="view-panel" id="view-panel">
                                <div class="view-row">
                                    <label>Шрифт</label>
                                    <select id="font-select" class="sel">
                                        <option value="'Arial',sans-serif">Arial</option>
                                        <option value="'Segoe UI','Inter',Arial,sans-serif">Segoe UI / Inter</option>
                                        <option value="'Calibri','Segoe UI',Arial,sans-serif">Calibri</option>
                                        <option value="'Georgia',serif">Georgia</option>
                                        <option value="'Consolas','Courier New',monospace">Consolas</option>
                                    </select>
                                </div>
                                <div class="view-row">
                                    <label>Текст</label>
                                    <input type="range" id="lyrics-size" min="8" max="26" step="1">
                                    <span class="view-val" id="lyrics-val"></span>
                                </div>
                                <div class="view-row">
                                    <label>Аккорды</label>
                                    <input type="range" id="chord-size" min="8" max="28" step="1">
                                    <span class="view-val" id="chord-val"></span>
                                </div>
                                <div class="view-row">
                                    <label>Интервал</label>
                                    <input type="range" id="line-height" min="0.3" max="1.4" step="0.05">
                                    <span class="view-val" id="line-val"></span>
                                </div>
                                <div class="view-row">
                                    <label>Стиль</label>
                                    <select id="chord-style" class="sel">
                                        <option value="plain">Обычный</option>
                                        <option value="frame">В рамке</option>
                                        <option value="boldframe">Жирно в рамке</option>
                                    </select>
                                </div>
                                <div class="view-row view-row-buttons">
                                    <div class="view-toggle-buttons">
                                        <button type="button" class="toggle-btn toggle-btn-auto" id="auto-cols-btn" data-target="auto-cols" title="Автомасштаб">
                                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
                                                <rect x="2" y="3" width="5" height="10" rx="1" stroke="currentColor" stroke-width="1.5" fill="none"/>
                                                <rect x="9" y="3" width="5" height="10" rx="1" stroke="currentColor" stroke-width="1.5" fill="none"/>
                                            </svg>
                                        </button>
                                        <button type="button" class="toggle-btn" id="hide-chords-btn" data-target="hide-chords" title="Скрыть аккорды">
                                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
                                                <path d="M8 3C5.5 3 3.5 5 2.5 6.5C2.5 7 2.5 9 8 13C13.5 9 13.5 7 13.5 6.5C12.5 5 10.5 3 8 3Z" stroke="currentColor" stroke-width="1.5" fill="none"/>
                                                <circle cx="8" cy="8" r="2" stroke="currentColor" stroke-width="1.5" fill="none"/>
                                                <path d="M2 2L14 14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                            </svg>
                                        </button>
                                        <button type="button" class="toggle-btn toggle-btn-reset" id="view-reset" title="Сброс">
                                            <?php echo renderIcon('refresh', 16, 16); ?>
                                        </button>
                                    </div>
                                    <input type="checkbox" id="auto-cols" style="display:none;">
                                    <input type="checkbox" id="hide-chords" style="display:none;">
                                </div>
                            </div>
                        </div>
                    </div>
                    </div>
                    <div class="song-body" id="song-body">
                        <?php echo renderSongWithChords($selectedSong['lyrics'], $chords); ?>
                    </div>
                    <?php if (!empty($selectedSong['comment'])): ?>
                        <div class="card" style="margin-top:1rem;">
                            <h3 style="margin:0 0 0.3rem;">Комментарий</h3>
                            <div class="meta" style="white-space:pre-wrap;"><?php echo htmlspecialchars($selectedSong['comment']); ?></div>
                        </div>
                    <?php endif; ?>
                    <p style="margin-top:1rem; display:flex; gap:0.5rem; flex-wrap:wrap;">
                        <?php if ($fromSetlistId > 0): ?>
                            <a href="/setlist_view.php?id=<?php echo $fromSetlistId; ?>&mode=view" class="btn secondary" style="text-decoration:none;">Вернуться к списку</a>
                        <?php else: ?>
                            <a href="/songs.php" class="btn secondary" style="text-decoration:none;">Вернуться к списку</a>
                        <?php endif; ?>
                        <?php
                        $nextUrl = null;
                        if ($fromSetlistId > 0) {
                            if (!empty($setlistItems)) {
                                $nextIndex = -1;
                                if ($fromSetlistPos >= 0 && $fromSetlistPos + 1 < count($setlistItems)) {
                                    $nextIndex = $fromSetlistPos + 1;
                                } else {
                                    $idx = array_search($selectedSongId, $setlistItems, true);
                                    if ($idx !== false && $idx + 1 < count($setlistItems)) {
                                        $nextIndex = $idx + 1;
                                    }
                                }
                                if ($nextIndex !== -1 && $nextIndex < count($setlistItems)) {
                                    $nextId = (int)$setlistItems[$nextIndex];
                                    if ($nextId > 0) {
                                        $nextUrl = '/songs.php?song_id=' . $nextId . '&setlist_id=' . $fromSetlistId . '&setlist_pos=' . $nextIndex;
                                    }
                                }
                            }
                        } else {
                            // Следующая песня из общего списка по сортировке
                            $allIds = array_column($songs, 'id');
                            $idx = array_search($selectedSongId, $allIds, true);
                            if ($idx !== false && $idx + 1 < count($allIds)) {
                                $nextId = (int)$allIds[$idx + 1];
                                if ($nextId > 0) {
                                    $query = $_GET;
                                    $query['song_id'] = $nextId;
                                    $nextUrl = '/songs.php?' . http_build_query($query);
                                }
                            }
                        }
                        if ($nextUrl):
                        ?>
                            <a href="<?php echo htmlspecialchars($nextUrl); ?>" class="btn" style="text-decoration:none;">Следующая песня</a>
                        <?php endif; ?>
                    </p>
                </div>
            <?php else: ?>
                <div class="card">
                    <h1>Все песни</h1>
                    <div class="search-box">
                        <form method='GET' id='search-form'>
                            <div class="search-box-inner">
                                <input type='text' name='search' id='search-input' placeholder='Поиск по названию, исполнителю или тексту...' value='<?php echo htmlspecialchars($search); ?>'>
                                <button type="button" class="filters-toggle" id="filters-toggle">
                                    <?php echo renderIcon('filter2', 26, 26); ?>
                                </button>
                            </div>
                        </form>
                    </div>
                    <div class="card filters-panel" id="filters-panel" style="margin-bottom:1rem;" <?php echo ($skillFilter||$popFilter||$localeFilter||$addedByFilter||$sort!=='date_desc') ? '' : 'hidden'; ?>>
                        <div style="display:flex; gap:0.75rem; flex-wrap:wrap; align-items:flex-end;">
                            <div style="flex:1; min-width:140px;">
                                <label class="meta">Навык ≥</label>
                                <select name="skill" form="search-form" class="sel" style="font-size:16px;">
                                    <option value="0">Все</option>
                                    <?php for ($i=1;$i<=5;$i++): ?>
                                        <option value="<?php echo $i; ?>" <?php echo ($skillFilter==$i)?'selected':''; ?>><?php echo $i; ?>★</option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div style="flex:1; min-width:140px;">
                                <label class="meta">Популярность ≥</label>
                                <select name="pop" form="search-form" class="sel" style="font-size:16px;">
                                    <option value="0">Все</option>
                                    <?php for ($i=1;$i<=5;$i++): ?>
                                        <option value="<?php echo $i; ?>" <?php echo ($popFilter==$i)?'selected':''; ?>><?php echo $i; ?>★</option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div style="flex:1; min-width:140px;">
                                <label class="meta">Язык</label>
                                <select name="locale" form="search-form" class="sel" style="font-size:16px;">
                                    <option value="">Все</option>
                                    <option value="ru" <?php echo $localeFilter==='ru'?'selected':''; ?>>Русское</option>
                                    <option value="foreign" <?php echo $localeFilter==='foreign'?'selected':''; ?>>Иностранное</option>
                                </select>
                            </div>
                            <div style="flex:1; min-width:160px;">
                                <label class="meta">Пользователь</label>
                                <select name="added_by" form="search-form" class="sel" style="font-size:16px;">
                                    <option value="0">Все</option>
                                    <?php foreach ($addedByUsers as $u): ?>
                                        <option value="<?php echo (int)$u['id']; ?>" <?php echo $addedByFilter==(int)$u['id']?'selected':''; ?>>
                                            <?php echo htmlspecialchars($u['display_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div style="flex:1; min-width:160px;">
                                <label class="meta">Сортировка</label>
                                <select name="sort" form="search-form" class="sel" style="font-size:16px;">
                                    <option value="date_desc" <?php echo $sort==='date_desc'?'selected':''; ?>>Новые сверху</option>
                                    <option value="date_asc" <?php echo $sort==='date_asc'?'selected':''; ?>>Старые сверху</option>
                                    <option value="skill" <?php echo $sort==='skill'?'selected':''; ?>>Навык</option>
                                    <option value="pop" <?php echo $sort==='pop'?'selected':''; ?>>Популярность</option>
                                    <option value="title" <?php echo $sort==='title'?'selected':''; ?>>Название</option>
                                </select>
                            </div>
                            <div style="display:flex; gap:0.5rem; width:100%; flex-wrap:wrap;">
                                <button form="search-form" class="btn" style="flex:1; min-width:140px;">Применить</button>
                                <button type="button" class="btn secondary" id="filters-reset" style="flex:1; min-width:140px;">Сбросить</button>
                            </div>
                        </div>
                    </div>
                    <?php if (empty($songs)): ?>
                        <div class='no-songs'>Песен не найдено.</div>
                    <?php else: ?>
                        <div class='songs-list'>
                            <?php foreach ($songs as $song): ?>
                                <?php
                                    $lyricsSnippet = $song['lyrics'] ?? '';
                                    if (function_exists('mb_substr')) {
                                        $lyricsSnippet = mb_substr($lyricsSnippet, 0, 1000, 'UTF-8');
                                    } else {
                                        $lyricsSnippet = substr($lyricsSnippet, 0, 1000);
                                    }
                                ?>
                                <div
                                    class='song-card'
                                    data-id="<?php echo $song['id']; ?>"
                                    data-lyrics="<?php echo htmlspecialchars($lyricsSnippet, ENT_QUOTES); ?>"
                                    onclick='window.location.href="/songs.php?song_id=<?php echo $song['id']; ?>"'
                                >
                                    <h3><?php echo htmlspecialchars($song['title']); ?></h3>
                                    <div class='meta' style="display:flex; gap:10px; flex-wrap:wrap;">
                                        <?php if ($song['artist']): ?><span><?php echo htmlspecialchars($song['artist']); ?></span><?php endif; ?>
                                        <?php if (!empty($song['cap'])): ?> <span>Cap: <?php echo htmlspecialchars($song['cap']); ?></span><?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>
    <script src="/js/sidebar-cache.js"></script>
    <?php renderLayoutScripts(); ?>
    <script src="/js/filters.js"></script>
    <?php if ($selectedSong): ?>
    <script src="/js/chord-player.js"></script>
    <script>
        // Транспонирование и автоскролл — только на странице песни
        const chordSpans = document.querySelectorAll('.song-detail .chord');
        function applyChordStyle(style) {
            const spans = chordSpans.length ? chordSpans : document.querySelectorAll('.song-detail .chord');
            spans.forEach(span => {
                span.classList.remove('style-frame', 'style-bold');
                if (style === 'frame') {
                    span.classList.add('style-frame');
                } else if (style === 'boldframe') {
                    span.classList.add('style-frame', 'style-bold');
                }
            });
        }
        // Инициализация транспонирования - кнопки должны работать всегда
        const tpMinus = document.getElementById('tp-minus');
        const tpPlus = document.getElementById('tp-plus');
        const tpReset = document.getElementById('tp-reset');
        const tpValue = document.getElementById('tp-value');
        
        // Проверяем наличие элементов кнопок транспонирования
        if (tpMinus && tpPlus && tpValue) {
            let shift = 0; // всегда по умолчанию 0
            const MAX_SHIFT = 11;
            const mapSharp = ['C','C#','D','D#','E','F','F#','G','G#','A','A#','B'];
            const flatToSharp = { 'Db':'C#','Eb':'D#','Gb':'F#','Ab':'G#','Bb':'A#' };
            
            function normalizeRoot(root) {
                if (flatToSharp[root]) return flatToSharp[root];
                return root.replace('H','B'); // если вдруг H
            }
            
            function transposeChord(chord, semis) {
                const m = chord.match(/^([A-GH])([#b]?)(.*)$/i);
                if (!m) return chord;
                let root = m[1].toUpperCase();
                let acc = m[2];
                let rest = m[3] || '';
                let bass = '';
                const bassMatch = rest.match(/^(.*)\/([A-GH][#b]?)$/);
                if (bassMatch) {
                    rest = bassMatch[1];
                    bass = bassMatch[2].toUpperCase();
                }
                root = normalizeRoot(root + (acc || ''));
                let idx = mapSharp.indexOf(root);
                if (idx === -1) idx = mapSharp.indexOf(normalizeRoot(root));
                if (idx === -1) return chord;
                const newRoot = mapSharp[(idx + semis + 12) % 12];

                let newBass = '';
                if (bass) {
                    bass = normalizeRoot(bass);
                    let bIdx = mapSharp.indexOf(bass);
                    if (bIdx !== -1) newBass = '/' + mapSharp[(bIdx + semis + 12) % 12];
                }
                return newRoot + rest + newBass;
            }
            
            function applyTranspose() {
                // Ищем аккорды динамически каждый раз (на случай изменения DOM)
                const currentChordSpans = document.querySelectorAll('.song-detail .chord');
                
                // Обновляем аккорды только если они есть на странице
                if (currentChordSpans.length) {
                    currentChordSpans.forEach(span => {
                        // Сохраняем базовый аккорд при первом обращении
                        if (!span.dataset.baseChord) {
                            const original = (span.dataset.chord || span.textContent || '').trim();
                            span.dataset.baseChord = original;
                            // Также сохраняем в dataset.chord для совместимости
                            if (!span.dataset.chord) {
                                span.dataset.chord = original;
                            }
                        }
                        const base = span.dataset.baseChord;
                        const transposed = transposeChord(base, shift);
                        span.dataset.chord = transposed;
                        span.textContent = transposed;
                    });
                }
                // Обновляем значение всегда
                if (tpValue) {
                    tpValue.textContent = shift > 0 ? `+${shift}` : `${shift}`;
                }
            }
            
            // Инициализация: сохраняем базовые аккорды при загрузке
            const initialChordSpans = document.querySelectorAll('.song-detail .chord');
            if (initialChordSpans.length) {
                initialChordSpans.forEach(span => {
                    if (!span.dataset.chord) {
                        span.dataset.chord = span.textContent.trim();
                    }
                });
            }
            
            // Применяем начальное состояние
            applyTranspose();
            
            // Добавляем обработчики событий
            tpMinus.addEventListener('click', () => { 
                shift = Math.max(-MAX_SHIFT, shift - 1); 
                applyTranspose(); 
            });
            tpPlus.addEventListener('click', () => { 
                shift = Math.min(MAX_SHIFT, shift + 1); 
                applyTranspose(); 
            });
            if (tpReset) {
                tpReset.addEventListener('click', () => { 
                    shift = 0; 
                    applyTranspose(); 
                });
            }
        }

        // Настройки вида (шрифты, размеры, межстрочный интервал, масштаб)
        (function() {
            const panel = document.getElementById('view-panel');
            const toggle = document.getElementById('view-toggle');
            if (!panel || !toggle) return;

            const fontSelect = document.getElementById('font-select');
            const lyricsRange = document.getElementById('lyrics-size');
            const chordRange = document.getElementById('chord-size');
            const lineRange = document.getElementById('line-height');
            const chordStyle = document.getElementById('chord-style');
            const autoCols = document.getElementById('auto-cols');
            const hideChords = document.getElementById('hide-chords');
            const autoColsBtn = document.getElementById('auto-cols-btn');
            const hideChordsBtn = document.getElementById('hide-chords-btn');
            const lv = document.getElementById('lyrics-val');
            const cv = document.getElementById('chord-val');
            const lnv = document.getElementById('line-val');
            const body = document.getElementById('song-body');
            const songContent = body ? body.querySelector('.song-content') : null;
            const originalNodes = songContent ? Array.from(songContent.children) : [];
            
            // Функция для обновления визуального состояния кнопок
            function updateToggleButtons() {
                if (autoColsBtn && autoCols) {
                    autoColsBtn.classList.toggle('active', autoCols.checked);
                }
                if (hideChordsBtn && hideChords) {
                    hideChordsBtn.classList.toggle('active', hideChords.checked);
                }
            }
            
            // Обработчики для кнопок-переключателей
            if (autoColsBtn && autoCols) {
                autoColsBtn.addEventListener('click', () => {
                    autoCols.checked = !autoCols.checked;
                    updateToggleButtons();
                    autoCols.dispatchEvent(new Event('change'));
                });
            }
            if (hideChordsBtn && hideChords) {
                hideChordsBtn.addEventListener('click', () => {
                    hideChords.checked = !hideChords.checked;
                    updateToggleButtons();
                    hideChords.dispatchEvent(new Event('change'));
                });
            }

            const STORAGE_KEY = 'vc_view_settings_v1';

            const defaults = {
                font: "'Arial',sans-serif",
                lyrics: 20,
                chords: 18,
                line: 1.2,
                chordStyle: 'frame',
                autoCols: false,
                hideChords: false
            };

            function loadSettings() {
                try {
                    const raw = localStorage.getItem(STORAGE_KEY);
                    if (!raw) return { ...defaults };
                    const parsed = JSON.parse(raw);
                    return {
                        font: parsed.font || defaults.font,
                        lyrics: Number.isFinite(parsed.lyrics) ? parsed.lyrics : defaults.lyrics,
                        chords: Number.isFinite(parsed.chords) ? parsed.chords : defaults.chords,
                        line: Number.isFinite(parsed.line) ? parsed.line : defaults.line,
                        chordStyle: parsed.chordStyle || defaults.chordStyle,
                        autoCols: !!parsed.autoCols,
                        hideChords: !!parsed.hideChords
                    };
                } catch {
                    return { ...defaults };
                }
            }

            let settings = loadSettings();

            function saveSettings() {
                try {
                    localStorage.setItem(STORAGE_KEY, JSON.stringify(settings));
                } catch {}
            }

            function apply(settings) {
                document.documentElement.style.setProperty('--lyrics-font', settings.font);
                document.documentElement.style.setProperty('--chord-font', settings.font);
                document.documentElement.style.setProperty('--lyrics-size', settings.lyrics + 'px');
                document.documentElement.style.setProperty('--chord-size', settings.chords + 'px');
                document.documentElement.style.setProperty('--lyrics-line', settings.line);
                lv.textContent = settings.lyrics + 'px';
                cv.textContent = settings.chords + 'px';
                if (lnv) lnv.textContent = (settings.line ?? defaults.line).toFixed(2);
                applyChordStyle(settings.chordStyle);
                if (autoCols) autoCols.checked = settings.autoCols;
                if (hideChords) hideChords.checked = settings.hideChords;
                updateToggleButtons();
                
                // Применяем скрытие аккордов
                if (songContent) {
                    const chordLines = songContent.querySelectorAll('.chord-line');
                    chordLines.forEach(line => {
                        line.style.display = settings.hideChords ? 'none' : '';
                    });
                }
                
                if (settings.autoCols) {
                    applyAutoCols();
                } else {
                    // одна колонка, исходная разметка
                    songContent.innerHTML = '';
                    originalNodes.forEach(n => songContent.appendChild(n.cloneNode(true)));
                    // Повторно применяем скрытие аккордов после пересоздания DOM
                    if (settings.hideChords && songContent) {
                        const chordLines = songContent.querySelectorAll('.chord-line');
                        chordLines.forEach(line => {
                            line.style.display = 'none';
                        });
                    }
                }
            }

            fontSelect.value = settings.font;
            lyricsRange.value = settings.lyrics;
            chordRange.value = settings.chords;
            lineRange.value = settings.line;
            chordStyle.value = settings.chordStyle;
            if (autoCols) autoCols.checked = settings.autoCols;
            if (hideChords) hideChords.checked = settings.hideChords;
            updateToggleButtons();
            apply(settings);

            function persist() {
                settings = {
                    font: fontSelect.value,
                    lyrics: parseInt(lyricsRange.value, 10),
                    chords: parseInt(chordRange.value, 10),
                    line: parseFloat(lineRange.value),
                    chordStyle: chordStyle.value || 'plain',
                    autoCols: !!(autoCols && autoCols.checked),
                    hideChords: !!(hideChords && hideChords.checked)
                };
                saveSettings();
                apply(settings);
            }

            fontSelect.addEventListener('change', () => {
                persist();
            });
            [lyricsRange, chordRange, lineRange].forEach(r => r.addEventListener('input', () => {
                persist();
            }));
            chordStyle.addEventListener('change', persist);
            if (autoCols) {
                autoCols.addEventListener('change', () => {
                    updateToggleButtons();
                    persist();
                });
            }
            if (hideChords) {
                hideChords.addEventListener('change', () => {
                    updateToggleButtons();
                    persist();
                });
            }

            const viewReset = document.getElementById('view-reset');
            if (viewReset) {
                viewReset.addEventListener('click', () => {
                    settings = { ...defaults };
                    saveSettings();
                    fontSelect.value = settings.font;
                    lyricsRange.value = settings.lyrics;
                    chordRange.value = settings.chords;
                    lineRange.value = settings.line;
                    chordStyle.value = settings.chordStyle;
                    if (autoCols) autoCols.checked = settings.autoCols;
                    if (hideChords) hideChords.checked = settings.hideChords;
                    updateToggleButtons();
                    apply(settings);
                });
            }

            toggle.addEventListener('click', (e) => {
                e.stopPropagation();
                panel.classList.toggle('open');
            });
            document.addEventListener('click', (e) => {
                if (!panel.contains(e.target) && e.target !== toggle) panel.classList.remove('open');
            });

            function applyTwoCols(enabled) {
                if (!songContent || !originalNodes.length) return;
                if (!enabled) {
                    songContent.innerHTML = '';
                    originalNodes.forEach(n => songContent.appendChild(n));
                    // Применяем скрытие аккордов после пересоздания DOM
                    if (settings.hideChords) {
                        const chordLines = songContent.querySelectorAll('.chord-line');
                        chordLines.forEach(line => { line.style.display = 'none'; });
                    }
                    return;
                }
                const nodes = originalNodes.slice();
                const stanzas = [];
                let buff = [];
                const isSeparator = (node) => {
                    if (node.classList.contains('text-line')) {
                        const t = (node.textContent || '').trim().toLowerCase();
                        if (t === '' || t.includes('куплет') || t.includes('припев')) return true;
                    }
                    if (node.classList.contains('chord-line') && node.classList.contains('empty')) return true;
                    return false;
                };
                nodes.forEach((node, idx) => {
                    buff.push(node);
                    const next = nodes[idx + 1];
                    if (isSeparator(node) || !next) {
                        stanzas.push(buff);
                        buff = [];
                    }
                });
                if (buff.length) stanzas.push(buff);
                const half = Math.ceil(stanzas.length / 2);
                const left = stanzas.slice(0, half).flat();
                const right = stanzas.slice(half).flat();
                songContent.innerHTML = '';
                const grid = document.createElement('div');
                grid.className = 'two-col';
                const c1 = document.createElement('div');
                const c2 = document.createElement('div');
                left.forEach(n => c1.appendChild(n));
                right.forEach(n => c2.appendChild(n));
                grid.appendChild(c1); grid.appendChild(c2);
                songContent.appendChild(grid);
                // Применяем скрытие аккордов после пересоздания DOM
                if (settings.hideChords) {
                    const chordLines = songContent.querySelectorAll('.chord-line');
                    chordLines.forEach(line => { line.style.display = 'none'; });
                }
            }

            function applyAutoCols() {
                if (!songContent || !originalNodes.length) return;
                const maxCols = 4;
                const parent = songContent;
                const baseLyrics = parseInt(lyricsRange.value || defaults.lyrics, 10);
                const baseChords = parseInt(chordRange.value || defaults.chords, 10);
                const minSize = 8;
                const viewportH = window.innerHeight || document.documentElement.clientHeight;

                // сначала предпочитаем больше колонок, потом уменьшение шрифта
                outer:
                for (let factor = 1.0; factor >= 0.5; factor -= 0.1) {
                    const lyrSize = Math.max(minSize, Math.round(baseLyrics * factor));
                    const chrSize = Math.max(minSize, Math.round(baseChords * factor));

                    // применяем временные размеры
                    document.documentElement.style.setProperty('--lyrics-size', lyrSize + 'px');
                    document.documentElement.style.setProperty('--chord-size', chrSize + 'px');

                    for (let cols = 1; cols <= maxCols; cols++) {
                        parent.innerHTML = '';

                        // один столбец — просто оригинальный текст
                        if (cols === 1) {
                            originalNodes.forEach(n => parent.appendChild(n.cloneNode(true)));
                        } else {
                            const container = document.createElement('div');
                            container.className = 'two-col';
                            container.style.display = 'grid';
                            container.style.gridTemplateColumns = `repeat(${cols}, 1fr)`;

                            const columnEls = [];
                            for (let i = 0; i < cols; i++) {
                                const col = document.createElement('div');
                                columnEls.push(col);
                                container.appendChild(col);
                            }

                            // Правила переносов между колонками:
                            // 1) Начало новой колонки допускается, если первая строка в новой колонке — заголовок куплета/припева и т.п. (ru/en)
                            // 2) Или если последняя строка предыдущей колонки пустая, а следующая строка — аккорды или пустая
                            // 3) Или если последняя строка предыдущей колонки пустая, а следующая строка не начинается с аккордов
                            const nodes = originalNodes.slice();
                            const isHeading = (node) => {
                                if (!node || !node.classList.contains('text-line')) return false;
                                const t = (node.textContent || '').trim().toLowerCase();
                                if (!t) return false;
                                return /(verse|chorus|bridge|intro|outro|pre[- ]?chorus|interlude|solo|riff|part\s*\d+|куплет|припев|бридж|интро|аутро|проигрыш|интерлюдия|пред[- ]?припев|переход)/i.test(t);
                            };
                            const isEmptyTextLine = (node) => node && node.classList.contains('text-line') && (node.textContent || '').trim() === '';
                            const isChordLine = (node) => node && node.classList.contains('chord-line') && !node.classList.contains('empty');
                            const isTextLine = (node) => node && node.classList.contains('text-line') && !isEmptyTextLine(node);

                            // Разрешённые точки разрыва между i и i+1
                            const allowedBreak = new Array(nodes.length).fill(false);
                            for (let i = 0; i < nodes.length - 1; i++) {
                                const curr = nodes[i];
                                const next = nodes[i + 1];
                                // Правило 1: следующая — заголовок
                                if (isHeading(next)) {
                                    allowedBreak[i] = true;
                                    continue;
                                }
                                // Правило 2: текущая пустая текстовая, а следующая — аккорды или пустая
                                if (isEmptyTextLine(curr) && (isChordLine(next) || isEmptyTextLine(next))) {
                                    allowedBreak[i] = true;
                                    continue;
                                }
                                // Правило 3: текущая пустая текстовая, а следующая — текстовая (не аккорды)
                                if (isEmptyTextLine(curr) && isTextLine(next)) {
                                    allowedBreak[i] = true;
                                    continue;
                                }
                            }

                            // Делим на cols колонок, двигая границы только к разрешённым разрывам
                            const total = nodes.length;
                            let startIdx = 0;
                            for (let c = 0; c < cols; c++) {
                                let endIdx;
                                if (c === cols - 1) {
                                    endIdx = total;
                                } else {
                                    const target = Math.round(((c + 1) * total) / cols);
                                    // Ищем ближайшую допустимую точку разрыва, предпочтительно вперёд, затем назад
                                    let found = -1;
                                    for (let j = target; j < total - 1; j++) {
                                        if (allowedBreak[j]) { found = j + 1; break; }
                                    }
                                    if (found === -1) {
                                        for (let j = target - 1; j > startIdx; j--) {
                                            if (allowedBreak[j - 1]) { found = j; break; }
                                        }
                                    }
                                    endIdx = found !== -1 ? found : target;
                                }
                                for (let i = startIdx; i < Math.min(endIdx, total); i++) {
                                    columnEls[c].appendChild(nodes[i].cloneNode(true));
                                }
                                startIdx = endIdx;
                            }

                            parent.appendChild(container);
                        }

                        const rect = parent.getBoundingClientRect();
                        const heightOk = rect.height <= viewportH * 0.95;
                        const widthOk = parent.scrollWidth <= parent.clientWidth * 1.02;

                        if (heightOk && widthOk) {
                            lyricsRange.value = lyrSize;
                            chordRange.value = chrSize;
                            // Применяем скрытие аккордов после пересоздания DOM
                            if (settings.hideChords) {
                                const chordLines = parent.querySelectorAll('.chord-line');
                                chordLines.forEach(line => { line.style.display = 'none'; });
                            }
                            break outer;
                        }
                    }
                }
                // Если не нашли подходящую комбинацию, все равно применяем скрытие аккордов
                if (settings.hideChords && parent) {
                    const chordLines = parent.querySelectorAll('.chord-line');
                    chordLines.forEach(line => { line.style.display = 'none'; });
                }
            }
        })();

        // Автопрокрутка
        (function() {
            const toggle = document.getElementById('scroll-toggle');
            const speedInput = document.getElementById('scroll-speed');
            if (!toggle || !speedInput) return;
            const savedSpeed = Number(localStorage.getItem('scrollSpeed') || 40);
            speedInput.value = isNaN(savedSpeed) ? 40 : savedSpeed;
            let raf = null;
            let lastTs = null;
            let isRunning = false;
            const loop = (ts) => {
                if (lastTs === null) lastTs = ts;
                const delta = ts - lastTs;
                lastTs = ts;
                // Линейная шкала, но с более высоким базовым уровнем, чтобы скролл ощущался сразу
                const raw = Number(speedInput.value || 40); // 0..100
                const factor = Math.min(1, Math.max(0, raw / 100)); // 0..1
                const pxPerSec = 12 + factor * 240;    // ~12..252 px/s
                const step = (pxPerSec / 1000) * delta;
                const maxScroll = document.documentElement.scrollHeight - window.innerHeight;
                const next = window.scrollY + step;
                if (next >= maxScroll) {
                    window.scrollTo(0, maxScroll);
                    stopHandler();
                    return;
                }
                window.scrollTo(0, next);
                raf = requestAnimationFrame(loop);
            };
            function stopHandler() {
                if (raf) cancelAnimationFrame(raf);
                raf = null;
                lastTs = null;
                isRunning = false;
                toggle.classList.remove('active');
                toggle.textContent = '▶';
            }
            toggle.addEventListener('click', () => {
                if (isRunning) {
                    stopHandler();
                    return;
                }
                isRunning = true;
                toggle.classList.add('active');
                toggle.textContent = '■';
                if (raf) cancelAnimationFrame(raf);
                lastTs = null;
                raf = requestAnimationFrame(loop);
            });
            speedInput.addEventListener('input', () => {
                localStorage.setItem('scrollSpeed', Number(speedInput.value || 40));
            });
            function userInterrupt() {
                if (isRunning) {
                    stopHandler();
                }
            }
            window.addEventListener('wheel', userInterrupt, { passive: true });
            window.addEventListener('touchmove', userInterrupt, { passive: true });
            window.addEventListener('beforeunload', () => stopHandler());
        })();

        // Контекстное меню "Добавить в сетлист"
        const isAdmin = <?php echo $isAdmin ? 'true' : 'false'; ?>;
        const csrfToken = '<?php echo htmlspecialchars($csrf); ?>';
        const setlists = <?php echo json_encode($setlistsAll, JSON_UNESCAPED_UNICODE); ?>;
        const setlistBlocks = <?php echo json_encode($setlistBlocks, JSON_UNESCAPED_UNICODE); ?>;
        if (isAdmin && setlists && setlists.length) {
            const menu = document.createElement('div');
            menu.className = 'context-setlist';
            document.body.appendChild(menu);
            let currentSongId = null;
            function hideMenu() { menu.style.display = 'none'; currentSongId = null; }
            document.addEventListener('click', e => { if (!menu.contains(e.target)) hideMenu(); });
            document.querySelectorAll('.song-card').forEach(card => {
                card.addEventListener('contextmenu', ev => {
                    ev.preventDefault();
                    const songId = card.getAttribute('data-id');
                    currentSongId = songId;
                    let html = '<div class="item" style="font-weight:700;">Добавить в сетлист:</div>';
                    setlists.forEach(sl => {
                        const blocks = Math.max(1, setlistBlocks[sl.id] || 1);
                        for (let b = 1; b <= blocks; b++) {
                            html += `<div class="item" data-sl="${sl.id}" data-block="${b}">${sl.name} — Блок ${b}</div>`;
                        }
                    });
                    menu.innerHTML = html;
                    menu.style.left = ev.clientX + 'px';
                    menu.style.top = ev.clientY + 'px';
                    menu.style.display = 'block';
                });
            });
            menu.addEventListener('click', e => {
                const el = e.target.closest('.item');
                if (!el || !el.dataset.sl || !currentSongId) return;
                fetch('/add_setlist_item.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrfToken },
                    body: new URLSearchParams({ setlist_id: el.dataset.sl, song_id: currentSongId, block_index: el.dataset.block || 1, csrf_token: csrfToken })
                }).then(r => r.json()).then(res => {
                    hideMenu();
                    if (res.ok) showToast('Добавлено в сетлист');
                    else showToast('Не удалось добавить', 'error');
                }).catch(() => { hideMenu(); showToast('Ошибка запроса', 'error'); });
            });
        }

        // Toast helper
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
                setTimeout(() => {
                    toast.remove();
                }, duration);
            };
        })();

        // Аппликатуры аккордов (по клику) — делегирование, работает и после перестроения DOM
        (function() {
            const shapes = <?php echo json_encode(require __DIR__ . '/chord_shapes.php', JSON_UNESCAPED_UNICODE); ?>;
            let container = document.querySelector('.toast-container');
            if (!container) {
                container = document.createElement('div');
                container.className = 'toast-container';
                document.body.appendChild(container);
            }
            let currentChordToast = null;
            function renderShape(name, shape) {
                const toast = document.createElement('div');
                toast.className = 'toast';
                toast.innerHTML = `<strong>${name}</strong>`;
                if (!shape) {
                    toast.innerHTML += `<div style="margin-top:6px;">Нет аппликатуры</div>`;
                    return toast;
                }
                const fretted = shape.filter(v => typeof v === 'number' && v > 0);
                const start = fretted.length ? Math.max(1, Math.min(...fretted) - 1) : 1;
                const end = start + 3; // показываем 4 лада
                const labels = ['6','5','4','3','2','1'];
                const header = `   Лады: ${start}-${end}\n`;
                const lines = shape.map((val, idx) => {
                    let prefix = '   ';
                    if (val === 'x') prefix = 'x  ';
                    else if (val === 0) prefix = 'o  ';
                    const cells = ['---','---','---','---'];
                    if (typeof val === 'number' && val > 0) {
                        const pos = Math.max(0, Math.min(cells.length - 1, val - start));
                        cells[pos] = '-*-';
                    }
                    return `${labels[idx]}:${prefix}|${cells.join('|')}|`;
                });
                toast.innerHTML += `<pre class="fret-pre">${header}${lines.join('\n')}</pre>`;
                toast.style.cursor = 'pointer';
                toast.addEventListener('click', () => toast.remove());
                return toast;
            }
            function enharmonic(name) {
                const m = name.match(/^([A-G])([#b]?)(.*)$/);
                if (!m) return name;
                const root = m[1].toUpperCase();
                const acc = m[2];
                const rest = m[3] || '';
                const map = { 'A#':'Bb', 'C#':'Db', 'D#':'Eb', 'F#':'Gb', 'G#':'Ab',
                              'Bb':'A#', 'Db':'C#', 'Eb':'D#', 'Gb':'F#', 'Ab':'G#' };
                const key = root + acc;
                if (map[key]) return map[key] + rest;
                return name;
            }
            const detail = document.querySelector('.song-detail');
            if (!detail) return;
            detail.addEventListener('click', (e) => {
                const ch = e.target.closest('.chord');
                if (!ch || !detail.contains(ch)) return;
                const name = (ch.dataset.chord || ch.textContent || '').trim();
                if (!name) return;
                if (currentChordToast) currentChordToast.remove();
                const norm = name.replace(/[()]/g, '').replace(/\s+/g, '');
                let lookup = norm;
                let shape = shapes[lookup] ?? null;
                if (!shape) {
                    lookup = enharmonic(norm);
                    shape = shapes[lookup] ?? null;
                }
                const toast = renderShape(lookup, shape);
                currentChordToast = toast;
                container.appendChild(toast);
            });
        })();
    </script>
    <?php endif; ?>
</body>
</html>
