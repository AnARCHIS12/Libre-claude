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
define('MISTRAL_CONVERSATIONS_ENDPOINT', 'https://api.mistral.ai/v1/conversations');
define('MISTRAL_AGENTS_ENDPOINT', 'https://api.mistral.ai/v1/agents');
define('MISTRAL_FILES_ENDPOINT', 'https://api.mistral.ai/v1/files');
define('MISTRAL_OCR_ENDPOINT', 'https://api.mistral.ai/v1/ocr');
define('MISTRAL_OCR_MODEL', libreclaude_env('MISTRAL_OCR_MODEL', 'mistral-ocr-latest'));
define('MISTRAL_TRANSCRIPTION_ENDPOINT', 'https://api.mistral.ai/v1/audio/transcriptions');
define('MISTRAL_SPEECH_ENDPOINT', 'https://api.mistral.ai/v1/audio/speech');
define('MISTRAL_TTS_MODEL', libreclaude_env('MISTRAL_TTS_MODEL', 'voxtral-mini-tts-2603'));
define('MISTRAL_TTS_VOICE_ID', libreclaude_env('MISTRAL_TTS_VOICE_ID', ''));
define('MISTRAL_WEB_SEARCH_TOOL', libreclaude_env('MISTRAL_WEB_SEARCH_TOOL', 'web_search'));
define('MISTRAL_IMAGE_MODEL', libreclaude_env('MISTRAL_IMAGE_MODEL', 'mistral-medium-latest'));
define('MISTRAL_IMAGE_AGENT_ID', libreclaude_env('MISTRAL_IMAGE_AGENT_ID', ''));

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
        ['id' => 'mistral-large-latest', 'name' => 'Claude Opus 4.6', 'desc' => 'Dernière génération Large (Payant / Carte bancaire)'],
        ['id' => 'mistral-large-2512', 'name' => 'Claude Opus 4.5', 'desc' => 'Raisonnement avancé (Payant / Carte bancaire)'],
        ['id' => 'mistral-large-2411', 'name' => 'Claude Opus 4', 'desc' => 'Version stable entreprise (Payant / Carte bancaire)'],
    ],
    'code' => [
        ['id' => 'codestral-latest', 'name' => 'Claude Code Max', 'desc' => 'Code & Chat temps réel — Gratuit'],
        ['id' => 'codestral-2508', 'name' => 'Claude Code Opus', 'desc' => 'Architecture, refactoring, FIM — Gratuit'],
        ['id' => 'mistral-code-latest', 'name' => 'Claude Code Sonnet', 'desc' => 'Débogage, patterns complexes — Gratuit'],
        ['id' => 'mistral-code-fim-latest', 'name' => 'Claude Code Haiku', 'desc' => 'Tests unitaires, CI/CD — Gratuit'],
    ],
    'vision' => [
        ['id' => 'ministral-14b-latest', 'name' => 'Claude Vision Opus', 'desc' => 'Multimodal 14B, diagrammes, UI — Gratuit'],
        ['id' => 'ministral-8b-latest', 'name' => 'Claude Vision Haiku', 'desc' => 'Multimodal 8B, OCR rapide — Gratuit'],
    ],
    'edge' => [
        ['id' => 'ministral-14b-2512', 'name' => 'Claude Local Sonnet', 'desc' => 'Modèle compact puissant 14B — Gratuit'],
        ['id' => 'ministral-8b-2512', 'name' => 'Claude Local Haiku', 'desc' => 'All-rounder équilibré 8B — Gratuit'],
        ['id' => 'ministral-3b-latest', 'name' => 'Claude Local Mini', 'desc' => 'Ultra-léger 3B, commandes courtes — Gratuit'],
    ],
    'medium' => [
        ['id' => 'mistral-medium-latest', 'name' => 'Claude Sonnet 4.6', 'desc' => 'Mistral Medium 3.5 — multimodal, agentique'],
        ['id' => 'mistral-medium-2604', 'name' => 'Claude Sonnet 4.5', 'desc' => 'Analyse textuelle, rédaction'],
        ['id' => 'mistral-medium-3', 'name' => 'Claude Sonnet 4', 'desc' => 'RAG, synthèse documents'],
    ],
    'small' => [
        ['id' => 'mistral-small-latest', 'name' => 'Claude Haiku 4.6', 'desc' => 'Mistral Small 4 — hybride rapide et efficace'],
        ['id' => 'mistral-small-2603', 'name' => 'Claude Haiku 4.5', 'desc' => 'Extraction masse, pipelines'],
    ],
    'agent' => [
        ['id' => 'magistral-medium-latest', 'name' => 'Claude Agent Sonnet', 'desc' => 'Orchestration multi-agents'],
        ['id' => 'magistral-small-latest', 'name' => 'Claude Agent Haiku', 'desc' => 'Routage rapide prompts'],
    ],
    'audio' => [
        ['id' => 'voxtral-small-latest', 'name' => 'Claude Audio Haiku', 'desc' => 'Analyse sémantique audio — Gratuit'],
        ['id' => 'voxtral-small-2507', 'name' => 'Claude Audio Mini', 'desc' => 'Traitement flux rapide — Gratuit'],
    ],
]);

define('MODEL_ALIASES', [
    'claude-opus-4.6'        => 'mistral-large-latest',
    'claude-opus-4.5'        => 'mistral-large-2512',
    'claude-opus-4'          => 'mistral-large-2411',
    'claude-sonnet-4.6'      => 'mistral-medium-latest',
    'claude-sonnet-4.5'      => 'mistral-medium-2604',
    'claude-sonnet-4'        => 'mistral-medium-3',
    'claude-haiku-4.6'       => 'mistral-small-latest',
    'claude-haiku-4.5'       => 'mistral-small-2603',
    'claude-code-max'        => 'codestral-latest',
    'claude-code-opus'       => 'codestral-2508',
    'claude-code-sonnet'     => 'mistral-code-latest',
    'claude-code-haiku'      => 'mistral-code-fim-latest',
    'claude-agent-sonnet'    => 'magistral-medium-latest',
    'claude-agent-haiku'     => 'magistral-small-latest',
    'claude-vision-opus'     => 'ministral-14b-latest',
    'claude-vision-haiku'    => 'ministral-8b-latest',
    'claude-muse'            => 'codestral-latest',
    'claude-local-sonnet'    => 'ministral-14b-2512',
    'claude-local-haiku'     => 'ministral-8b-2512',
    'claude-local-mini'      => 'ministral-3b-latest',
    'claude-audio-haiku'     => 'voxtral-small-latest',
    'claude-audio-mini'      => 'voxtral-small-2507',
    // Rétrocompatibilité snapshots retirés par Mistral
    'devstral-2512'          => 'codestral-2508',
    'devstral-medium-2507'   => 'mistral-code-latest',
    'devstral-small-2507'    => 'mistral-code-fim-latest',
    'pixtral-large-2411'     => 'ministral-14b-latest',
    'pixtral-12b-2409'       => 'ministral-8b-latest',
    'labs-mistral-small-creative' => 'codestral-latest',
    'ministral-3b-2512'      => 'ministral-3b-latest',
]);

// Modèle par défaut pour chaque rôle (fonctionnels par défaut sur tous les comptes)
define('MASTER_AGENT_MODEL', 'codestral-latest');
define('CODE_AGENT_MODEL', 'codestral-latest');
define('VISION_AGENT_MODEL', 'ministral-14b-latest');
define('PLANNER_AGENT_MODEL', 'codestral-latest');
define('CREATIVE_AGENT_MODEL', 'codestral-latest');

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
