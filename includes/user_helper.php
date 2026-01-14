<?php
/**
 * Хелпер для работы с пользователями
 * Унифицирует загрузку данных пользователя на всех страницах
 */

/**
 * Получает данные текущего авторизованного пользователя
 * @param string $username Имя пользователя из сессии
 * @return array Массив с данными пользователя и вспомогательными полями
 */
function getCurrentUser(string $username): array {
    $db = DB::getConnection();
    $stmt = $db->prepare('SELECT id, username, full_name, is_admin, avatar_data FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    
    if (!$user) {
        return [
            'user' => null,
            'isAdmin' => false,
            'hasAvatar' => false,
            'avatarUrl' => null,
            'displayName' => $username,
            'initial' => strtoupper(substr($username, 0, 1) ?: 'G')
        ];
    }
    
    $fullName = trim($user['full_name'] ?? '');
    $hasAvatar = !empty($user['avatar_data']);
    
    return [
        'user' => $user,
        'isAdmin' => (int)$user['is_admin'] === 1,
        'isModerator' => (int)$user['is_admin'] === 2,
        'isAdminOrModerator' => (int)$user['is_admin'] === 1 || (int)$user['is_admin'] === 2,
        'hasAvatar' => $hasAvatar,
        'avatarUrl' => $hasAvatar ? '/avatar.php?id=' . (int)$user['id'] . '&t=' . time() : null,
        'displayName' => $fullName !== '' ? $fullName : $username,
        'initial' => strtoupper(substr($username, 0, 1) ?: 'G')
    ];
}
