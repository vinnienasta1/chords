<?php
// Общие утилиты безопасности: безопасный запуск сессии и CSRF

function ensure_session_started(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'httponly' => true,
        'secure'   => $secure,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function csrf_token(): string {
    ensure_session_started();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function require_csrf(): void {
    ensure_session_started();
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if (!$token || !$sessionToken || !hash_equals($sessionToken, $token)) {
        http_response_code(400);
        exit('Bad CSRF token');
    }
}

function is_safe_redirect(string $url): bool {
    if ($url === '') return false;
    // Разрешаем только относительные пути вида "/..."; запрещаем схемы и протокол-relative
    return $url[0] === '/' && (strlen($url) === 1 || $url[1] !== '/');
}
