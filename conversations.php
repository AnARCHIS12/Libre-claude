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
         LIMIT 50",
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

} else {
    echo json_encode(['success' => false, 'error' => 'Action inconnue']);
}
