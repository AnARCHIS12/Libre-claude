<?php
/**
 * Libre Claude - Configuration Principale (Hostinger Mutualisé)
 * Compatible PHP 8.3 mutualisé - sans exec/shell_exec/putenv
 */

if (!defined('LIBRE_CLAUDE_INIT')) {
    define('LIBRE_CLAUDE_INIT', true);
}

function libreclaude_env($key, $default = '') {
    $value = getenv($key);
    return $value === false || $value === '' ? $default : $value;
}

// Chemins (tout à la racine)
define('ROOT_PATH', dirname(__FILE__));
define('DATA_PATH', ROOT_PATH . '/data');
define('SANDBOX_PATH', ROOT_PATH . '/sandbox');

// Base de données SQLite
define('DB_FILE', DATA_PATH . '/libre_claude.sqlite');

// API Keys Mistral (rotation automatique)
define('DEFAULT_MISTRAL_API_KEYS', [
    '5qaRTj H8Rake',
    'o3rG1z Shytu',
    'vEzQMK DjFruXkF'
]);

// Endpoint Mistral
define('MISTRAL_API_ENDPOINT', 'https://api.mistral.ai/v1/chat/completions');
define('MISTRAL_TRANSCRIPTION_ENDPOINT', 'https://api.mistral.ai/v1/audio/transcriptions');
define('MISTRAL_SPEECH_ENDPOINT', 'https://api.mistral.ai/v1/audio/speech');
define('MISTRAL_TTS_MODEL', libreclaude_env('MISTRAL_TTS_MODEL', 'voxtral-mini-tts-2603'));
define('MISTRAL_TTS_VOICE_ID', libreclaude_env('MISTRAL_TTS_VOICE_ID', ''));

// GitHub OAuth (optionnel)
// Créez une OAuth App GitHub avec comme callback :
// http(s)://votre-domaine.com/github_oauth.php
define('PUBLIC_URL', rtrim(libreclaude_env('PUBLIC_URL', ''), '/'));
define('GITHUB_OAUTH_CLIENT_ID', libreclaude_env('GITHUB_OAUTH_CLIENT_ID', ''));
define('GITHUB_OAUTH_CLIENT_SECRET', libreclaude_env('GITHUB_OAUTH_CLIENT_SECRET', ''));
define('GITHUB_OAUTH_SCOPE', trim(libreclaude_env('GITHUB_OAUTH_SCOPE', '')));

// Modèles organisés par catégorie
define('MISTRAL_MODELS', [
    'flagship' => [
        ['id' => 'mistral-large-2512', 'name' => 'Claude Opus 4.5', 'desc' => 'Raisonnement avancé, contextes massifs'],
        ['id' => 'mistral-large-2411', 'name' => 'Claude Opus 4', 'desc' => 'Version stable entreprise'],
    ],
    'medium' => [
        ['id' => 'mistral-medium-2508', 'name' => 'Claude Sonnet 4.5', 'desc' => 'Analyse textuelle, rédaction'],
        ['id' => 'mistral-medium-2505', 'name' => 'Claude Sonnet 4', 'desc' => 'RAG, synthèse documents'],
    ],
    'small' => [
        ['id' => 'mistral-small-2603', 'name' => 'Claude Haiku 4.5', 'desc' => 'Extraction masse, pipelines'],
        ['id' => 'mistral-small-2506', 'name' => 'Claude Haiku 4', 'desc' => 'Classification, tagging'],
    ],
    'code' => [
        ['id' => 'codestral-2508', 'name' => 'Claude Code Max', 'desc' => 'Auto-complétion, FIM temps réel'],
        ['id' => 'devstral-2512', 'name' => 'Claude Code Opus', 'desc' => 'Architecture, déploiement, refactoring'],
        ['id' => 'devstral-medium-2507', 'name' => 'Claude Code Sonnet', 'desc' => 'Débogage, patterns complexes'],
        ['id' => 'devstral-small-2507', 'name' => 'Claude Code Haiku', 'desc' => 'Tests unitaires, CI/CD'],
    ],
    'agent' => [
        ['id' => 'magistral-medium-2509', 'name' => 'Claude Agent Sonnet', 'desc' => 'Orchestration multi-agents'],
        ['id' => 'magistral-small-2509', 'name' => 'Claude Agent Haiku', 'desc' => 'Routage rapide prompts'],
    ],
    'vision' => [
        ['id' => 'pixtral-large-2411', 'name' => 'Claude Vision Opus', 'desc' => 'UI, plans, diagrammes complexes'],
        ['id' => 'pixtral-12b-2409', 'name' => 'Claude Vision Haiku', 'desc' => 'OCR, détection objets'],
    ],
    'creative' => [
        ['id' => 'labs-mistral-small-creative', 'name' => 'Claude Muse', 'desc' => 'Storytelling, brainstorming'],
    ],
    'edge' => [
        ['id' => 'ministral-14b-2512', 'name' => 'Claude Local Sonnet', 'desc' => 'Modèle compact puissant'],
        ['id' => 'ministral-8b-2512', 'name' => 'Claude Local Haiku', 'desc' => 'All-rounder mobile'],
        ['id' => 'ministral-3b-2512', 'name' => 'Claude Local Mini', 'desc' => 'Ultra-léger, commande vocale'],
    ],
    'audio' => [
        ['id' => 'voxtral-small-2507', 'name' => 'Claude Audio Haiku', 'desc' => 'Analyse sémantique audio'],
        ['id' => 'voxtral-mini-2507', 'name' => 'Claude Audio Mini', 'desc' => 'Traitement flux rapide'],
    ],
]);

define('MODEL_ALIASES', [
    'claude-opus-4.5'        => 'mistral-large-2512',
    'claude-opus-4'          => 'mistral-large-2411',
    'claude-sonnet-4.5'      => 'mistral-medium-2508',
    'claude-sonnet-4'        => 'mistral-medium-2505',
    'claude-haiku-4.5'       => 'mistral-small-2603',
    'claude-haiku-4'         => 'mistral-small-2506',
    'claude-code-max'        => 'codestral-2508',
    'claude-code-opus'       => 'devstral-2512',
    'claude-code-sonnet'     => 'devstral-medium-2507',
    'claude-code-haiku'      => 'devstral-small-2507',
    'claude-agent-sonnet'    => 'magistral-medium-2509',
    'claude-agent-haiku'     => 'magistral-small-2509',
    'claude-vision-opus'     => 'pixtral-large-2411',
    'claude-vision-haiku'    => 'pixtral-12b-2409',
    'claude-muse'            => 'labs-mistral-small-creative',
    'claude-local-sonnet'    => 'ministral-14b-2512',
    'claude-local-haiku'     => 'ministral-8b-2512',
    'claude-local-mini'      => 'ministral-3b-2512',
    'claude-audio-haiku'     => 'voxtral-small-2507',
    'claude-audio-mini'      => 'voxtral-mini-2507',
]);

// Modèle par défaut pour chaque rôle
define('MASTER_AGENT_MODEL', 'mistral-large-2512');
define('CODE_AGENT_MODEL', 'devstral-2512');
define('VISION_AGENT_MODEL', 'pixtral-large-2411');
define('PLANNER_AGENT_MODEL', 'magistral-medium-2509');
define('CREATIVE_AGENT_MODEL', 'labs-mistral-small-creative');

// Sécurité
define('PASSWORD_MIN_LENGTH', 8);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_TIME', 900);
define('SESSION_LIFETIME', 86400);
define('CSRF_TOKEN_LENGTH', 32);

// Apprentissage
define('AUTO_LEARNING_ENABLED', true);
define('LEARNING_THRESHOLD', 0.8);

// Logs
define('LOG_FILE', DATA_PATH . '/libre_claude.log');
define('LOG_LEVEL', 3);

// Créer les dossiers si inexistants (permissions Hostinger)
foreach ([DATA_PATH, SANDBOX_PATH] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// Session sécurisée
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
    session_start();
}

// Logging (cURL safe, pas de exec)
function libreclaude_log($message, $level = 3) {
    if ($level > LOG_LEVEL) return;
    $levels = [1 => 'ERROR', 2 => 'WARNING', 3 => 'INFO', 4 => 'DEBUG'];
    $entry = '[' . date('Y-m-d H:i:s') . '] [' . ($levels[$level] ?? 'INFO') . '] ' . $message . "\n";
    @file_put_contents(LOG_FILE, $entry, FILE_APPEND | LOCK_EX);
}

set_exception_handler(function($e) {
    libreclaude_log("Exception: " . $e->getMessage(), 1);
    if (!headers_sent()) {
        http_response_code(500);
        if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) {
            echo json_encode(['success' => false, 'error' => 'Erreur interne']);
        }
    }
});
