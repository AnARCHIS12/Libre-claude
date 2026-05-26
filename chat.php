<?php
/**
 * Libre Claude - API Chat (Hostinger compatible)
 * Endpoint: /chat.php (POST JSON)
 */

require_once dirname(__FILE__) . '/config.php';
require_once dirname(__FILE__) . '/database.php';
require_once dirname(__FILE__) . '/auth.php';
require_once dirname(__FILE__) . '/claude.php';
require_once dirname(__FILE__) . '/memory.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    exit;
}

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

$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);

$message  = trim($input['message'] ?? '');
$model    = trim($input['model'] ?? MASTER_AGENT_MODEL);
$modelAlias = strtolower($model);
if (defined('MODEL_ALIASES') && isset(MODEL_ALIASES[$modelAlias])) {
    $model = MODEL_ALIASES[$modelAlias];
}
$convId   = isset($input['conversation_id']) ? (int)$input['conversation_id'] : null;
$useHistory = !isset($input['use_history']) || (bool)$input['use_history'];
$useWebSearch = !empty($input['web_search']);

if ($message === '') {
    echo json_encode(['success' => false, 'error' => 'Message vide']);
    exit;
}

// Valider le modèle
$allModels = [];
$modelNames = [];
foreach (MISTRAL_MODELS as $cat) {
    foreach ($cat as $m) {
        $allModels[] = $m['id'];
        $modelNames[$m['id']] = $m['name'];
    }
}
if (!in_array($model, $allModels)) {
    $model = MASTER_AGENT_MODEL;
}

try {
    $auth   = new Auth();
    $bearer = '';
    if (preg_match('/Bearer\s+(.+)/i', $_SERVER['HTTP_AUTHORIZATION'] ?? '', $m)) {
        $bearer = trim($m[1]);
    } elseif (preg_match('/Bearer\s+(.+)/i', $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '', $m)) {
        $bearer = trim($m[1]);
    }

    $user   = $bearer ? $auth->getUserByApiToken($bearer) : $auth->getCurrentUser();
    if ($bearer && !$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Clé API Libre Claude invalide']);
        exit;
    }
    $userId = $user ? (int)$user['id'] : null;
    $apiKey = $user ? ($user['mistral_api_key'] ?: null) : null;

    $claude = getClaudeClient($apiKey);

    // Créer ou récupérer la conversation, y compris pour les appels API internes lc_sk_...
    if (!$convId && $userId && $useHistory) {
        $convId = $db->insert('conversations', [
            'user_id'    => $userId,
            'title'      => mb_substr($message, 0, 60),
            'model_used' => $model,
        ]);
    } elseif ($convId && $userId) {
        // Vérifier que la conversation appartient à l'utilisateur
        $conv = $db->fetch("SELECT id FROM conversations WHERE id = ? AND user_id = ?", [$convId, $userId]);
        if (!$conv) {
            echo json_encode(['success' => false, 'error' => 'Conversation introuvable']);
            exit;
        }
    }

    // Sauvegarder le message utilisateur
    if ($convId) {
        $db->insert('messages', [
            'conversation_id' => $convId,
            'role'            => 'user',
            'content'         => $message,
            'model_used'      => $model,
        ]);
    }
    if ($userId) {
        memory_capture_from_message($db, $user, $message, $convId);
    }

    // Construire les messages pour l'API
    $apiMessages = [];

    // Système prompt
    $memoryContext = $userId ? memory_build_context($db, $user) : '';
    $systemPrompt = "Tu es Libre Claude, un assistant IA avancé basé sur Mistral AI. Tu es intelligent, précis, créatif et bienveillant. Tu réponds toujours en français sauf si l'utilisateur parle une autre langue. Tu peux coder, analyser, créer et planifier des tâches complexes.";
    if ($useWebSearch) {
        $systemPrompt .= " Quand la recherche web est activée, utilise l'outil web_search pour vérifier les informations récentes, cite les sources pertinentes et signale clairement si les résultats ne permettent pas de conclure.";
    }
    if ($memoryContext !== '') {
        $systemPrompt .= "\n\n" . $memoryContext;
    }
    $apiMessages[] = [
        'role'    => 'system',
        'content' => $systemPrompt,
    ];

    // Historique de la conversation (max 20 derniers messages)
    if ($convId && $useHistory) {
        $history = $db->fetchAll(
            "SELECT role, content FROM messages 
             WHERE conversation_id = ? 
             ORDER BY created_at DESC 
             LIMIT 20",
            [$convId]
        );
        foreach (array_reverse($history) as $msg) {
            if (in_array($msg['role'], ['user', 'assistant'])) {
                $apiMessages[] = [
                    'role'    => $msg['role'],
                    'content' => $msg['content'],
                ];
            }
        }
    } else {
        // Sans compte, juste le message actuel
        $apiMessages[] = ['role' => 'user', 'content' => $message];
    }

    // Appel Mistral
    if ($useWebSearch) {
        $result = $claude->chatWithWebSearch($apiMessages, $model, [
            'temperature' => 0.3,
            'web_search_tool' => MISTRAL_WEB_SEARCH_TOOL,
        ]);
    } else {
        $result = $claude->chat($apiMessages, $model, [
            'temperature' => 0.7,
            'max_tokens'  => 4096,
        ]);
    }

    if ($result['success']) {
        $reply = $result['content'];

        // Sauvegarder la réponse
        if ($convId) {
            $db->insert('messages', [
                'conversation_id' => $convId,
                'role'            => 'assistant',
                'content'         => $reply,
                'model_used'      => $model,
                'tokens_used'     => $result['usage']['total_tokens'] ?? 0,
            ]);

            // Mettre à jour le timestamp de la conversation
            $db->update('conversations', ['updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$convId]);
        }

        echo json_encode([
            'success'         => true,
            'content'         => $reply,
            'model'           => $model,
            'model_name'      => $modelNames[$model] ?? $model,
            'conversation_id' => $convId,
            'web_search'      => $useWebSearch,
            'sources'         => $result['sources'] ?? [],
            'usage'           => $result['usage'] ?? [],
        ]);
    } else {
        libreclaude_log("Chat error for user $userId: " . $result['error'], 2);
        echo json_encode([
            'success' => false,
            'error'   => $result['error'] ?? 'Erreur de l\'API Mistral',
        ]);
    }

} catch (Exception $e) {
    libreclaude_log("Chat exception: " . $e->getMessage(), 1);
    echo json_encode(['success' => false, 'error' => 'Erreur interne du serveur']);
}
