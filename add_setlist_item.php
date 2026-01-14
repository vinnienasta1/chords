<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/db.php';
ensure_session_started();
DB::init();

header('Content-Type: application/json');
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'auth']);
    exit;
}

require_csrf();

$username = $_SESSION['username'] ?? '';
$db = DB::getConnection();
$stmt = $db->prepare("SELECT is_admin FROM users WHERE username=?");
$stmt->execute([$username]);
$row = $stmt->fetch();
if (!$row || (int)$row['is_admin'] !== 1) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'forbidden']);
    exit;
}

$setlistId = (int)($_POST['setlist_id'] ?? 0);
$songId = (int)($_POST['song_id'] ?? 0);
$blockIndex = isset($_POST['block_index']) ? (int)$_POST['block_index'] : 0;
if ($setlistId <= 0 || $songId <= 0) {
    echo json_encode(['ok'=>false,'error'=>'bad_params']); exit;
}

$db->beginTransaction();
$block = $blockIndex > 0 ? $blockIndex : null;
if ($block === null) {
    $blockStmt = $db->prepare("SELECT COALESCE(MAX(block_index),1) AS blk FROM setlist_items WHERE setlist_id=?");
    $blockStmt->execute([$setlistId]);
    $block = (int)$blockStmt->fetch()['blk'];
}
$posStmt = $db->prepare("SELECT COALESCE(MAX(position),0)+1 AS pos FROM setlist_items WHERE setlist_id=? AND block_index=? AND song_id<>0");
$posStmt->execute([$setlistId, $block]);
$pos = (int)$posStmt->fetch()['pos'];
$ins = $db->prepare("INSERT INTO setlist_items(setlist_id, song_id, block_index, position) VALUES(?,?,?,?)");
$ins->execute([$setlistId, $songId, $block, $pos]);
$db->commit();
echo json_encode(['ok'=>true]);

