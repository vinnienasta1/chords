<?php
class DB {
    private static $db = null;

    public static function getConnection() {
        if (self::$db === null) {
            // Настройте переменные окружения или замените значения по умолчанию ниже
            $host = getenv('DB_HOST') ?: '127.0.0.1';
            $dbName = getenv('DB_NAME') ?: 'chords';
            $user = getenv('DB_USER') ?: 'chords';
            $pass = getenv('DB_PASS') ?: 'password';

            $dsn = "mysql:host={$host};dbname={$dbName};charset=utf8mb4";
            self::$db = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }
        return self::$db;
    }

    public static function init() {
        $db = self::getConnection();
        self::ensureTables($db);
        self::ensureColumns($db);
        self::ensureIndexes($db);
        reindexSetlistItems($db);
    }

    private static function ensureTables(PDO $db): void {
        $db->exec("CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(255) UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            is_admin TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $db->exec("CREATE TABLE IF NOT EXISTS songs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            artist VARCHAR(255),
            cap TEXT,
            first_note VARCHAR(50),
            skill_stars INT DEFAULT 0,
            popularity_stars INT DEFAULT 0,
            locale VARCHAR(10),
            lyrics LONGTEXT,
            comment TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $db->exec("CREATE TABLE IF NOT EXISTS chords (
            id INT AUTO_INCREMENT PRIMARY KEY,
            song_id INT NULL,
            chord_text TEXT NOT NULL,
            position INT DEFAULT 0,
            char_position INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_chords_song FOREIGN KEY (song_id) REFERENCES songs(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $db->exec("CREATE TABLE IF NOT EXISTS setlists (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $db->exec("CREATE TABLE IF NOT EXISTS setlist_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setlist_id INT NOT NULL,
            song_id INT NULL,
            block_index INT DEFAULT 1,
            position INT DEFAULT 0,
            checked TINYINT(1) DEFAULT 0,
            comment TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_setlist_items_setlist FOREIGN KEY (setlist_id) REFERENCES setlists(id) ON DELETE CASCADE,
            CONSTRAINT fk_setlist_items_song FOREIGN KEY (song_id) REFERENCES songs(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $db->exec("CREATE TABLE IF NOT EXISTS setlist_comments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setlist_id INT NOT NULL,
            comment TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_setlist_comments_setlist FOREIGN KEY (setlist_id) REFERENCES setlists(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        $db->exec("CREATE TABLE IF NOT EXISTS setlist_access (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setlist_id INT NOT NULL,
            user_id INT NOT NULL,
            can_edit TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_setlist_access_setlist FOREIGN KEY (setlist_id) REFERENCES setlists(id) ON DELETE CASCADE,
            CONSTRAINT fk_setlist_access_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY uniq_setlist_user (setlist_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        
        // История
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS hystory (
                id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT(11) NOT NULL,
                action VARCHAR(50) NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                entity_type VARCHAR(50) DEFAULT NULL,
                entity_id INT(11) DEFAULT NULL,
                old_values JSON DEFAULT NULL,
                new_values JSON DEFAULT NULL,
                changes TEXT DEFAULT NULL,
                description TEXT DEFAULT NULL,
                ip_address VARCHAR(45) DEFAULT NULL,
                user_agent TEXT DEFAULT NULL,
                request_url TEXT DEFAULT NULL,
                request_method VARCHAR(10) DEFAULT NULL,
                session_id VARCHAR(255) DEFAULT NULL,
                INDEX idx_user_id (user_id),
                INDEX idx_action (action),
                INDEX idx_created_at (created_at DESC),
                INDEX idx_entity (entity_type, entity_id),
                INDEX idx_ip_address (ip_address),
                CONSTRAINT fk_hystory_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (PDOException $e) {
            error_log('Hystory table creation warning: ' . $e->getMessage());
        }
    }

    private static function ensureColumns(PDO $db): void {
        self::addColumnIfMissing($db, "songs", "cap", "TEXT");
        self::addColumnIfMissing($db, "songs", "first_note", "VARCHAR(50)");
        self::addColumnIfMissing($db, "songs", "skill_stars", "INT DEFAULT 0");
        self::addColumnIfMissing($db, "songs", "popularity_stars", "INT DEFAULT 0");
        self::addColumnIfMissing($db, "songs", "locale", "VARCHAR(10)");
        self::addColumnIfMissing($db, "songs", "comment", "TEXT");
        self::addColumnIfMissing($db, "songs", "added_by", "INT NULL");
        self::addColumnIfMissing($db, "chords", "char_position", "INT DEFAULT 0");
        self::addColumnIfMissing($db, "setlists", "owner_id", "INT NULL");
        self::addColumnIfMissing($db, "setlists", "is_public", "TINYINT(1) DEFAULT 0");
        self::addColumnIfMissing($db, "setlists", "share_token", "VARCHAR(64) DEFAULT NULL");
        self::addColumnIfMissing($db, "setlists", "share_can_edit", "TINYINT(1) DEFAULT 0");
        self::addColumnIfMissing($db, "setlist_items", "comment", "TEXT DEFAULT NULL");
        // Профиль пользователя
        self::addColumnIfMissing($db, "users", "full_name", "VARCHAR(255) DEFAULT ''");
        self::addColumnIfMissing($db, "users", "avatar_path", "VARCHAR(255) DEFAULT NULL");
        // Аватар в БД
        self::addColumnIfMissing($db, "users", "avatar_data", "LONGBLOB DEFAULT NULL");
        self::addColumnIfMissing($db, "users", "avatar_mime", "VARCHAR(50) DEFAULT NULL");
        // Активность пользователя
        self::addColumnIfMissing($db, "users", "active", "TINYINT(1) DEFAULT 0");
        // Telegram
        self::addColumnIfMissing($db, "users", "telegram", "VARCHAR(255) DEFAULT NULL");
        self::addColumnIfMissing($db, "users", "telegram_user_id", "BIGINT DEFAULT NULL");
        self::addColumnIfMissing($db, "users", "verification_code", "VARCHAR(20) DEFAULT NULL");
        self::addColumnIfMissing($db, "users", "verified", "TINYINT(1) DEFAULT 0");
    }

    private static function ensureIndexes(PDO $db): void {
        if (!self::indexExists($db, "songs", "idx_songs_artist")) {
            $db->exec("CREATE INDEX idx_songs_artist ON songs(artist)");
        }
        if (!self::indexExists($db, "chords", "idx_chords_song_id")) {
            $db->exec("CREATE INDEX idx_chords_song_id ON chords(song_id)");
        }
    }

    private static function addColumnIfMissing(PDO $db, string $table, string $column, string $definition): void {
        if (!self::columnExists($db, $table, $column)) {
            $db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    }

    private static function columnExists(PDO $db, string $table, string $column): bool {
        $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private static function indexExists(PDO $db, string $table, string $index): bool {
        $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?");
        $stmt->execute([$table, $index]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

function reindexSetlist(PDO $db, int $setlistId): void {
    $stmt = $db->prepare("SELECT id FROM setlist_items WHERE setlist_id=? ORDER BY block_index, position, id");
    $stmt->execute([$setlistId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $pos = 1;
    foreach ($items as $row) {
        $upd = $db->prepare("UPDATE setlist_items SET position=? WHERE id=?");
        $upd->execute([$pos++, $row['id']]);
    }
}

function reindexSetlistItems(PDO $db): void {
    $ids = $db->query("SELECT id FROM setlists")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $sid) {
        reindexSetlist($db, (int)$sid);
    }
}
