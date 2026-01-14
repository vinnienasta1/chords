<?php
/**
 * API endpoint для получения HTML сайдбара
 * Возвращает HTML сайдбара с данными текущего пользователя
 */

require_once __DIR__ . '/../security.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/user_helper.php';
require_once __DIR__ . '/../includes/layout_helper.php';

ensure_session_started();
DB::init();

if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$username = $_SESSION['username'] ?? '';
if (!$username) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$userData = getCurrentUser($username);
$activePage = $_GET['activePage'] ?? '';

// Рендерим сайдбар в буфер
ob_start();
renderSidebar($userData, $activePage);
$sidebarHtml = ob_get_clean();

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'html' => $sidebarHtml,
    'userId' => isset($userData['user']) && isset($userData['user']['id']) ? (int)$userData['user']['id'] : null,
    'timestamp' => time()
]);
