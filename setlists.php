<?php
$username = require __DIR__ . '/includes/auth_required.php';
require_once __DIR__ . '/includes/user_helper.php';
require_once __DIR__ . '/includes/layout_helper.php';

$userData = getCurrentUser($username);
extract($userData);
$csrf = csrf_token();
DB::init();
$db = DB::getConnection();
$userId = (int)($user['id'] ?? 0);
$isAdminOrModerator = $isAdminOrModerator ?? ($isAdmin || ($isModerator ?? false));

// CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    // Разрешаем создание всем авторизованным; редактирование/удаление только владельцу или админу/модератору
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') {
            $stmt = $db->prepare("INSERT INTO setlists(name, owner_id) VALUES(?, ?)");
            $stmt->execute([$name, $userId ?: null]);
            $newId = $db->lastInsertId();
            header('Location: /setlist_view.php?id=' . $newId . '&mode=edit');
            exit;
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $ownerStmt = $db->prepare("SELECT owner_id FROM setlists WHERE id=?");
            $ownerStmt->execute([$id]);
            $ownerId = (int)($ownerStmt->fetchColumn() ?? 0);
            if ($isAdminOrModerator || ($ownerId === $userId && $ownerId !== 0)) {
                $stmt = $db->prepare("DELETE FROM setlists WHERE id = ?");
                $stmt->execute([$id]);
            }
        }
    } elseif ($action === 'rename') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($id > 0 && $name !== '') {
            $ownerStmt = $db->prepare("SELECT owner_id FROM setlists WHERE id=?");
            $ownerStmt->execute([$id]);
            $ownerId = (int)($ownerStmt->fetchColumn() ?? 0);
            if ($isAdminOrModerator || ($ownerId === $userId && $ownerId !== 0)) {
                $stmt = $db->prepare("UPDATE setlists SET name = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$name, $id]);
            }
            echo json_encode(['ok' => true]);
            exit;
        }
        echo json_encode(['ok' => false]);
        exit;
    } elseif ($action === 'hide_shared') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0 && $userId) {
            $db->prepare("DELETE FROM setlist_access WHERE setlist_id=? AND user_id=?")->execute([$id, $userId]);
        }
        header('Location: /setlists.php');
        exit;
    }
    header('Location: /setlists.php');
    exit;
}

$showAll = $isAdminOrModerator && ($_GET['all'] ?? '') === '1';
if ($isAdminOrModerator && $showAll) {
    $setlists = $db->query("SELECT s.*, u.username AS owner_username, u.full_name AS owner_full_name, 0 AS shared_for_user FROM setlists s LEFT JOIN users u ON u.id = s.owner_id ORDER BY s.created_at DESC")->fetchAll();
} elseif ($isAdminOrModerator) {
    // Админы/модераторы без all видят свои + расшаренные им
    $stmt = $db->prepare("SELECT DISTINCT s.*, u.username AS owner_username, u.full_name AS owner_full_name,
        CASE WHEN sa.user_id IS NULL THEN 0 ELSE 1 END AS shared_for_user
        FROM setlists s
        LEFT JOIN users u ON u.id = s.owner_id
        LEFT JOIN setlist_access sa ON sa.setlist_id = s.id AND sa.user_id = ?
        WHERE s.owner_id = ? OR sa.user_id IS NOT NULL
        ORDER BY s.created_at DESC");
    $stmt->execute([$userId, $userId]);
    $setlists = $stmt->fetchAll();
} else {
    // Обычные пользователи: свои + расшаренные им
    $stmt = $db->prepare("SELECT DISTINCT s.*, u.username AS owner_username, u.full_name AS owner_full_name,
        CASE WHEN sa.user_id IS NULL THEN 0 ELSE 1 END AS shared_for_user
        FROM setlists s
        LEFT JOIN users u ON u.id = s.owner_id
        LEFT JOIN setlist_access sa ON sa.setlist_id = s.id AND sa.user_id = ?
        WHERE s.owner_id = ? OR sa.user_id IS NOT NULL
        ORDER BY s.created_at DESC");
    $stmt->execute([$userId, $userId]);
    $setlists = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Сет листы</title>
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
        .card { background:var(--card-bg); border:1px solid var(--border); border-radius:14px; padding:1.4rem; margin-bottom:1rem; }
        .page-header { display:flex; flex-wrap:wrap; gap:0.75rem; align-items:center; justify-content:space-between; margin-bottom:0.75rem; }
        h1 { margin:0 0 0.5rem; }
        .list { display:grid; gap:0.6rem; }
        .item { padding:0.9rem; border-radius:12px; background:var(--card-bg); border:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; color:var(--text); width:100%; gap:0.75rem; cursor:pointer; }
        .item-name { font-weight:700; cursor:pointer; color:var(--text); }
        .actions { display:flex; gap:0.5rem; }
        .btn { padding:0.5rem 0.9rem; border-radius:10px; border:none; cursor:pointer; font-weight:600; display:inline-flex; align-items:center; gap:0.5rem; }
        .btn.primary { background:var(--accent); color:#fff; }
        .btn.danger { background:#dc3545; color:#fff; }
        .btn.ghost { background:transparent; border:1px solid var(--btn-outline-border, var(--border)); color:var(--btn-outline-text, var(--text)); }
        .btn.ghost:hover { background:color-mix(in srgb, var(--accent) 10%, transparent); border-color:var(--accent); color:var(--accent); }
        .btn-icon svg { flex-shrink:0; }
        .btn-text { white-space:nowrap; }
        .input { width:100%; padding:0.7rem; border-radius:10px; border:1px solid var(--input-border); background:var(--input-bg); color:var(--text); }
        .toast-container { position:fixed; right:16px; bottom:16px; display:grid; gap:8px; z-index:2000; }
        .toast { min-width:200px; max-width:320px; background:var(--panel); color:var(--text); border:1px solid var(--border); border-radius:10px; padding:10px 12px; box-shadow:var(--shadow); font-size:0.95rem; }
        .toast.success { border-color: rgba(46,204,113,0.5); }
        .toast.error { border-color: rgba(231,76,60,0.5); }
        .toast .actions { margin-top:8px; display:flex; gap:8px; justify-content:flex-end; }
        .toast .btn-small { padding:0.35rem 0.7rem; border-radius:8px; border:none; cursor:pointer; font-weight:600; }
        .toast .btn-small.confirm { background:var(--accent); color:#fff; }
        .toast .btn-small.cancel { background:#4b5563; color:#fff; }
        @media (max-width:1200px) {
            h1 { text-align:center; }
            .page-header { flex-direction:column; align-items:center; justify-content:center; }
        .backdrop { position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9; display:none; pointer-events:none; }
        .backdrop.show { display:block; pointer-events:auto; }
            .layout { display:block; }
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
                z-index:11;
                padding:0.55rem 0.85rem;
                border-radius:10px;
                border:1px solid var(--border);
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
                padding: 1.1rem;
            }
            h1 {
                font-size: 1.75rem;
                text-align: center;
            }
            .item {
                flex-direction: row;
                align-items: center;
                gap: 0.75rem;
            }
            .item-name {
                flex: 1;
                min-width: 0;
            }
            .actions {
                flex-shrink: 0;
                margin-left: auto;
            }
            .actions .btn {
                padding: 0.5rem;
                min-width: 40px;
                width: 40px;
                height: 40px;
                justify-content: center;
            }
            .actions .btn .btn-text {
                display: none;
            }
            .actions .btn svg {
                width: 18px;
                height: 18px;
            }
            form[style*="display:flex"] {
                flex-direction: column;
            }
            form[style*="display:flex"] > * {
                width: 100%;
            }
            .input {
                font-size: 16px;
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
            .toggle {
                top: 8px;
                left: 8px;
                padding: 0.5rem 0.75rem;
            }
            .item {
                padding: 0.75rem;
            }
            .actions .btn {
                padding: 0.45rem;
                min-width: 38px;
                width: 38px;
                height: 38px;
            }
            .actions .btn svg {
                width: 16px;
                height: 16px;
            }
        }
    </style>
</head>
<body>
    <div class="layout">
        <!-- Sidebar будет загружен через JavaScript -->
        <main class="content">
            <div class="card">
                <div class="page-header">
                    <h1 style="margin:0;">Сет листы</h1>
                    <?php if ($isAdminOrModerator): ?>
                    <div style="display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center;">
                        <?php $showAll = $isAdminOrModerator && ($_GET['all'] ?? '') === '1'; ?>
                        <a class="btn ghost btn-icon<?php echo $showAll ? ' primary' : ''; ?>" href="/setlists.php<?php echo $showAll ? '' : '?all=1'; ?>" title="<?php echo $showAll ? 'Показать только мои' : 'Показать все'; ?>">
                            <?php echo renderIcon('user', 16, 16); ?>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
                <form method="POST" style="margin-bottom:1rem; display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
                    <input type="hidden" name="action" value="create">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                    <input class="input" name="name" placeholder="Название сет-листа" required style="flex:1; min-width:260px; margin:0;">
                    <button class="btn primary" type="submit">Создать</button>
                </form>
                <div class="list">
                    <?php if (empty($setlists)): ?>
                        <div class="item"><span class="item-name">Нет сет-листов</span></div>
                    <?php else: foreach ($setlists as $sl): ?>
                        <div class="item" data-id="<?php echo $sl['id']; ?>" data-owner="<?php echo (int)($sl['owner_id'] ?? 0); ?>" data-link="/setlist_view.php?id=<?php echo $sl['id']; ?>&mode=view">
                            <div style="flex:1; min-width:0;">
                                <span class="item-name" data-editable="1"><?php echo htmlspecialchars($sl['name']); ?></span>
                                <?php if ($isAdminOrModerator && !empty($sl['owner_username'])): ?>
                                    <div class="meta" style="margin-top:0.15rem; color:var(--muted); font-size:0.9rem;">
                                        Владелец: <?php echo htmlspecialchars($sl['owner_full_name'] ?: $sl['owner_username']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="actions" style="display:flex; gap:0.5rem; align-items:center;">
                                <?php 
                                    $isOwner = (int)($sl['owner_id'] ?? 0) === $userId;
                                    $isShared = !empty($sl['shared_for_user']) && !$isOwner;
                                    $canEdit = ($isAdminOrModerator || $isOwner) && !$isShared;
                                ?>
                                <?php if ($canEdit): ?>
                                <a class="btn ghost btn-icon" style="text-decoration:none;" href="/setlist_view.php?id=<?php echo $sl['id']; ?>&mode=view&from=setlists#share" title="Поделиться">
                                    <?php echo renderIcon('share', 16, 16); ?>
                                </a>
                                <?php endif; ?>
                                <?php if ($canEdit): ?>
                                <a class="btn ghost btn-icon" style="text-decoration:none;" href="/setlist_view.php?id=<?php echo $sl['id']; ?>&mode=edit" title="Редактировать">
                                    <?php echo renderIcon('settings', 16, 16); ?>
                                </a>
                                <?php endif; ?>
                                <?php if ($canEdit): ?>
                                <form method="POST" data-toast-confirm="Удалить сетлист?" style="margin:0;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $sl['id']; ?>">
                                    <button class="btn danger btn-icon" type="submit" title="Удалить">
                                        <?php echo renderIcon('trash', 16, 16); ?>
                                    </button>
                                </form>
                                <?php endif; ?>
                                <?php if ($isShared): ?>
                                <form method="POST" data-toast-confirm="Убрать из списка?" style="margin:0;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                    <input type="hidden" name="action" value="hide_shared">
                                    <input type="hidden" name="id" value="<?php echo $sl['id']; ?>">
                                    <button class="btn ghost btn-icon" type="submit" title="Скрыть">
                                        <?php echo renderIcon('close', 16, 16); ?>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </main>
    </div>
    <script>
        const csrfToken = '<?php echo htmlspecialchars($csrf); ?>';
        const currentUserId = <?php echo (int)$userId; ?>;
        document.querySelectorAll('[data-editable="1"]').forEach(el => {
            el.addEventListener('dblclick', () => {
                const item = el.closest('.item');
                if (!item) return;
                const ownerId = parseInt(item.dataset.owner || '0', 10);
                const isAdmin = <?php echo $isAdminOrModerator ? 'true' : 'false'; ?>;
                const canRename = isAdmin || (ownerId && ownerId === currentUserId);
                if (!canRename) return;
                const id = item.dataset.id;
                const val = prompt('Новое название:', el.textContent.trim());
                if (!val) return;
                fetch('/setlists.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ action: 'rename', id, name: val, csrf_token: csrfToken })
                }).then(r => r.json()).then(() => { el.textContent = val; });
            });
        });
        document.querySelectorAll('.item').forEach(el => {
            el.addEventListener('click', (e) => {
                if (e.target.closest('form') || e.target.tagName === 'BUTTON' || e.target.tagName === 'A') return;
                const link = el.dataset.link;
                if (link) window.location.href = link;
            });
        });
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
            document.querySelectorAll('form[data-toast-confirm]').forEach(form => {
                const msg = form.getAttribute('data-toast-confirm');
                form.addEventListener('submit', ev => {
                    if (form.dataset.confirming === '1') { form.dataset.confirming = ''; return; }
                    ev.preventDefault();
                    // Только одно toast-подтверждение
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

