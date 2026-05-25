<?php
/**
 * Libre Claude - Authentification (Hostinger compatible)
 */

require_once dirname(__FILE__) . '/database.php';

class Auth {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function register($username, $email, $password) {
        if (strlen($password) < PASSWORD_MIN_LENGTH) {
            return ['success' => false, 'error' => 'Mot de passe trop court (min ' . PASSWORD_MIN_LENGTH . ' caractères)'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Email invalide'];
        }

        $existing = $this->db->fetch(
            "SELECT id FROM users WHERE username = ? OR email = ?",
            [$username, $email]
        );
        if ($existing) {
            return ['success' => false, 'error' => 'Ce nom d\'utilisateur ou email existe déjà'];
        }

        try {
            $userId = $this->db->insert('users', [
                'username'      => $username,
                'email'         => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
            libreclaude_log("New user registered: $username (id=$userId)", 3);
            return ['success' => true, 'user_id' => $userId];
        } catch (Exception $e) {
            libreclaude_log("Register error: " . $e->getMessage(), 1);
            return ['success' => false, 'error' => 'Erreur lors de l\'inscription'];
        }
    }

    public function login($username, $password) {
        $user = $this->db->fetch(
            "SELECT * FROM users WHERE username = ? OR email = ?",
            [$username, $username]
        );

        if (!$user) {
            return ['success' => false, 'error' => 'Identifiants invalides'];
        }

        // Vérifier verrou
        if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            $mins = ceil((strtotime($user['locked_until']) - time()) / 60);
            return ['success' => false, 'error' => "Compte verrouillé pour $mins minutes"];
        }

        if (!password_verify($password, $user['password_hash'])) {
            $attempts = $user['login_attempts'] + 1;
            $locked   = null;
            if ($attempts >= MAX_LOGIN_ATTEMPTS) {
                $locked   = date('Y-m-d H:i:s', time() + LOCKOUT_TIME);
                $attempts = 0;
            }
            $this->db->update('users', [
                'login_attempts' => $attempts,
                'locked_until'   => $locked,
            ], 'id = ?', [$user['id']]);
            return ['success' => false, 'error' => 'Identifiants invalides'];
        }

        // Reset tentatives
        $this->db->update('users', [
            'login_attempts' => 0,
            'locked_until'   => null,
            'last_login'     => date('Y-m-d H:i:s'),
        ], 'id = ?', [$user['id']]);

        // Créer session
        $token     = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);

        $this->db->insert('sessions', [
            'user_id'    => $user['id'],
            'token'      => $token,
            'expires_at' => $expiresAt,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);

        $_SESSION['user_id']       = $user['id'];
        $_SESSION['session_token'] = $token;
        $_SESSION['username']      = $user['username'];

        libreclaude_log("User logged in: {$user['username']}", 3);
        return ['success' => true, 'user' => $user];
    }

    public function logout() {
        if (isset($_SESSION['session_token'])) {
            $this->db->delete('sessions', 'token = ?', [$_SESSION['session_token']]);
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public function isAuthenticated() {
        if (empty($_SESSION['user_id']) || empty($_SESSION['session_token'])) return false;

        $session = $this->db->fetch(
            "SELECT id FROM sessions WHERE token = ? AND user_id = ? AND expires_at > datetime('now')",
            [$_SESSION['session_token'], $_SESSION['user_id']]
        );
        if (!$session) return false;

        $this->db->update('sessions', ['last_activity' => date('Y-m-d H:i:s')], 'id = ?', [$session['id']]);
        return true;
    }

    public function getCurrentUser() {
        if (!$this->isAuthenticated()) return null;
        return $this->db->fetch("SELECT * FROM users WHERE id = ?", [$_SESSION['user_id']]);
    }

    public function getUserByApiToken($token) {
        if (!preg_match('/^lc_sk_[A-Fa-f0-9]{64}$/', $token)) return null;

        $hash = hash('sha256', $token);
        $row = $this->db->fetch(
            "SELECT t.id AS token_id, t.user_id, u.*
             FROM api_tokens t
             JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = ?
               AND t.is_active = 1
               AND (t.expires_at IS NULL OR t.expires_at > datetime('now'))",
            [$hash]
        );
        if (!$row) return null;

        $this->db->update('api_tokens', ['last_used_at' => date('Y-m-d H:i:s')], 'id = ?', [$row['token_id']]);
        return $row;
    }

    public function updateApiKey($userId, $apiKey) {
        $this->db->update('users', ['mistral_api_key' => trim($apiKey)], 'id = ?', [$userId]);
        return ['success' => true];
    }

    public function updateSettings($userId, $settings) {
        $current = $this->db->fetch("SELECT settings FROM users WHERE id = ?", [$userId]);
        $data = json_decode($current['settings'] ?? '{}', true);
        if (!is_array($data)) $data = [];

        $data = array_merge($data, $settings);
        $this->db->update('users', ['settings' => json_encode($data)], 'id = ?', [$userId]);
        return ['success' => true];
    }
}
