<?php
/**
 * Общий файл для проверки аутентификации
 * Подключается на страницах, требующих авторизации
 */

require_once __DIR__ . '/../security.php';
require_once __DIR__ . '/../db.php';

ensure_session_started();
DB::init();

if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    $redirect = $_SERVER['REQUEST_URI'] ?? '/';
    header('Location: /auth.php?redirect=' . urlencode($redirect));
    exit;
}

$username = $_SESSION['username'] ?? '';
if (!$username) {
    header('Location: /auth.php');
    exit;
}

// Проверяем активность пользователя (кроме страницы newer.php)
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($currentPath !== '/newer.php' && $currentPath !== '/logout.php' && $currentPath !== '/auth.php') {
    $db = DB::getConnection();
    $stmt = $db->prepare('SELECT active FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    
    // Если пользователь не активирован, редиректим на страницу ожидания
    if (!$user || !isset($user['active']) || (int)$user['active'] === 0) {
        header('Location: /newer.php');
        exit;
    }
}

// Возвращаем username для использования на страницах
return $username;
