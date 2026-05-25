<?php
/**
 * Libre Claude - Dictée vocale Voxtral
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
    echo json_encode(['success' => false, 'error' => 'Connexion requise pour utiliser la dictée vocale']);
    exit;
}

if (empty($_FILES['audio']) || !is_uploaded_file($_FILES['audio']['tmp_name'])) {
    echo json_encode(['success' => false, 'error' => 'Aucun fichier audio reçu']);
    exit;
}

$audio = $_FILES['audio'];
if (($audio['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'Upload audio impossible']);
    exit;
}

$maxSize = 25 * 1024 * 1024;
if (($audio['size'] ?? 0) <= 0 || $audio['size'] > $maxSize) {
    echo json_encode(['success' => false, 'error' => 'Audio vide ou trop volumineux']);
    exit;
}

$mimeType = $audio['type'] ?: 'audio/webm';
$fileName = $audio['name'] ?: 'dictee.webm';
$language = trim($_POST['language'] ?? 'fr');
$apiKey = $user['mistral_api_key'] ?: null;

try {
    $claude = getClaudeClient($apiKey);
    $result = $claude->transcribe($audio['tmp_name'], $fileName, $mimeType, $language);

    if ($result['success']) {
        echo json_encode([
            'success'  => true,
            'text'     => $result['text'],
            'language' => $result['language'] ?? null,
            'model'    => $result['model'] ?? 'voxtral-mini-latest',
            'usage'    => $result['usage'] ?? [],
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error'   => $result['error'] ?? 'Transcription impossible',
        ]);
    }
} catch (Exception $e) {
    libreclaude_log("Transcription exception: " . $e->getMessage(), 1);
    echo json_encode(['success' => false, 'error' => 'Erreur interne de transcription']);
}
