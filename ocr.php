<?php
/**
 * Libre Claude - Analyse OCR Mistral
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
    echo json_encode(['success' => false, 'error' => 'Connexion requise pour analyser un fichier']);
    exit;
}

if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
    echo json_encode(['success' => false, 'error' => 'Aucun fichier reçu']);
    exit;
}

$file = $_FILES['file'];
if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'Upload impossible']);
    exit;
}

$maxSize = 25 * 1024 * 1024;
if (($file['size'] ?? 0) <= 0 || $file['size'] > $maxSize) {
    echo json_encode(['success' => false, 'error' => 'Fichier vide ou trop volumineux']);
    exit;
}

$fileName = $file['name'] ?: 'document';
$mimeType = $file['type'] ?: 'application/octet-stream';
$allowed = [
    'application/pdf',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'image/png',
    'image/jpeg',
    'image/jpg',
    'image/webp',
    'image/avif',
    'image/gif',
];
if (!in_array($mimeType, $allowed, true) && strpos($mimeType, 'image/') !== 0) {
    echo json_encode(['success' => false, 'error' => 'Format non supporté pour l OCR']);
    exit;
}

$prompt = trim($_POST['prompt'] ?? '');
$model = trim($_POST['model'] ?? VISION_AGENT_MODEL);
$apiKey = $user['mistral_api_key'] ?: null;

try {
    $claude = getClaudeClient($apiKey);
    $ocr = $claude->ocrFile($file['tmp_name'], $fileName, $mimeType);
    if (empty($ocr['success'])) {
        echo json_encode(['success' => false, 'error' => $ocr['error'] ?? 'OCR impossible']);
        exit;
    }

    $ocrText = trim($ocr['text'] ?? '');
    $content = $ocrText;
    $analysisModel = $ocr['model'] ?? MISTRAL_OCR_MODEL;

    if ($prompt !== '') {
        $analysisPrompt = "Analyse le document extrait par OCR ci-dessous. Réponds en français, de façon claire et utile.\n\nDemande utilisateur : " . $prompt . "\n\nTexte OCR :\n" . $ocrText;
        $analysis = $claude->chat([
            [
                'role' => 'system',
                'content' => 'Tu es Libre Claude, expert en analyse de documents OCR. Cite les éléments observables et signale quand une information est incertaine.',
            ],
            [
                'role' => 'user',
                'content' => $analysisPrompt,
            ],
        ], $model, [
            'temperature' => 0.3,
            'max_tokens' => 4096,
        ]);

        if (!empty($analysis['success'])) {
            $content = $analysis['content'];
            $analysisModel = $analysis['model'] ?? $model;
        }
    }

    echo json_encode([
        'success' => true,
        'content' => $content,
        'ocr_text' => $ocrText,
        'model' => $analysisModel,
        'file_name' => $fileName,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Exception $e) {
    libreclaude_log("OCR exception: " . $e->getMessage(), 1);
    echo json_encode(['success' => false, 'error' => 'Erreur interne OCR']);
}
