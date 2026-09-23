<?php
/**
 * Libre Claude - Génération d images Mistral
 * Sauvegarde les images sur disque pour les afficher dans l historique
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

/**
 * Sauvegarde une image base64 sur disque et retourne son URL relative
 */
function saveGeneratedImage(string $base64, string $mime = 'image/png'): ?string {
    $dir = dirname(__FILE__) . '/data/generated_images';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $ext = $mime === 'image/jpeg' ? 'jpg' : 'png';
    $filename = 'img_' . date('Ymd_His') . '_' . substr(md5(microtime()), 0, 6) . '.' . $ext;
    $path = $dir . '/' . $filename;
    $data = base64_decode($base64);
    if ($data === false || file_put_contents($path, $data) === false) {
        return null;
    }
    return '/data/generated_images/' . $filename;
}

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

        // Sauvegarder chaque image sur disque, construire le contenu BDD avec URLs
        $savedUrls = [];
        $dbContent = '';
        if (!empty($result['images'])) {
            foreach ($result['images'] as $img) {
                $url = saveGeneratedImage($img['base64'], $img['mime'] ?? 'image/png');
                if ($url) {
                    $savedUrls[] = $url;
                    $dbContent .= "\n\n![Image générée]($url)";
                }
            }
        }
        if ($dbContent === '') {
            $dbContent = '🖼️ Image générée — ' . mb_substr($prompt, 0, 80);
        }

        if ($convId) {
            $db->insert('messages', [
                'conversation_id' => $convId,
                'role'            => 'user',
                'content'         => $prompt,
                'model_used'      => 'image-generation',
            ]);
            $db->insert('messages', [
                'conversation_id' => $convId,
                'role'            => 'assistant',
                'content'         => trim($dbContent),
                'model_used'      => $result['model'] ?? MISTRAL_IMAGE_MODEL,
            ]);
            $db->update('conversations', ['updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$convId]);
        }

        echo json_encode([
            'success'         => true,
            'content'         => '',
            'model'           => $result['model'] ?? MISTRAL_IMAGE_MODEL,
            'images'          => $result['images'] ?? [],
            'saved_urls'      => $savedUrls,
            'conversation_id' => $convId,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } else {
        echo json_encode([
            'success' => false,
            'error'   => $result['error'] ?? 'Génération d image impossible',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
} catch (Exception $e) {
    libreclaude_log("Image generation exception: " . $e->getMessage(), 1);
    echo json_encode(['success' => false, 'error' => 'Erreur interne de génération d image']);
}
