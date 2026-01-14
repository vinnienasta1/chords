<?php
require_once __DIR__ . '/db.php';

DB::init();
$db = DB::getConnection();

$userId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($userId <= 0) {
    http_response_code(400);
    exit('Invalid user ID');
}

$stmt = $db->prepare('SELECT avatar_data, avatar_mime FROM users WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user || empty($user['avatar_data'])) {
    http_response_code(404);
    exit('Avatar not found');
}

header('Content-Type: ' . ($user['avatar_mime'] ?: 'image/jpeg'));
header('Cache-Control: public, max-age=31536000');
echo $user['avatar_data'];
