<?php
/**
 * Libre Claude - Base de Données SQLite (Hostinger Compatible)
 */

require_once dirname(__FILE__) . '/config.php';

$databaseConfigurations = (object) [
    'file' => DB_FILE,
    'pragmas' => [
        'journal_mode' => 'WAL',
        'synchronous'  => 'NORMAL',
        'cache_size'   => 5000,
        'temp_store'   => 'MEMORY',
    ],
];

class Database {
    private static $instance = null;
    private $pdo;

    private function __construct() {
        global $databaseConfigurations;

        try {
            $this->pdo = new PDO('sqlite:' . $databaseConfigurations->file);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            foreach ($databaseConfigurations->pragmas as $name => $value) {
                $this->pdo->exec("PRAGMA $name=$value");
            }
            $this->initTables();
        } catch (PDOException $e) {
            libreclaude_log("DB error: " . $e->getMessage(), 1);
            throw $e;
        }
    }

    public static function getInstance() {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function initTables() {
        $sql = "
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            email TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            mistral_api_key TEXT,
            role TEXT DEFAULT 'user',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_login DATETIME,
            login_attempts INTEGER DEFAULT 0,
            locked_until DATETIME,
            settings TEXT DEFAULT '{}'
        );
        CREATE TABLE IF NOT EXISTS sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            token TEXT UNIQUE NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            last_activity DATETIME DEFAULT CURRENT_TIMESTAMP,
            ip_address TEXT,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS conversations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            title TEXT,
            model_used TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            is_archived INTEGER DEFAULT 0,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            conversation_id INTEGER NOT NULL,
            role TEXT NOT NULL,
            content TEXT NOT NULL,
            model_used TEXT,
            tokens_used INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS app_settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS api_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            token_hash TEXT UNIQUE NOT NULL,
            prefix TEXT NOT NULL,
            last_four TEXT NOT NULL,
            is_active INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_used_at DATETIME,
            expires_at DATETIME,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS workspace_files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            language TEXT DEFAULT 'text',
            content TEXT NOT NULL,
            source_conversation_id INTEGER,
            github_path TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS workspace_github (
            user_id INTEGER PRIMARY KEY,
            repo_url TEXT,
            owner TEXT,
            repo TEXT,
            branch TEXT DEFAULT 'main',
            token TEXT,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS user_memories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            scope TEXT DEFAULT 'general',
            content TEXT NOT NULL,
            key_hash TEXT NOT NULL,
            source_conversation_id INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_used_at DATETIME,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
        CREATE INDEX IF NOT EXISTS idx_sessions_token ON sessions(token);
        CREATE INDEX IF NOT EXISTS idx_conv_user ON conversations(user_id);
        CREATE INDEX IF NOT EXISTS idx_msg_conv ON messages(conversation_id);
        CREATE INDEX IF NOT EXISTS idx_api_tokens_hash ON api_tokens(token_hash);
        CREATE INDEX IF NOT EXISTS idx_workspace_files_user ON workspace_files(user_id, updated_at);
        CREATE UNIQUE INDEX IF NOT EXISTS idx_user_memories_hash ON user_memories(user_id, scope, key_hash);
        CREATE INDEX IF NOT EXISTS idx_user_memories_user ON user_memories(user_id, scope, updated_at);
        ";
        foreach (explode(';', $sql) as $q) {
            $q = trim($q);
            if ($q) $this->pdo->exec($q);
        }
    }

    public function query($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function fetch($sql, $params = []) {
        return $this->query($sql, $params)->fetch();
    }

    public function fetchAll($sql, $params = []) {
        return $this->query($sql, $params)->fetchAll();
    }

    public function insert($table, $data) {
        $cols = implode(', ', array_keys($data));
        $vals = implode(', ', array_fill(0, count($data), '?'));
        $this->query("INSERT INTO $table ($cols) VALUES ($vals)", array_values($data));
        return $this->pdo->lastInsertId();
    }

    public function update($table, $data, $where, $whereParams = []) {
        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($data)));
        $this->query("UPDATE $table SET $set WHERE $where", array_merge(array_values($data), $whereParams));
    }

    public function delete($table, $where, $params = []) {
        $this->query("DELETE FROM $table WHERE $where", $params);
    }

    public function isInstalled() {
        $row = $this->fetch("SELECT COUNT(*) AS total FROM users");
        return ((int)($row['total'] ?? 0)) > 0;
    }

    public function getSetting($key, $default = null) {
        $row = $this->fetch("SELECT value FROM app_settings WHERE key = ?", [$key]);
        return $row ? $row['value'] : $default;
    }

    public function setSetting($key, $value) {
        $this->query(
            "INSERT OR REPLACE INTO app_settings (key, value, updated_at) VALUES (?, ?, datetime('now'))",
            [$key, $value]
        );
    }

    public function beginTransaction() { $this->pdo->beginTransaction(); }
    public function commit() { $this->pdo->commit(); }
    public function rollback() { $this->pdo->rollBack(); }
}
