<?php
require_once __DIR__ . '/../security.php';
require_once __DIR__ . '/../db.php';

ensure_session_started();
DB::init();

$mode = $_GET['mode'] ?? '';
$isCheck = $mode === 'check_exact';

// Для регистрации (check_exact) позволяем без авторизации, иначе требуем сессию
if (!$isCheck && (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true)) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode([]);
    exit;
}

$qRaw = trim($_GET['q'] ?? '');
// нормализуем: убираем t.me/ и ведущие @
$q = preg_replace('~^https?://t\\.me/~i', '', $qRaw);
$q = preg_replace('~^t\\.me/~i', '', $q);
$q = ltrim($q, "@ \t\n\r\0\x0B");
if ($q === '' || strlen($q) < 2) {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit;
}

if ($isCheck) {
    $tg = '@' . $q;
    $stmt = DB::getConnection()->prepare("SELECT id, username, full_name, telegram FROM users WHERE username IN (?, ?) OR telegram IN (?, ?)");
    $stmt->execute([$q, $tg, $tg, $q]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    header('Content-Type: application/json');
    echo json_encode($row ? [$row] : []);
    exit;
}
if ($q === '' || strlen($q) < 2) {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit;
}

try {
    $db = DB::getConnection();
    $like = '%' . $q . '%';
    $tgLike = '%@' . $q . '%';
    $stmt = $db->prepare("SELECT id, username, full_name, telegram FROM users WHERE username LIKE ? OR full_name LIKE ? OR telegram LIKE ? ORDER BY username LIMIT 10");
    $stmt->execute([$like, $like, $tgLike]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $out = array_map(function($r) {
        return [
            'id' => (int)$r['id'],
            'username' => $r['username'],
            'full_name' => $r['full_name'] ?? '',
            'telegram' => $r['telegram'] ?? ''
        ];
    }, $rows);
    header('Content-Type: application/json');
    echo json_encode($out);
} catch (Throwable $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([]);
}
