<?php
/**
 * Libre Claude - API Workspace
 */
require_once dirname(__FILE__) . '/config.php';
require_once dirname(__FILE__) . '/auth.php';
require_once dirname(__FILE__) . '/database.php';

header('Content-Type: application/json; charset=utf-8');

$auth = new Auth();
$user = $auth->getCurrentUser();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Connexion requise']);
    exit;
}

$db = Database::getInstance();
$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) $payload = $_POST;

$action = $payload['action'] ?? '';

if ($action === 'save_block') {
    $language = strtolower(trim($payload['language'] ?? 'text'));
    $content = trim($payload['content'] ?? '');
    $name = trim($payload['name'] ?? '');

    if ($content === '') {
        echo json_encode(['success' => false, 'error' => 'Code vide']);
        exit;
    }

    $extensions = [
        'html' => 'html',
        'css' => 'css',
        'js' => 'js',
        'javascript' => 'js',
        'svg' => 'svg',
        'php' => 'php',
        'python' => 'py',
        'py' => 'py',
        'json' => 'json',
        'sql' => 'sql',
        'text' => 'txt',
    ];
    $ext = $extensions[$language] ?? 'txt';
    if ($name === '') {
        $name = 'bloc-' . date('Ymd-His') . '.' . $ext;
    }

    $db->insert('workspace_files', [
        'user_id' => $user['id'],
        'name' => $name,
        'language' => $language ?: 'text',
        'content' => $content,
        'source_conversation_id' => isset($payload['conversation_id']) ? (int)$payload['conversation_id'] : null,
    ]);

    echo json_encode(['success' => true, 'message' => 'saved']);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Action inconnue']);
