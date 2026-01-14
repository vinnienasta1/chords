<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/db.php';
ensure_session_started();
DB::init();

function safe_lower($s) {
    if (function_exists('mb_strtolower')) return mb_strtolower($s, 'UTF-8');
    return strtolower($s);
}
function safe_sub($s, $len) {
    if (function_exists('mb_substr')) return mb_substr($s, 0, $len, 'UTF-8');
    return substr($s, 0, $len);
}

if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    header('Location: /auth.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

require_once __DIR__ . '/includes/user_helper.php';
require_once __DIR__ . '/includes/layout_helper.php';
$username = $_SESSION['username'] ?? 'Гость';
$userData = getCurrentUser($username);
extract($userData);
$db = DB::getConnection();

$id = (int)($_GET['id'] ?? 0);
$csrf = csrf_token();
// Разрешаем редактирование админам/модераторам и владельцу
$isAdminOrModerator = ($isAdminOrModerator ?? ($isAdmin || ($isModerator ?? false)));
$userId = (int)($user['id'] ?? 0);
$viewMode = ($_GET['mode'] ?? '') === 'view';
if ($id <= 0) { header('Location: /setlists.php'); exit; }

// Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    // доступ к действиям будет проверен после загрузки сетлиста (ниже), здесь только ранний выход
    $action = $_POST['action'] ?? '';
    if ($action === 'toggle_public') {
        $newState = isset($_POST['is_public']) && $_POST['is_public'] === '1' ? 1 : 0;
        $token = $setlist['share_token'] ?: bin2hex(random_bytes(16));
        $db->prepare("UPDATE setlists SET is_public=?, share_token=? WHERE id=?")->execute([$newState, $token, $id]);
        header('Location: /setlist_view.php?id=' . $id . '&mode=' . ($viewMode ? 'view' : 'edit'));
        exit;
    } elseif ($action === 'set_share_role') {
        // Обновление роли токена и параметров доступа
        $role = $_POST['share_role'] ?? 'view';
        $scope = $_POST['share_scope'] ?? 'private';
        $inviteUser = trim($_POST['invite_username'] ?? '');
        $inviteCanEdit = isset($_POST['invite_can_edit']) && $_POST['invite_can_edit'] === '1';

        $token = $setlist['share_token'] ?: bin2hex(random_bytes(16));
        $canEditLink = $role === 'edit' ? 1 : 0;
        $isPublicNew = $scope === 'link' ? 1 : 0;
        $db->prepare("UPDATE setlists SET share_token=?, share_can_edit=?, is_public=? WHERE id=?")->execute([$token, $canEditLink, $isPublicNew, $id]);

        // Добавление приглашённого пользователя
        if ($inviteUser !== '') {
            $find = $db->prepare("SELECT id FROM users WHERE username=?");
            $find->execute([$inviteUser]);
            $uId = (int)($find->fetchColumn() ?? 0);
            if ($uId > 0) {
                $ins = $db->prepare("INSERT INTO setlist_access(setlist_id, user_id, can_edit) VALUES(?,?,?) ON DUPLICATE KEY UPDATE can_edit=VALUES(can_edit)");
                $ins->execute([$id, $uId, $inviteCanEdit ? 1 : 0]);
            }
        }

        header('Location: /setlist_view.php?id=' . $id . '&mode=' . ($viewMode ? 'view' : 'edit') . '#share');
        exit;
    } elseif ($action === 'remove_share_user') {
        $uid = (int)($_POST['share_user_id'] ?? 0);
        if ($uid > 0) {
            $db->prepare("DELETE FROM setlist_access WHERE setlist_id=? AND user_id=?")->execute([$id, $uid]);
        }
        header('Location: /setlist_view.php?id=' . $id . '&mode=' . ($viewMode ? 'view' : 'edit') . '#share');
        exit;
    } elseif ($action === 'add_block') {
        $max = $db->prepare("SELECT COALESCE(MAX(block_index),0)+1 AS next FROM setlist_items WHERE setlist_id=?");
        $max->execute([$id]);
        $next = (int)$max->fetch()['next'];
        $db->prepare("INSERT INTO setlist_items(setlist_id, song_id, block_index, position) VALUES(?, NULL, ?, 0)")->execute([$id, $next]);
    } elseif ($action === 'add_item') {
        $songId = (int)($_POST['song_id'] ?? 0);
        $block = (int)($_POST['block_index'] ?? 1);
        if ($songId > 0) {
            $stmt = $db->prepare("SELECT COALESCE(MAX(position),0)+1 AS pos FROM setlist_items WHERE setlist_id=? AND block_index=? AND song_id IS NOT NULL");
            $stmt->execute([$id, $block]);
            $pos = (int)$stmt->fetch()['pos'];
            $db->prepare("INSERT INTO setlist_items(setlist_id, song_id, block_index, position) VALUES(?,?,?,?)")->execute([$id, $songId, $block, $pos]);
            // убираем пустые плейсхолдеры-комментарии
            $db->prepare("DELETE FROM setlist_items WHERE setlist_id=? AND block_index=? AND song_id IS NULL AND (comment IS NULL OR TRIM(comment)='')")->execute([$id, $block]);
        }
    } elseif ($action === 'toggle') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $db->prepare("UPDATE setlist_items SET checked = CASE checked WHEN 1 THEN 0 ELSE 1 END WHERE id=?")->execute([$itemId]);
        echo json_encode(['ok'=>true]); exit;
    } elseif ($action === 'move') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $dir = $_POST['dir'] ?? 'up';
        $item = $db->prepare("SELECT * FROM setlist_items WHERE id=?");
        $item->execute([$itemId]);
        $item = $item->fetch();
        if ($item) {
            $cmp = $dir === 'up' ? "<" : ">";
            $order = $dir === 'up' ? "DESC" : "ASC";
            $neighbor = $db->prepare("SELECT * FROM setlist_items WHERE setlist_id=? AND block_index=? AND song_id IS NOT NULL AND position $cmp ? ORDER BY position $order LIMIT 1");
            $neighbor->execute([$id, $item['block_index'], $item['position']]);
            $neighbor = $neighbor->fetch();
            if ($neighbor) {
                $db->prepare("UPDATE setlist_items SET position=? WHERE id=?")->execute([$neighbor['position'], $itemId]);
                $db->prepare("UPDATE setlist_items SET position=? WHERE id=?")->execute([$item['position'], $neighbor['id']]);
            }
        }
        echo json_encode(['ok'=>true]); exit;
    } elseif ($action === 'swap') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $targetId = (int)($_POST['target_id'] ?? 0);
        if ($itemId && $targetId) {
            $aStmt = $db->prepare("SELECT id, block_index, position FROM setlist_items WHERE id=?");
            $bStmt = $db->prepare("SELECT id, block_index, position FROM setlist_items WHERE id=?");
            $aStmt->execute([$itemId]);
            $bStmt->execute([$targetId]);
            $a = $aStmt->fetch(); $b = $bStmt->fetch();
            if ($a && $b && $a['block_index'] == $b['block_index']) {
                $db->prepare("UPDATE setlist_items SET position=? WHERE id=?")->execute([$b['position'], $a['id']]);
                $db->prepare("UPDATE setlist_items SET position=? WHERE id=?")->execute([$a['position'], $b['id']]);
            }
        }
        echo json_encode(['ok'=>true]); exit;
    } elseif ($action === 'rename') {
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') {
            $db->prepare("UPDATE setlists SET name=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$name, $id]);
            echo json_encode(['ok'=>true]); exit;
        }
        echo json_encode(['ok'=>false]); exit;
    } elseif ($action === 'comment') {
        $txt = trim($_POST['comment'] ?? '');
        if ($txt !== '') {
            $db->prepare("INSERT INTO setlist_comments(setlist_id, comment) VALUES(?, ?)")->execute([$id, $txt]);
        }
    } elseif ($action === 'delete_block') {
        $blockIndex = (int)($_POST['block_index'] ?? 0);
        if ($blockIndex > 0) {
            $db->prepare("DELETE FROM setlist_items WHERE setlist_id=? AND block_index=?")->execute([$id, $blockIndex]);
        }
    } elseif ($action === 'delete_item') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        if ($itemId > 0) {
            $db->prepare("DELETE FROM setlist_items WHERE id=?")->execute([$itemId]);
        }
    } elseif ($action === 'add_comment') {
        $parentId = (int)($_POST['item_id'] ?? 0);
        $text = trim($_POST['comment'] ?? '');
        if ($parentId > 0 && $text !== '') {
            $pStmt = $db->prepare("SELECT block_index, position FROM setlist_items WHERE id=? AND setlist_id=?");
            $pStmt->execute([$parentId, $id]);
            $parent = $pStmt->fetch();
            if ($parent) {
                $block = (int)$parent['block_index'];
                $pos = (float)$parent['position'] + 0.1;
                $db->prepare("INSERT INTO setlist_items(setlist_id, song_id, block_index, position, comment) VALUES(?, NULL, ?, ?, ?)")->execute([$id, $block, $pos, $text]);
                // упорядочим заново
                $ids = $db->prepare("SELECT id FROM setlist_items WHERE setlist_id=? AND block_index=? ORDER BY position");
                $ids->execute([$id, $block]);
                $items = $ids->fetchAll();
                $posFix = 1;
                $u = $db->prepare("UPDATE setlist_items SET position=? WHERE id=?");
                foreach ($items as $it) { $u->execute([$posFix++, $it['id']]); }
                echo json_encode(['ok'=>true]); exit;
            } else {
                echo json_encode(['ok'=>false, 'error'=>'Элемент не найден']); exit;
            }
        } else {
            echo json_encode(['ok'=>false, 'error'=>'Не указан ID элемента или текст комментария']); exit;
        }
    } elseif ($action === 'reorder') {
        $blockIndex = (int)($_POST['block_index'] ?? 0);
        $ids = json_decode($_POST['order'] ?? '[]', true);
        if ($blockIndex > 0 && is_array($ids) && !empty($ids)) {
            // Пересчитываем позиции только для песен (song_id IS NOT NULL)
            $pos = 1;
            $stmt = $db->prepare("UPDATE setlist_items SET position=? WHERE id=? AND setlist_id=? AND block_index=? AND song_id IS NOT NULL");
            foreach ($ids as $idItem) {
                $stmt->execute([$pos++, (int)$idItem, $id, $blockIndex]);
            }
            echo json_encode(['ok'=>true]); exit;
        }
        echo json_encode(['ok'=>false]); exit;
    } elseif ($action === 'move_reorder') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $fromBlock = (int)($_POST['from_block'] ?? 0);
        $toBlock = (int)($_POST['to_block'] ?? 0);
        $targetOrder = json_decode($_POST['target_order'] ?? '[]', true);
        $originOrder = json_decode($_POST['origin_order'] ?? '[]', true);
        if ($itemId && $toBlock > 0 && is_array($targetOrder)) {
            // гарантируем, что в порядке назначения есть переносимый элемент
            if (empty($targetOrder) || !in_array($itemId, $targetOrder, true)) {
                $targetOrder[] = $itemId;
            }
            $db->beginTransaction();
            // перемещаем элемент в новый блок
            $db->prepare("UPDATE setlist_items SET block_index=?, position=0 WHERE id=? AND setlist_id=?")->execute([$toBlock, $itemId, $id]);
            // собрать итоговый порядок целевого блока: сначала targetOrder, затем остальные, сохраняя их порядок
            $existingTarget = $db->prepare("SELECT id FROM setlist_items WHERE setlist_id=? AND block_index=? AND id NOT IN (" . implode(',', array_fill(0, count($targetOrder), '?')) . ") ORDER BY position, id");
            $existingParams = array_merge([$id, $toBlock], $targetOrder);
            $existingTarget->execute($existingParams);
            $appendIds = $existingTarget->fetchAll(PDO::FETCH_COLUMN);
            $finalTarget = array_merge(array_map('intval', $targetOrder), array_map('intval', $appendIds));
            $pos = 1;
            $stmt = $db->prepare("UPDATE setlist_items SET position=? WHERE id=? AND setlist_id=? AND block_index=? AND song_id IS NOT NULL");
            foreach ($finalTarget as $tid) { $stmt->execute([$pos++, (int)$tid, $id, $toBlock]); }
            // удалить пустые заглушки без комментариев в целевом блоке
            $db->prepare("DELETE FROM setlist_items WHERE setlist_id=? AND block_index=? AND song_id IS NULL AND (comment IS NULL OR TRIM(comment)='')")->execute([$id, $toBlock]);
            // пересчитать исходный блок, если передали порядок
            if ($fromBlock > 0 && is_array($originOrder)) {
                if (!in_array($itemId, $originOrder, true)) {
                    // если не пришли элементы, пересчитаем по факту из БД
                    if (empty($originOrder)) {
                        $tmp = $db->prepare("SELECT id FROM setlist_items WHERE setlist_id=? AND block_index=? ORDER BY position, id");
                        $tmp->execute([$id, $fromBlock]);
                        $originOrder = $tmp->fetchAll(PDO::FETCH_COLUMN);
                    }
                }
                // исключаем перенесённый элемент из порядка источника
                $originOrder = array_values(array_filter(array_map('intval', $originOrder), fn($v) => $v !== $itemId));
                $pos2 = 1;
                $stmt2 = $db->prepare("UPDATE setlist_items SET position=? WHERE id=? AND setlist_id=? AND block_index=? AND song_id IS NOT NULL");
                foreach ($originOrder as $oid) { $stmt2->execute([$pos2++, (int)$oid, $id, $fromBlock]); }
                // удалить пустые заглушки без комментариев в исходном блоке
                $db->prepare("DELETE FROM setlist_items WHERE setlist_id=? AND block_index=? AND song_id IS NULL AND (comment IS NULL OR TRIM(comment)='')")->execute([$id, $fromBlock]);
            }
            $db->commit();
            echo json_encode(['ok'=>true]); exit;
        }
        echo json_encode(['ok'=>false]); exit;
    }
    header('Location: /setlist_view.php?id=' . $id);
    exit;
}

$setlistStmt = $db->prepare("SELECT s.*, u.username AS owner_username, u.full_name AS owner_full_name FROM setlists s LEFT JOIN users u ON u.id = s.owner_id WHERE s.id=?");
$setlistStmt->execute([$id]);
$setlist = $setlistStmt->fetch();
if (!$setlist) { header('Location: /setlists.php'); exit; }
$isOwner = (int)($setlist['owner_id'] ?? 0) === $userId;
$shareToken = $setlist['share_token'] ?? null;
$tokenParam = $_GET['token'] ?? '';
$tokenOk = $shareToken && $tokenParam && hash_equals($shareToken, $tokenParam);
$isPublic = (int)($setlist['is_public'] ?? 0) === 1;
$shareCanEdit = (int)($setlist['share_can_edit'] ?? 0) === 1;

// Загружаем список приглашённых пользователей
$shareUsersStmt = $db->prepare("SELECT sa.user_id, sa.can_edit, u.username, u.full_name FROM setlist_access sa JOIN users u ON u.id=sa.user_id WHERE sa.setlist_id=? ORDER BY u.username");
$shareUsersStmt->execute([$id]);
$shareUsers = [];
foreach ($shareUsersStmt->fetchAll() as $row) {
    $shareUsers[] = [
        'user_id' => (int)$row['user_id'],
        'can_edit' => (int)$row['can_edit'] === 1,
        'display' => $row['full_name'] ?: $row['username'],
    ];
}
$setlist['shared_users'] = $shareUsers;

$canView = $isAdminOrModerator || $isOwner || $isPublic || $tokenOk;
// Проверка точного доступа
$hasExplicitAccess = false;
$hasExplicitEdit = false;
if (!$isAdminOrModerator && !$isOwner) {
    $checkAccess = $db->prepare("SELECT can_edit FROM setlist_access WHERE setlist_id=? AND user_id=?");
    $checkAccess->execute([$id, $userId]);
    $accRow = $checkAccess->fetch();
    if ($accRow) {
        $hasExplicitAccess = true;
        $hasExplicitEdit = (int)$accRow['can_edit'] === 1;
    }
}
$canView = $isAdminOrModerator || $isOwner || $isPublic || $tokenOk || $hasExplicitAccess;
if (!$canView) { header('Location: /setlists.php'); exit; }
$canEdit = $isAdminOrModerator || $isOwner || ($tokenOk && $shareCanEdit) || $hasExplicitEdit;
$viewOnly = (!$canEdit) || $viewMode;
$fromSetlists = ($_GET['from'] ?? '') === 'setlists';

// Load blocks/items
$blockIdxStmt = $db->prepare("SELECT DISTINCT block_index FROM setlist_items WHERE setlist_id=? ORDER BY block_index");
$blockIdxStmt->execute([$id]);
$blockIndices = $blockIdxStmt->fetchAll(PDO::FETCH_COLUMN);
if (empty($blockIndices)) { $blockIndices = [1]; }

$itemsStmt = $db->prepare("SELECT si.*, s.title, s.artist, s.cap FROM setlist_items si LEFT JOIN songs s ON s.id=si.song_id WHERE si.setlist_id=? AND (si.song_id IS NOT NULL OR (si.comment IS NOT NULL AND TRIM(si.comment)<>'')) ORDER BY block_index, position");
$itemsStmt->execute([$id]);
$items = $itemsStmt->fetchAll();
$blocks = [];
foreach ($blockIndices as $bi) { $blocks[$bi] = []; }
foreach ($items as $it) { $blocks[$it['block_index']][] = $it; }

// songs for add
$allSongs = $db->query("SELECT id, title, artist, lyrics FROM songs ORDER BY title")->fetchAll();

$comments = $db->prepare("SELECT * FROM setlist_comments WHERE setlist_id=? ORDER BY created_at DESC");
$comments->execute([$id]);
$comments = $comments->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Сетлист: <?php echo htmlspecialchars($setlist['name']); ?></title>
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
        .layout { display:block; height:100vh; overflow-y:auto; }
        .sidebar { background:var(--panel); padding:1.2rem; border-right:1px solid var(--border); position:fixed; top:0; left:0; bottom:0; width:260px; overflow:auto; }
        .brand { font-weight:700; letter-spacing:0.5px; color:var(--text); margin-bottom:1rem; display:flex; align-items:center; gap:0.5rem; }
        .nav { display:grid; gap:0.4rem; }
        .nav a { display:block; padding:0.75rem 0.9rem; border-radius:10px; color:var(--text); text-decoration:none; background:var(--card-bg); border:1px solid var(--border); transition:0.2s; }
        .nav a:hover { background:color-mix(in srgb, var(--accent) 15%, transparent); }
        .nav a.active { background:color-mix(in srgb, var(--accent) 28%, transparent); border:1px solid color-mix(in srgb, var(--accent) 50%, transparent); box-shadow:0 6px 16px color-mix(in srgb, var(--accent) 20%, transparent); }
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
        .topbar { display:flex; justify-content:flex-start; align-items:center; margin-bottom:1rem; }
        .topbar h1 { margin:0; }
        .card { background:var(--card-bg); border:1px solid var(--border); border-radius:14px; padding:1.4rem; margin-bottom:1rem; }
        .block { margin-bottom:1rem; padding:0.9rem; border-radius:12px; border:1px solid var(--border); background:var(--card-bg); }
        .block h3 { margin:0 0 0.5rem; }
        .item { display:flex; align-items:flex-start; gap:0.6rem; padding:0.4rem 0; border-bottom:1px solid var(--border); cursor:pointer; }
        .item:last-child { border-bottom:none; }
        .handle { cursor:grab; color:var(--muted); font-weight:700; user-select:none; margin-left:0.5rem; }
        .title { flex:1; }
        .meta { color:var(--muted); font-size:0.9rem; }
        .btn { padding:0.4rem 0.8rem; border-radius:10px; border:1px solid var(--border); background:var(--card-bg); color:var(--text); cursor:pointer; }
        .btn.primary { background:var(--accent); border:none; color:#fff; }
        .btn.danger { background:#dc3545; border:none; color:#fff; }
        .input { width:100%; padding:0.7rem; border-radius:10px; border:1px solid var(--input-border); background:var(--input-bg); color:var(--text); }
        .song-filter { background:var(--panel); color:var(--text); border-radius:10px; border:1px solid var(--border); padding:0.6rem; }
        .song-list { background:var(--card-bg); color:var(--text); border-radius:10px; border:1px solid var(--border); padding:0.2rem; max-height:220px; overflow:auto; display:grid; gap:4px; }
        .song-item { padding:0.55rem 0.65rem; border-radius:10px; cursor:grab; background:var(--card-bg); display:flex; align-items:center; gap:8px; min-height:44px; }
        .song-item:hover { background:color-mix(in srgb, var(--accent) 12%, transparent); }
        .song-item .t { font-weight:600; color:var(--text); }
        .song-item .artist { color:var(--muted); font-size:0.95rem; }
        .toast-container { position:fixed; right:16px; bottom:16px; display:grid; gap:8px; z-index:2000; }
        .toast { min-width:200px; max-width:340px; background:#0b1224; color:#e2e8f0; border:1px solid rgba(255,255,255,0.12); border-radius:10px; padding:10px 12px; box-shadow:0 8px 24px rgba(0,0,0,0.25); font-size:0.95rem; }
        .toast.success { border-color: rgba(46,204,113,0.5); }
        .toast.error { border-color: rgba(231,76,60,0.5); }
        .toast .actions { margin-top:8px; display:flex; gap:8px; justify-content:flex-end; }
        .toast .btn-small { padding:0.35rem 0.7rem; border-radius:8px; border:none; cursor:pointer; font-weight:600; }
        .toast .btn-small.confirm { background:var(--accent); color:#fff; }
        .toast .btn-small.cancel { background:#4b5563; color:#fff; }
        .comment-box { margin-top:1rem; }
        .comment { padding:0.5rem 0; border-bottom:1px solid rgba(255,255,255,0.08); color:var(--muted); }
        .placeholder { border:1px dashed rgba(255,255,255,0.4); border-radius:8px; height:36px; margin:4px 0; }
        .context-menu { position:fixed; background:#0b1224; color:#e2e8f0; border:1px solid rgba(255,255,255,0.12); border-radius:8px; box-shadow:0 8px 24px rgba(0,0,0,0.35); padding:0.4rem 0; display:none; z-index:2000; min-width:200px; }
        .context-menu .item { padding:0.45rem 0.75rem; cursor:pointer; }
        .context-menu .item:hover { background:rgba(102,126,234,0.2); color:#fff; }
        .num { width:28px; color:var(--muted); text-align:right; margin-right:6px; font-weight:700; }
        #invite-edit-toggle.primary,
        #invite-edit-toggle.active {
            background:var(--accent);
            border-color:var(--accent);
            color:#fff;
        }
        #invite-edit-toggle.primary:hover,
        #invite-edit-toggle.active:hover {
            background:color-mix(in srgb, var(--accent) 85%, #fff 15%);
            border-color:var(--accent);
            color:#fff;
        }
        @media (max-width:960px) {
            .layout { display:block; }
        .backdrop { position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9; display:none; pointer-events:none; }
        .backdrop.show { display:block; pointer-events:auto; }
            .sidebar {
                position:fixed;
                inset:0 auto 0 0;
                width:240px;
                transform:translateX(-260px);
                transition:transform 0.2s ease;
                z-index:100;
            }
            .sidebar.open { transform:translateX(0); }
            .content { padding:0.85rem 0.9rem 1rem; margin-left:0; }
            .toggle {
                position:fixed;
                top:10px;
                left:10px;
                z-index:101;
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
                flex-direction: column;
                align-items: stretch;
                gap: 0.5rem;
            }
            .topbar > * { width: 100%; }
            .card {
                padding: 1.1rem;
            }
            h1 {
                font-size: 1.75rem;
                text-align: center;
            }
            .topbar h1 {
                text-align: center;
                width: 100%;
                margin: 0 auto;
            }
            .block {
                padding: 0.75rem;
            }
            .item {
                flex-direction: row;
                align-items: center;
                gap: 0.5rem;
            }
            .title {
                flex: 1;
                min-width: 0;
            }
            .handle {
                margin-left: 8px;
                margin-right: 2px;
                flex-shrink: 0;
            }
            .song-filter, .song-list {
                font-size: 16px;
            }
            .song-item {
                min-height: 44px;
                padding: 0.6rem;
            }
            form[style*="display:flex"] {
                flex-direction: column;
            }
            form[style*="display:flex"] > * {
                width: 100%;
            }
            .toast-container {
                right: 8px;
                left: 8px;
                bottom: 8px;
            }
            .toast {
                max-width: 100%;
            }
            .context-menu {
                left: 8px !important;
                right: 8px !important;
                width: auto !important;
                min-width: unset !important;
            }
        }
        
        @media (max-width: 480px) {
            .content { padding: 0.75rem; }
            .card {
                padding: 1rem;
            }
            .topbar {
                flex-direction: column;
                align-items: stretch;
            }
            .topbar > * { width: 100%; }
            h1 {
                font-size: 1.5rem;
                text-align: center;
            }
            .topbar h1 {
                text-align: center;
                width: 100%;
                margin: 0 auto;
            }
            .toggle {
                top: 8px;
                left: 8px;
                padding: 0.5rem 0.75rem;
            }
            .block {
                padding: 0.6rem;
            }
            .item {
                padding: 0.5rem 0;
            }
            .num {
                width: 24px;
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body>
    <div class="layout">
        <!-- Sidebar будет загружен через JavaScript -->
        <main class="content">
            <div class="topbar">
                <div style="flex:1; min-width:0;">
                    <h1 id="sl-title" data-id="<?php echo $setlist['id']; ?>" style="margin:0;"><?php echo htmlspecialchars($setlist['name']); ?></h1>
                    <div class="meta" style="margin-top:0.25rem; color:var(--muted);">
                        Владелец: <?php echo htmlspecialchars($setlist['owner_full_name'] ?: $setlist['owner_username'] ?: 'Неизвестно'); ?>
                        <?php if ($isPublic): ?> • Доступ по ссылке включён<?php endif; ?>
                    </div>
                </div>
                <div style="display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center;">
                    <a class="btn ghost btn-icon" href="#share" id="share-open" title="Поделиться" style="text-decoration:none;">
                        <?php echo renderIcon('share', 16, 16); ?>
                    </a>
                    <?php if ($canEdit): ?>
                    <form method="POST" style="display:flex; align-items:center; gap:0.5rem; margin-left:0; flex-shrink:0;">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                        <input type="hidden" name="action" value="toggle_public">
                        <label style="display:flex; align-items:center; gap:0.35rem; cursor:pointer;">
                            <input type="checkbox" name="is_public" value="1" <?php echo $isPublic ? 'checked' : ''; ?> onchange="this.form.submit()">
                            <span class="meta" style="color:var(--text);">Общий доступ</span>
                        </label>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <!-- Share modal overlay -->
            <div id="share-overlay" style="position:fixed; inset:0; background:rgba(0,0,0,0.55); backdrop-filter:blur(4px); display:none; z-index:3000; align-items:center; justify-content:center; padding:1rem;">
                <div id="share-modal" class="card" style="max-width:760px; width:100%; margin:0; position:relative; box-shadow:0 14px 48px rgba(0,0,0,0.4); border:1px solid var(--border); background:var(--card-bg); display:flex; flex-direction:column; gap:1rem;">
                    <div style="display:flex; justify-content:space-between; align-items:center; gap:0.5rem;">
                        <div>
                            <div class="meta" style="margin:0; color:var(--muted);">Шаринг</div>
                            <strong>Поделиться сет-листом</strong>
                        </div>
                        <button type="button" id="share-close" class="btn btn-icon ghost" style="border:1px solid var(--border); background:transparent;">
                            <?php echo renderIcon('close', 16, 16); ?>
                        </button>
                    </div>
                    <div style="display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center; border-bottom:1px solid var(--border); padding-bottom:0.25rem;">
                        <button type="button" class="btn ghost" id="tab-user" data-tab="share-user">С пользователем</button>
                        <button type="button" class="btn ghost" id="tab-link" data-tab="share-link">По ссылке</button>
                    </div>
                    <div id="tab-content-user" style="display:none; gap:1rem; flex-direction:row; flex-wrap:wrap;">
                        <div style="flex:1 1 320px; min-width:320px; max-width:480px; display:grid; gap:0.75rem;">
                            <?php if ($canEdit): ?>
                            <form method="POST" style="display:grid; gap:0.75rem;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                <input type="hidden" name="action" value="set_share_role">
                                <input type="hidden" name="share_scope" value="private">
                                <input type="hidden" name="share_role" value="<?php echo $setlist['share_can_edit'] ? 'edit' : 'view'; ?>">
                                <div style="display:grid; gap:0.4rem;">
                                    <div class="meta" style="color:var(--muted);">Пригласить пользователя</div>
                                    <div style="display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center;">
                                        <div style="position:relative; flex:0 1 240px; min-width:200px;">
                                            <input type="text" id="invite_username" name="invite_username" class="input" placeholder="Имя или логин" autocomplete="off" style="position:relative; z-index:1;">
                                            <span id="invite-ghost" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--muted); pointer-events:auto; z-index:0; font-size:inherit; display:none;"></span>
                                            <div id="invite-suggest" style="position:absolute; top:calc(100% + 4px); left:0; right:0; background:var(--card-bg); border:1px solid var(--border); border-radius:10px; box-shadow:0 10px 28px rgba(0,0,0,0.35); display:none; z-index:40; max-height:220px; overflow:auto;"></div>
                                        </div>
                                        <label id="invite-edit-toggle" class="btn ghost btn-icon" style="gap:0.35rem; padding:0.6rem 0.8rem; border:1px solid var(--border); cursor:pointer;" title="Редактирование">
                                            <input id="invite_can_edit" type="checkbox" name="invite_can_edit" value="1" style="display:none;">
                                            <?php echo renderIcon('settings', 16, 16); ?>
                                        </label>
                                        <button class="btn ghost btn-icon" type="submit" name="invite_action" value="add" title="Добавить">
                                            <?php echo renderIcon('plus', 16, 16); ?>
                                        </button>
                                    </div>
                                </div>
                            </form>
                            <?php endif; ?>
                        </div>
                        <div style="flex:1 1 280px; min-width:260px; max-width:420px; display:grid; gap:0.35rem;">
                            <?php if (!empty($setlist['shared_users'])): ?>
                            <div class="meta" style="color:var(--muted);">Приглашённые пользователи</div>
                            <div style="display:grid; gap:0.35rem;">
                                <?php foreach ($setlist['shared_users'] as $shareUser): ?>
                                    <form method="POST" style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                        <input type="hidden" name="action" value="remove_share_user">
                                        <input type="hidden" name="share_user_id" value="<?php echo (int)$shareUser['user_id']; ?>">
                                        <span class="meta" style="color:var(--text); font-weight:600;"><?php echo htmlspecialchars($shareUser['display']); ?></span>
                                        <span class="meta" style="color:var(--muted);"><?php echo $shareUser['can_edit'] ? 'Редактирование' : 'Просмотр'; ?></span>
                                        <?php if ($canEdit): ?>
                                        <button class="btn danger btn-icon" type="submit" title="Убрать доступ">
                                            <?php echo renderIcon('trash', 16, 16); ?>
                                        </button>
                                        <?php endif; ?>
                                    </form>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div id="tab-content-link" style="display:none; gap:0.9rem; flex-direction:column;">
                        <div style="display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center;">
                            <div style="flex:1; min-width:240px;">
                                <div class="meta" style="margin-bottom:0.25rem; color:var(--muted);">Ссылка</div>
                                <input id="share-link" class="input" type="text" readonly value="<?php echo htmlspecialchars((isset($_SERVER['HTTP_HOST']) ? (($_SERVER['HTTPS'] ?? '') === 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] : '') . '/setlist_view.php?id=' . $setlist['id'] . '&token=' . $shareToken); ?>">
                            </div>
                            <button type="button" class="btn ghost btn-icon" id="share-copy" title="Копировать">
                                <?php echo renderIcon('copy', 16, 16); ?>
                            </button>
                        </div>
                        <?php if ($canEdit): ?>
                        <form method="POST" style="display:grid; gap:0.75rem;">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                            <input type="hidden" name="action" value="set_share_role">
                            <div style="display:grid; gap:0.35rem;">
                                <div class="meta" style="color:var(--muted);">Роль по ссылке</div>
                                <label style="display:flex; align-items:center; gap:0.35rem;">
                                    <input type="radio" name="share_role" value="view" <?php echo !$setlist['share_can_edit'] ? 'checked' : ''; ?>> Только просмотр
                                </label>
                                <label style="display:flex; align-items:center; gap:0.35rem;">
                                    <input type="radio" name="share_role" value="edit" <?php echo $setlist['share_can_edit'] ? 'checked' : ''; ?>> Редактирование
                                </label>
                            </div>
                            <div style="display:grid; gap:0.35rem;">
                                <div class="meta" style="color:var(--muted);">Доступ</div>
                                <label style="display:flex; align-items:center; gap:0.35rem;">
                                    <input type="radio" name="share_scope" value="link" <?php echo $isPublic ? 'checked' : ''; ?>> Всем по ссылке
                                </label>
                                <label style="display:flex; align-items:center; gap:0.35rem;">
                                    <input type="radio" name="share_scope" value="private" <?php echo !$isPublic ? 'checked' : ''; ?>> Только приглашённым
                                </label>
                            </div>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="card">
                <?php if (!$viewOnly): ?>
                <form method="POST" style="margin-bottom:0.5rem;">
                    <input type="hidden" name="action" value="add_block">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                    <button class="btn primary" type="submit">Добавить блок</button>
                </form>
                <div style="margin-bottom:1rem; display:flex; gap:0.5rem; flex-wrap:wrap;">
                    <div style="flex:1; min-width:260px; display:flex; flex-direction:column; gap:0.3rem;">
                        <input type="text" class="song-filter" placeholder="Поиск песни...">
                        <div class="song-list">
                        <?php foreach ($allSongs as $s): $lyr = safe_lower(safe_sub($s['lyrics'] ?? '', 1000)); ?>
                                <div class="song-item" draggable="true" data-id="<?php echo $s['id']; ?>" data-title="<?php echo htmlspecialchars($s['title']); ?>" data-artist="<?php echo htmlspecialchars($s['artist'] ?? ''); ?>" data-lyrics="<?php echo htmlspecialchars($lyr); ?>">
                                    <div class="t"><?php echo htmlspecialchars($s['title']); ?></div>
                                    <?php if ($s['artist']): ?><div class="artist">— <?php echo htmlspecialchars($s['artist']); ?></div><?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php $songOrder = 0; ?>
                <?php foreach ($blocks as $blockIndex => $blockItems): ?>
                    <?php $blockCounter = 1; ?>
                    <div class="block" data-block="<?php echo $blockIndex; ?>">
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <h3 style="margin:0;">Блок <?php echo $blockIndex; ?></h3>
                            <?php if (!$viewOnly): ?>
                            <form method="POST" data-confirm="Удалить блок?">
                                <input type="hidden" name="action" value="delete_block">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                <input type="hidden" name="block_index" value="<?php echo $blockIndex; ?>">
                                <button class="btn danger btn-icon" type="submit" title="Удалить блок">
                                    <?php echo renderIcon('trash', 16, 16); ?>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                        <?php if (empty($blockItems)): ?>
                            <div class="meta">Песен нет. Добавьте через форму выше.</div>
                        <?php else: ?>
                        <?php foreach ($blockItems as $item): ?>
                            <?php if (empty($item['song_id']) || $item['song_id'] === null): ?>
                                <div class="item comment" data-id="<?php echo $item['id']; ?>" data-song="0" data-block="<?php echo $blockIndex; ?>" <?php if(!$viewOnly): ?>draggable="true"<?php endif; ?>>
                                    <div class="num" aria-hidden="true">—</div>
                                    <div class="title" style="white-space:pre-wrap;"><?php echo htmlspecialchars($item['comment'] ?? ''); ?></div>
                                    <?php if (!$viewOnly): ?><span class="handle">=</span><?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="item" data-id="<?php echo $item['id']; ?>" data-song="<?php echo $item['song_id']; ?>" data-block="<?php echo $blockIndex; ?>" data-order="<?php echo $songOrder++; ?>" <?php if(!$viewOnly): ?>draggable="true"<?php endif; ?>>
                                    <div class="num"><?php echo $blockCounter++; ?>.</div>
                                    <div class="title">
                                        <?php echo htmlspecialchars($item['title'] ?? ''); ?>
                                        <?php if (!empty($item['artist'])): ?><span class="meta">— <?php echo htmlspecialchars($item['artist']); ?></span><?php endif; ?>
                                        <?php if (!empty($item['cap'])): ?><span class="meta" style="margin-left:8px;">Cap: <?php echo htmlspecialchars($item['cap']); ?></span><?php endif; ?>
                                    </div>
                                    <?php if (!$viewOnly): ?><span class="handle">=</span><?php endif; ?>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </main>
    </div>
    <script>
        const viewOnly = <?php echo $viewOnly ? 'true' : 'false'; ?>;
        const csrfToken = '<?php echo htmlspecialchars($csrf); ?>';
        const defaultHeaders = { 'Content-Type': 'application/x-www-form-urlencoded' };
        const formWithCsrf = (params) => {
            const data = new URLSearchParams(params);
            data.append('csrf_token', csrfToken);
            return data;
        };
        // Share modal
        const shareModal = document.getElementById('share-modal');
        const shareOpen = document.getElementById('share-open');
        const shareClose = document.getElementById('share-close');
        const shareOverlay = document.getElementById('share-overlay');
        const shareLink = document.getElementById('share-link');
        const shareCopy = document.getElementById('share-copy');
        const tabUser = document.getElementById('tab-user');
        const tabLink = document.getElementById('tab-link');
        const tabUserPane = document.getElementById('tab-content-user');
        const tabLinkPane = document.getElementById('tab-content-link');
        const fromSetlists = <?php echo $fromSetlists ? 'true' : 'false'; ?>;
        const showShare = () => { if (shareOverlay) { shareOverlay.style.display = 'flex'; window.location.hash = 'share'; } };
        const hideShare = () => {
            if (shareOverlay) shareOverlay.style.display = 'none';
            if (window.location.hash === '#share') {
                history.replaceState(null, '', window.location.pathname + window.location.search);
            }
            if (fromSetlists && viewOnly) {
                window.location.href = '/setlists.php#share';
            }
        };
        if (shareOpen) shareOpen.addEventListener('click', (e) => { e.preventDefault(); showShare(); });
        if (shareClose) shareClose.addEventListener('click', hideShare);
        if (shareOverlay) shareOverlay.addEventListener('click', (e) => { if (e.target === shareOverlay) hideShare(); });
        if (shareCopy && shareLink) {
            shareCopy.addEventListener('click', () => {
                shareLink.select();
                document.execCommand('copy');
            });
        }
        if (window.location.hash === '#share') showShare();
        const activateTab = (tab) => {
            if (!tabUserPane || !tabLinkPane || !tabUser || !tabLink) return;
            tabUserPane.style.display = tab === 'user' ? 'flex' : 'none';
            tabLinkPane.style.display = tab === 'link' ? 'flex' : 'none';
            tabUser.classList.toggle('primary', tab === 'user');
            tabLink.classList.toggle('primary', tab === 'link');
        };
        if (tabUser) tabUser.addEventListener('click', () => activateTab('user'));
        if (tabLink) tabLink.addEventListener('click', () => activateTab('link'));
        activateTab('user');

        // Автодополнение пользователей + inline подсказка
        const inviteInput = document.getElementById('invite_username');
        const suggestBox = document.getElementById('invite-suggest');
        const inviteGhost = document.getElementById('invite-ghost');
        const editToggle = document.getElementById('invite-edit-toggle');
        const editCheckbox = document.getElementById('invite_can_edit');
        let suggestTimer = null;
        let firstSuggestion = '';
        const acceptGhost = () => {
            if (!firstSuggestion || !inviteInput) return;
            inviteInput.value = firstSuggestion;
            firstSuggestion = '';
            if (inviteGhost) { inviteGhost.style.display = 'none'; inviteGhost.textContent = ''; }
        };
        let renderSuggest = (items) => {
            // Без выпадающего списка: только inline-подсказка
            if (!items.length) {
                firstSuggestion = '';
                if (suggestBox) { suggestBox.style.display = 'none'; suggestBox.innerHTML = ''; }
                updateGhost();
                return;
            }
            firstSuggestion = items[0].username || '';
            if (suggestBox) { suggestBox.style.display = 'none'; suggestBox.innerHTML = ''; }
            updateGhost();
        };
        const fetchSuggest = (q) => {
            fetch('/api/users_search.php?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
                .then(r => r.ok ? r.json() : [])
                .then(arr => {
                    const list = Array.isArray(arr) ? arr : [];
                    renderSuggest(list.map(u => ({
                        username: u.username,
                        display: u.full_name ? `${u.full_name} (${u.username})` : u.username
                    })));
                })
                .catch(() => renderSuggest([]));
        };
        if (inviteInput) {
            inviteInput.addEventListener('input', () => {
                const q = inviteInput.value.trim();
                clearTimeout(suggestTimer);
                if (inviteGhost) { inviteGhost.style.display = 'none'; inviteGhost.textContent = ''; }
                firstSuggestion = '';
                if (q.length < 2) { renderSuggest([]); return; }
                suggestTimer = setTimeout(() => fetchSuggest(q), 200);
            });
            inviteInput.addEventListener('keydown', (e) => {
                if (e.key === 'ArrowRight' && firstSuggestion) {
                    acceptGhost();
                    renderSuggest([]);
                }
            });
            inviteInput.addEventListener('touchend', () => {
                if (firstSuggestion) {
                    acceptGhost();
                    renderSuggest([]);
                }
            }, { passive: true });
        }
        // Обновляем ghost по подсказкам
        const updateGhost = () => {
            if (!inviteInput || !inviteGhost) return;
            const q = inviteInput.value;
            if (!firstSuggestion || !q) { inviteGhost.style.display = 'none'; inviteGhost.textContent = ''; return; }
            if (firstSuggestion.toLowerCase().startsWith(q.toLowerCase())) {
                const tail = firstSuggestion.substring(q.length);
                inviteGhost.textContent = q + tail;
                inviteGhost.style.display = 'block';
            } else {
                inviteGhost.style.display = 'none';
                inviteGhost.textContent = '';
            }
        };
        // Подхватываем первую подсказку после fetch
        const originalRenderSuggest = renderSuggest;
        renderSuggest = (items) => {
            if (!suggestBox) return;
            originalRenderSuggest(items);
            if (items.length > 0 && inviteInput) {
                firstSuggestion = items[0].username || '';
                updateGhost();
            } else {
                firstSuggestion = '';
                updateGhost();
            }
        };
        // Тоггл редактирования кнопкой
        if (editToggle && editCheckbox) {
            const syncEdit = () => {
                editToggle.classList.toggle('primary', !!editCheckbox.checked);
                editToggle.classList.toggle('active', !!editCheckbox.checked);
            };
            editToggle.addEventListener('click', (e) => {
                e.preventDefault();
                editCheckbox.checked = !editCheckbox.checked;
                syncEdit();
            });
            syncEdit();
        }
        if (inviteGhost) {
            inviteGhost.addEventListener('click', () => {
                if (firstSuggestion) {
                    acceptGhost();
                    renderSuggest([]);
                }
            });
        }
        document.getElementById('sl-title')?.addEventListener('dblclick', (e) => {
            if (viewOnly) return;
            const id = e.target.dataset.id;
            const val = prompt('Новое название:', e.target.textContent.trim());
            if (!val) return;
            fetch('/setlist_view.php?id=' + id, {
                method: 'POST',
                headers: defaultHeaders,
                body: formWithCsrf({ action: 'rename', name: val })
            }).then(r => r.json()).then(() => e.target.textContent = val);
        });
        // drag & drop reorder with placeholder + переход на песню + контекстное меню
        (function() {
            if (viewOnly) {
                const setlistId = <?php echo (int)$id; ?>;
                document.querySelectorAll('.item').forEach(el => {
                    el.addEventListener('click', (ev) => {
                        if (ev.target.closest('form')) return;
                        const song = el.dataset.song;
                        const order = el.dataset.order ?? '';
                        if (song && song !== '0') {
                            const url = new URL('/songs.php', window.location.origin);
                            url.searchParams.set('song_id', song);
                            url.searchParams.set('setlist_id', setlistId);
                            if (order !== '') url.searchParams.set('setlist_pos', order);
                            window.location.href = url.toString();
                        }
                    });
                });
                return;
            }
            let dragging = null;
            let draggingSongId = null;
            const placeholder = document.createElement('div');
            placeholder.className = 'placeholder';
            const ctx = document.createElement('div');
            ctx.className = 'context-menu';
            ctx.innerHTML = `
                <div class="item" data-action="move">Переместить</div>
                <div class="item" data-action="delete">Удалить</div>
                <div class="item" data-action="comment">Добавить комментарий снизу</div>
            `;
            document.body.appendChild(ctx);
            let ctxTarget = null;

            // Обновляем нумерацию после перестановки
            function updateNumbers() {
                document.querySelectorAll('.block').forEach(block => {
                    let counter = 1;
                    block.querySelectorAll('.item').forEach(item => {
                        const numEl = item.querySelector('.num');
                        if (numEl && item.dataset.song && item.dataset.song !== '0') {
                            numEl.textContent = counter++ + '.';
                        }
                    });
                });
            }
            
            const blocks = document.querySelectorAll('.block');
            blocks.forEach(blockEl => {
                const blockIndex = blockEl.dataset.block;
                blockEl.addEventListener('dragover', ev => {
                    ev.preventDefault();
                    if (!dragging && !draggingSongId) return;
                    placePlaceholder(blockEl, ev.clientY);
                });
                blockEl.addEventListener('drop', ev => {
                    ev.preventDefault();
                    if (draggingSongId) {
                        addSongToBlock(blockIndex, draggingSongId);
                        draggingSongId = null;
                        placeholder.remove();
                        return;
                    }
                    if (!dragging) return;
                    if (!placeholder.parentElement) {
                        placePlaceholder(blockEl, ev.clientY);
                        if (!placeholder.parentElement) return;
                    }
                    const originBlock = dragging.dataset.block;
                    blockEl.insertBefore(dragging, placeholder);
                    placeholder.remove();
                    dragging.dataset.block = blockIndex;
                    // Обновляем нумерацию сразу после изменения порядка в DOM
                    updateNumbers();
                    const targetOrder = Array.from(blockEl.querySelectorAll('.item[draggable="true"]')).filter(i => i.dataset.song && i.dataset.song !== '0').map(i => i.dataset.id);
                    if (originBlock === blockIndex) {
                        sendReorder(blockIndex, targetOrder);
                    } else {
                        const originEl = document.querySelector(`.block[data-block="${originBlock}"]`);
                        const originOrder = originEl ? Array.from(originEl.querySelectorAll('.item[draggable="true"]')).filter(i => i !== dragging && i.dataset.song && i.dataset.song !== '0').map(i => i.dataset.id) : [];
                        moveAcrossBlocks(dragging.dataset.id, originBlock, blockIndex, targetOrder, originOrder);
                    }
                });
            });

            const setlistId = <?php echo (int)$id; ?>;
            document.querySelectorAll('.item[draggable="true"]').forEach(el => {
                el.addEventListener('click', (ev) => {
                    if (ev.target.closest('form')) return;
                    const song = el.dataset.song;
                    const order = el.dataset.order ?? '';
                    if (song && song !== '0') {
                        const url = new URL('/songs.php', window.location.origin);
                        url.searchParams.set('song_id', song);
                        url.searchParams.set('setlist_id', setlistId);
                        if (order !== '') url.searchParams.set('setlist_pos', order);
                        window.location.href = url.toString();
                    }
                });
                el.addEventListener('dragstart', ev => {
                    dragging = el;
                    ev.dataTransfer.setData('text/plain', el.dataset.id);
                    ev.dataTransfer.effectAllowed = 'move';
                    setTimeout(() => {
                        el.style.opacity = '0.3';
                    });
                });
                el.addEventListener('dragend', () => {
                    dragging = null;
                    el.style.opacity = '1';
                    placeholder.remove();
                });
                el.addEventListener('contextmenu', ev => {
                    ev.preventDefault();
                    ctxTarget = el;
                    ctx.style.left = ev.clientX + 'px';
                    ctx.style.top = ev.clientY + 'px';
                    ctx.style.display = 'block';
                });
            });
            document.addEventListener('click', ev => {
                if (!ctx.contains(ev.target)) ctx.style.display = 'none';
            });
            ctx.addEventListener('click', ev => {
                const act = ev.target.dataset.action;
                if (!act || !ctxTarget) return;
                ctx.style.display = 'none';
                const itemId = ctxTarget.dataset.id;
                const block = ctxTarget.dataset.block;
                if (act === 'delete') {
                    fetch('/setlist_view.php?id=<?php echo $id; ?>', {
                        method: 'POST',
                        headers: defaultHeaders,
                        body: formWithCsrf({ action: 'delete_item', item_id: itemId })
                    }).then(() => location.reload());
                } else if (act === 'comment') {
                    const txt = prompt('Комментарий:');
                    if (!txt || !txt.trim()) return;
                    fetch('/setlist_view.php?id=<?php echo $id; ?>', {
                        method: 'POST',
                        headers: defaultHeaders,
                        body: formWithCsrf({ action: 'add_comment', item_id: itemId, comment: txt.trim() })
                    }).then(r => r.json()).then(res => {
                        if (res.ok) {
                            location.reload();
                        } else {
                            showToast(res.error || 'Ошибка при добавлении комментария', 'error');
                        }
                    }).catch(err => {
                        showToast('Ошибка запроса', 'error');
                        console.error(err);
                    });
                } else if (act === 'move') {
                    showToast('Перетаскивайте за "=" для перемещения');
                }
            });

            function sendReorder(blockIndex, order) {
                fetch('/setlist_view.php?id=<?php echo $id; ?>', {
                    method: 'POST',
                    headers: defaultHeaders,
                    body: formWithCsrf({ action: 'reorder', block_index: blockIndex, order: JSON.stringify(order) })
                }).then(() => showToast('Порядок сохранён'));
            }

            function moveAcrossBlocks(itemId, fromBlock, toBlock, targetOrder, originOrder) {
                fetch('/setlist_view.php?id=<?php echo $id; ?>', {
                    method: 'POST',
                    headers: defaultHeaders,
                    body: formWithCsrf({
                        action: 'move_reorder',
                        item_id: itemId,
                        from_block: fromBlock,
                        to_block: toBlock,
                        target_order: JSON.stringify(targetOrder),
                        origin_order: JSON.stringify(originOrder)
                    })
                }).then(() => location.reload())
                  .catch(() => location.reload());
            }

            // Drag from song list
            document.querySelectorAll('.song-item').forEach(el => {
                el.addEventListener('dragstart', ev => {
                    draggingSongId = el.dataset.id;
                    ev.dataTransfer.setData('text/plain', draggingSongId);
                    ev.dataTransfer.effectAllowed = 'copy';
                });
                el.addEventListener('dragend', () => {
                    draggingSongId = null;
                });
                el.addEventListener('dblclick', () => {
                    addSongToLastBlock(el.dataset.id);
                });
            });

            function addSongToBlock(blockIndex, songId) {
                fetch('/setlist_view.php?id=<?php echo $id; ?>', {
                    method: 'POST',
                    headers: defaultHeaders,
                    body: formWithCsrf({ action: 'add_item', block_index: blockIndex, song_id: songId })
                }).then(() => location.reload()).catch(() => location.reload());
            }
            function addSongToLastBlock(songId) {
                const blocks = Array.from(document.querySelectorAll('.block'));
                const last = blocks.length ? blocks[blocks.length - 1].dataset.block : '1';
                addSongToBlock(last, songId);
            }

            function placePlaceholder(blockEl, clientY) {
                const items = Array.from(blockEl.querySelectorAll('.item[draggable="true"]')).filter(i => i !== dragging);
                if (!items.length) {
                    if (!placeholder.parentElement || placeholder.parentElement !== blockEl) blockEl.appendChild(placeholder);
                    return;
                }
                let target = null;
                for (const it of items) {
                    const rect = it.getBoundingClientRect();
                    if (clientY < rect.top + rect.height / 2) { target = it; break; }
                }
                if (target) {
                    blockEl.insertBefore(placeholder, target);
                } else {
                    blockEl.appendChild(placeholder);
                }
            }
        })();
        // Фильтр песен в форме добавления
        (function() {
            const input = document.querySelector('.song-filter');
            const list = document.querySelector('.song-list');
            if (!input || !list) return;
            const items = Array.from(list.querySelectorAll('.song-item'));
            input.addEventListener('input', () => {
                const q = input.value.toLowerCase();
                items.forEach(it => {
                    const txt = (it.dataset.title + ' ' + it.dataset.artist + ' ' + (it.dataset.lyrics || '')).toLowerCase();
                    it.style.display = txt.includes(q) ? '' : 'none';
                });
            });
        })();

        // Toasts and confirm
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
    </script>
    <script src="/js/sidebar-cache.js"></script>
    <script src="/js/theme-switcher.js"></script>
    <script src="/js/app.js"></script>
</body>
</html>

