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
        $convId = isset($input['conversation_id']) ? (int)$input['conversation_id'] : null;
        if (!$convId && !empty($user['id'])) {
            $convId = $db->insert('conversations', [
                'user_id' => $user['id'],
                'title'   => mb_substr($prompt, 0, 50, 'UTF-8'),
            ]);
        }
        if ($convId) {
            $db->insert('messages', [
                'conversation_id' => $convId,
                'role'            => 'user',
                'content'         => $prompt,
                'model_used'      => 'image-generation',
            ]);
            $assistantContent = $result['content'] ?? 'Image générée.';
            if (!empty($result['images'])) {
                foreach ($result['images'] as $img) {
                    $assistantContent .= "\n\n![Image](data:" . ($img['mime'] ?? 'image/png') . ";base64," . $img['base64'] . ")";
                }
            }
            $db->insert('messages', [
                'conversation_id' => $convId,
                'role'            => 'assistant',
                'content'         => $assistantContent,
                'model_used'      => $result['model'] ?? MISTRAL_IMAGE_MODEL,
            ]);
            $db->update('conversations', ['updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$convId]);
        }

        echo json_encode([
            'success' => true,
            'content' => $result['content'] ?? 'Image générée.',
            'model' => $result['model'] ?? MISTRAL_IMAGE_MODEL,
            'images' => $result['images'] ?? [],
            'conversation_id' => $convId,
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
