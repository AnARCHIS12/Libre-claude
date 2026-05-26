<?php
/**
 * Libre Claude - Génération d images Mistral
 */
require_once dirname(__FILE__) . '/config.php';
require_once dirname(__FILE__) . '/database.php';
require_once dirname(__FILE__) . '/auth.php';
require_once dirname(__FILE__) . '/claude.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Méthode non autorisée']);
    exit;
}

$db = Database::getInstance();
if (!$db->isInstalled()) {
    echo json_encode(['success' => false, 'setup_required' => true, 'error' => 'Instance non configurée']);
    exit;
}

$auth = new Auth();
$user = $auth->getCurrentUser();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Connexion requise pour générer une image']);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
$prompt = trim($input['prompt'] ?? '');
if ($prompt === '') {
    echo json_encode(['success' => false, 'error' => 'Décrivez l image à générer']);
    exit;
}

$apiKey = $user['mistral_api_key'] ?: null;

try {
    $claude = getClaudeClient($apiKey);
    $result = $claude->generateImage($prompt);

    if (!empty($result['success'])) {
        echo json_encode([
            'success' => true,
            'content' => $result['content'] ?? 'Image générée.',
            'model' => $result['model'] ?? MISTRAL_IMAGE_MODEL,
            'images' => $result['images'] ?? [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } else {
        echo json_encode([
            'success' => false,
            'error' => $result['error'] ?? 'Génération d image impossible',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
} catch (Exception $e) {
    libreclaude_log("Image generation exception: " . $e->getMessage(), 1);
    echo json_encode(['success' => false, 'error' => 'Erreur interne de génération d image']);
}
