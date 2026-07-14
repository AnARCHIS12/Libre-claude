<?php
/**
 * Libre Claude - API Conversations (liste + détail)
 */

require_once dirname(__FILE__) . '/config.php';
require_once dirname(__FILE__) . '/database.php';
require_once dirname(__FILE__) . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

$db = Database::getInstance();
if (!$db->isInstalled()) {
    echo json_encode(['success' => false, 'setup_required' => true, 'error' => 'Instance non configurée']);
    exit;
}

$auth = new Auth();
if (!$auth->isAuthenticated()) {
    echo json_encode(['success' => false, 'error' => 'Non authentifié']);
    exit;
}

$user = $auth->getCurrentUser();

$action = $_GET['action'] ?? 'list';

if ($action === 'list') {
    $convs = $db->fetchAll(
        "SELECT id, title, model_used, updated_at, created_at 
         FROM conversations 
         WHERE user_id = ? AND is_archived = 0 
         ORDER BY updated_at DESC 
         LIMIT 200",
        [$user['id']]
    );
    echo json_encode(['success' => true, 'conversations' => $convs]);

} elseif ($action === 'messages') {
    $convId = (int)($_GET['id'] ?? 0);
    if (!$convId) {
        echo json_encode(['success' => false, 'error' => 'ID manquant']);
        exit;
    }

    // Vérifier appartenance
    $conv = $db->fetch("SELECT * FROM conversations WHERE id = ? AND user_id = ?", [$convId, $user['id']]);
    if (!$conv) {
        echo json_encode(['success' => false, 'error' => 'Conversation introuvable']);
        exit;
    }

    $messages = $db->fetchAll(
        "SELECT role, content, model_used, tokens_used, created_at 
         FROM messages 
         WHERE conversation_id = ? 
         ORDER BY created_at ASC",
        [$convId]
    );

    echo json_encode([
        'success'      => true,
        'conversation' => $conv,
        'messages'     => $messages,
    ]);

} elseif ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input  = json_decode(file_get_contents('php://input'), true);
    $convId = (int)($input['id'] ?? 0);

    if ($convId) {
        $conv = $db->fetch("SELECT id FROM conversations WHERE id = ? AND user_id = ?", [$convId, $user['id']]);
        if ($conv) {
            $db->delete('messages', 'conversation_id = ?', [$convId]);
            $db->delete('conversations', 'id = ?', [$convId]);
            echo json_encode(['success' => true]);
            exit;
        }
    }
    echo json_encode(['success' => false, 'error' => 'Conversation introuvable']);

} elseif ($action === 'search') {
    $query = trim($_GET['q'] ?? '');
    if (strlen($query) < 2) {
        echo json_encode(['success' => false, 'error' => 'Requête trop courte']);
        exit;
    }

    // Rechercher dans les titres de conversations
    $convsByTitle = $db->fetchAll(
        "SELECT id, title, model_used, updated_at, created_at 
         FROM conversations 
         WHERE user_id = ? AND is_archived = 0 AND title LIKE ? 
         ORDER BY updated_at DESC 
         LIMIT 50",
        [$user['id'], "%$query%"]
    );

    // Rechercher dans le contenu des messages
    $messages = $db->fetchAll(
        "SELECT DISTINCT m.conversation_id, c.title, c.model_used, c.updated_at, c.created_at
         FROM messages m
         JOIN conversations c ON m.conversation_id = c.id
         WHERE c.user_id = ? AND c.is_archived = 0 AND m.content LIKE ?
         ORDER BY c.updated_at DESC
         LIMIT 50",
        [$user['id'], "%$query%"]
    );

    // Fusionner et dédoublonner les résultats
    $allConvs = [];
    $seenIds = [];

    foreach ($convsByTitle as $conv) {
        $conv['match_type'] = 'title';
        $allConvs[] = $conv;
        $seenIds[$conv['id']] = true;
    }

    foreach ($messages as $conv) {
        if (!isset($seenIds[$conv['conversation_id']])) {
            $conv['id'] = $conv['conversation_id'];
            unset($conv['conversation_id']);
            $conv['match_type'] = 'content';
            $allConvs[] = $conv;
            $seenIds[$conv['id']] = true;
        }
    }

    echo json_encode(['success' => true, 'conversations' => $allConvs, 'query' => $query]);

} elseif ($action === 'export') {
    $convId = (int)($_GET['id'] ?? 0);
    $format = $_GET['format'] ?? 'markdown';
    
    if (!$convId) {
        echo json_encode(['success' => false, 'error' => 'ID manquant']);
        exit;
    }

    // Vérifier appartenance
    $conv = $db->fetch("SELECT * FROM conversations WHERE id = ? AND user_id = ?", [$convId, $user['id']]);
    if (!$conv) {
        echo json_encode(['success' => false, 'error' => 'Conversation introuvable']);
        exit;
    }

    $messages = $db->fetchAll(
        "SELECT role, content, model_used, tokens_used, created_at 
         FROM messages 
         WHERE conversation_id = ? 
         ORDER BY created_at ASC",
        [$convId]
    );

    if ($format === 'json') {
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="conversation_' . $convId . '.json"');
        echo json_encode([
            'conversation' => $conv,
            'messages' => $messages,
            'exported_at' => date('c')
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } elseif ($format === 'markdown') {
        header('Content-Type: text/markdown');
        header('Content-Disposition: attachment; filename="conversation_' . $convId . '.md"');
        
        $title = $conv['title'] ?: 'Sans titre';
        echo "# {$title}\n\n";
        echo "**Modèle:** {$conv['model_used']}\n";
        echo "**Créée le:** {$conv['created_at']}\n";
        echo "**Mise à jour le:** {$conv['updated_at']}\n\n";
        echo "---\n\n";
        
        foreach ($messages as $msg) {
            $role = $msg['role'] === 'user' ? '👤 **Utilisateur**' : '🤖 **Assistant**';
            echo "### {$role}\n\n";
            echo $msg['content'] . "\n\n";
            if ($msg['tokens_used']) {
                echo "*Tokens: {$msg['tokens_used']}*\n\n";
            }
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Format non supporté']);
    }
    exit;

} else {
    echo json_encode(['success' => false, 'error' => 'Action inconnue']);
}
