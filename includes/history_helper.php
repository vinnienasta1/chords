<?php
/**
 * Хелпер для работы с историей изменений
 */

/**
 * Логирует событие в историю
 * @param string $action Тип действия (CREATE, UPDATE, DELETE, ACTIVATE, DEACTIVATE и т.д.)
 * @param string|null $entityType Тип сущности (songs, users, setlists и т.д.)
 * @param int|null $entityId ID сущности
 * @param array|null $oldValues Старые значения (JSON)
 * @param array|null $newValues Новые значения (JSON)
 * @param string|null $changes Текстовое описание изменений
 * @param string|null $description Дополнительное описание
 */
function logHistory(
    string $action,
    ?string $entityType = null,
    ?int $entityId = null,
    ?array $oldValues = null,
    ?array $newValues = null,
    ?string $changes = null,
    ?string $description = null
): void {
    try {
        require_once __DIR__ . '/../security.php';
        require_once __DIR__ . '/../db.php';
        
        ensure_session_started();
        
        // Получаем user_id из username в сессии
        $username = $_SESSION['username'] ?? null;
        if (!$username) {
            error_log('HISTORY ERROR: username not found in session');
            return;
        }
        
        // Убеждаемся, что таблица hystory создана
        DB::init();
        
        $db = DB::getConnection();
        
        // Проверяем существование таблицы hystory
        try {
            $tableCheck = $db->query("SHOW TABLES LIKE 'hystory'")->fetch();
            if (!$tableCheck) {
                error_log('HISTORY ERROR: hystory table does not exist!');
                return;
            }
        } catch (Throwable $e) {
            error_log('HISTORY ERROR: Error checking hystory table: ' . $e->getMessage());
            return;
        }
        
        // Получаем user_id из БД
        try {
            $userStmt = $db->prepare('SELECT id FROM users WHERE username = ?');
            $userStmt->execute([$username]);
            $user = $userStmt->fetch();
            
            if (!$user) {
                error_log('HISTORY ERROR: user not found for username: ' . $username);
                return;
            }
            
            $userId = (int)$user['id'];
            error_log("HISTORY DEBUG: user_id={$userId}, username={$username}, action={$action}");
        } catch (Throwable $e) {
            error_log('HISTORY ERROR: Error getting user_id: ' . $e->getMessage());
            return;
        }
        
        // Получаем IP адрес
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ipAddress = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        }
        
        // Получаем user agent
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        // Получаем request URL
        $requestUrl = $_SERVER['REQUEST_URI'] ?? null;
        
        // Получаем request method
        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        
        // Получаем session ID
        $sessionId = session_id();
        
        // Подготавливаем JSON значения
        $oldValuesJson = $oldValues ? json_encode($oldValues, JSON_UNESCAPED_UNICODE) : null;
        $newValuesJson = $newValues ? json_encode($newValues, JSON_UNESCAPED_UNICODE) : null;
        
        try {
            error_log("HISTORY DEBUG: About to insert - action={$action}, entityType={$entityType}, entityId={$entityId}");
            
            $stmt = $db->prepare('
                INSERT INTO hystory (
                    user_id, action, entity_type, entity_id,
                    old_values, new_values, changes, description,
                    ip_address, user_agent, request_url, request_method, session_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            
            $result = $stmt->execute([
                $userId,
                $action,
                $entityType,
                $entityId,
                $oldValuesJson,
                $newValuesJson,
                $changes,
                $description,
                $ipAddress,
                $userAgent,
                $requestUrl,
                $requestMethod,
                $sessionId
            ]);
            
            if (!$result) {
                $errorInfo = $stmt->errorInfo();
                error_log('HISTORY ERROR: Insert failed: ' . implode(', ', $errorInfo));
            } else {
                $insertId = $db->lastInsertId();
                error_log("HISTORY SUCCESS: Record inserted with id={$insertId}");
            }
        } catch (PDOException $e) {
            error_log('HISTORY ERROR: PDO error: ' . $e->getMessage());
            error_log('HISTORY ERROR: PDO error code: ' . $e->getCode());
            error_log('HISTORY ERROR: SQLSTATE: ' . $e->getCode());
        } catch (Throwable $e) {
            error_log('HISTORY ERROR: Insert error: ' . $e->getMessage());
            error_log('HISTORY ERROR: Trace: ' . $e->getTraceAsString());
        }
    } catch (Throwable $e) {
        // Логируем ошибки подробно
        error_log('Hystory log error: ' . $e->getMessage());
        error_log('Hystory log error trace: ' . $e->getTraceAsString());
    }
}

/**
 * Получает историю событий
 * @param int $limit Ограничение количества записей
 * @return array Массив событий
 */
function getHistory(int $limit = 100): array {
    try {
        $db = DB::getConnection();
        
        // Проверяем существование таблицы hystory
        $tableCheck = $db->query("SHOW TABLES LIKE 'hystory'")->fetch();
        if (!$tableCheck) {
            // Таблица не существует, возвращаем пустой массив
            error_log('HISTORY ERROR: hystory table does not exist in getHistory');
            return [];
        }
        
        error_log("HISTORY DEBUG: Fetching history records, limit={$limit}");
        
        // Используем простой запрос без JOIN для максимальной надежности
        // Затем загружаем связанные данные отдельно
        // ВАЖНО: Для LIMIT в MySQL/MariaDB нужно использовать именованный параметр с PDO::PARAM_INT
        $stmt = $db->prepare('SELECT * FROM hystory ORDER BY created_at DESC LIMIT :limit');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("HISTORY DEBUG: Simple query returned " . count($result) . " raw records");
        
        // Загружаем связанные данные для каждой записи
        if (count($result) > 0) {
            foreach ($result as &$row) {
                // Получаем данные пользователя отдельно
                if (!empty($row['user_id'])) {
                    try {
                        $userStmt = $db->prepare('SELECT username, full_name, is_admin, avatar_data FROM users WHERE id = ?');
                        $userStmt->execute([$row['user_id']]);
                        $user = $userStmt->fetch();
                        if ($user) {
                            $row['username'] = $user['username'];
                            $row['full_name'] = $user['full_name'];
                            $row['is_admin'] = $user['is_admin'];
                            $row['avatar_data'] = $user['avatar_data'];
                        }
                    } catch (Throwable $e) {
                        error_log('HISTORY WARNING: Failed to fetch user data: ' . $e->getMessage());
                    }
                }
                
                // Получаем данные песни отдельно
                if (!empty($row['entity_type']) && $row['entity_type'] === 'songs' && !empty($row['entity_id'])) {
                    try {
                        $songStmt = $db->prepare('SELECT title, artist FROM songs WHERE id = ?');
                        $songStmt->execute([$row['entity_id']]);
                        $song = $songStmt->fetch();
                        if ($song) {
                            $row['song_title'] = $song['title'];
                            $row['song_artist'] = $song['artist'];
                        }
                    } catch (Throwable $e) {
                        error_log('HISTORY WARNING: Failed to fetch song data: ' . $e->getMessage());
                    }
                }
                
                // Получаем данные целевого пользователя отдельно
                if (!empty($row['entity_type']) && $row['entity_type'] === 'users' && !empty($row['entity_id'])) {
                    try {
                        $user2Stmt = $db->prepare('SELECT username, full_name, is_admin, avatar_data FROM users WHERE id = ?');
                        $user2Stmt->execute([$row['entity_id']]);
                        $user2 = $user2Stmt->fetch();
                        if ($user2) {
                            $row['entity_username'] = $user2['username'];
                            $row['entity_full_name'] = $user2['full_name'];
                            $row['entity_is_admin'] = $user2['is_admin'];
                            $row['entity_avatar_data'] = $user2['avatar_data'];
                        }
                    } catch (Throwable $e) {
                        error_log('HISTORY WARNING: Failed to fetch entity user data: ' . $e->getMessage());
                    }
                }
                
                // Декодируем JSON значения
                if (!empty($row['old_values']) && is_string($row['old_values'])) {
                    $decoded = json_decode($row['old_values'], true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $row['old_values'] = $decoded;
                    } else {
                        error_log('HISTORY WARNING: Failed to decode old_values for record id=' . ($row['id'] ?? 'unknown') . ': ' . json_last_error_msg());
                    }
                }
                if (!empty($row['new_values']) && is_string($row['new_values'])) {
                    $decoded = json_decode($row['new_values'], true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $row['new_values'] = $decoded;
                    } else {
                        error_log('HISTORY WARNING: Failed to decode new_values for record id=' . ($row['id'] ?? 'unknown') . ': ' . json_last_error_msg());
                    }
                }
            }
            error_log("HISTORY DEBUG: After loading related data, we have " . count($result) . " processed records");
        }
        
        error_log("HISTORY DEBUG: Returning " . count($result) . " final records");
        
        return $result;
    } catch (Throwable $e) {
        // Логируем ошибку подробно
        error_log('HISTORY ERROR: getHistory error: ' . $e->getMessage());
        error_log('HISTORY ERROR: getHistory trace: ' . $e->getTraceAsString());
        return [];
    }
}
