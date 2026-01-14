<?php
/**
 * Расширенные функции для работы с аутентификацией и авторизацией
 */

/**
 * Проверяет, является ли текущий пользователь администратором
 * @return bool
 */
function isCurrentUserAdmin(): bool {
    ensure_session_started();
    if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
        return false;
    }
    
    $username = $_SESSION['username'] ?? '';
    if (!$username) return false;
    
    $db = DB::getConnection();
    $stmt = $db->prepare('SELECT is_admin FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    
    return $user && (int)$user['is_admin'] === 1;
}

/**
 * Требует права администратора, иначе редирект на главную
 * @param string $redirectTo URL для редиректа при отсутствии прав
 */
function requireAdmin(string $redirectTo = '/'): void {
    if (!isCurrentUserAdmin()) {
        header('Location: ' . $redirectTo);
        exit;
    }
}

/**
 * Получает ID текущего пользователя
 * @return int|null
 */
function getCurrentUserId(): ?int {
    ensure_session_started();
    if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
        return null;
    }
    
    $username = $_SESSION['username'] ?? '';
    if (!$username) return null;
    
    $db = DB::getConnection();
    $stmt = $db->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    
    return $user ? (int)$user['id'] : null;
}

/**
 * Проверяет, является ли указанный пользователь текущим
 * @param int $userId ID пользователя для проверки
 * @return bool
 */
function isCurrentUser(int $userId): bool {
    $currentUserId = getCurrentUserId();
    return $currentUserId !== null && $currentUserId === $userId;
}
