<?php
/**
 * Libre Claude - Réponse vocale Voxtral TTS
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
    echo json_encode(['success' => false, 'error' => 'Connexion requise pour utiliser la discussion vocale']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    echo json_encode(['success' => false, 'error' => 'JSON invalide']);
    exit;
}

$text = trim($input['text'] ?? '');
if ($text === '') {
    echo json_encode(['success' => false, 'error' => 'Texte vide']);
    exit;
}

$text = preg_replace('/```[\s\S]*?```/', ' bloc de code omis. ', $text);
$text = preg_replace('/`([^`]+)`/', '$1', $text);
$text = preg_replace('/https?:\/\/\S+/i', ' lien omis. ', $text);
$text = preg_replace('/[#*_>\[\]\(\){}|~^=]/', ' ', $text);
$text = preg_replace('/[^\p{L}\p{N}\p{P}\p{Zs}\n\r]/u', ' ', $text);
$text = trim(preg_replace('/\s+/', ' ', strip_tags($text)));

if (mb_strlen($text) > 1200) {
    $text = mb_substr($text, 0, 1200) . '...';
}

$format = trim($input['format'] ?? 'mp3');
$voiceId = trim($input['voice_id'] ?? '');
$refAudio = trim($input['ref_audio'] ?? '');
if ($refAudio !== '') {
    $refAudio = preg_replace('/^data:audio\/[^;]+;base64,/i', '', $refAudio);
    if (!preg_match('/^[A-Za-z0-9+\/=\r\n]+$/', $refAudio)) {
        $refAudio = '';
    }
    if (strlen($refAudio) > 8 * 1024 * 1024) {
        $refAudio = '';
    }
}
$apiKey = $user['mistral_api_key'] ?: null;

try {
    $claude = getClaudeClient($apiKey);
    $result = $claude->speech($text, $voiceId, $format, $refAudio);

    if ($result['success']) {
        echo json_encode([
            'success'      => true,
            'audio_base64' => $result['audio_data'],
            'mime'         => $result['mime'] ?? 'audio/mpeg',
            'format'       => $result['format'] ?? 'mp3',
            'model'        => $result['model'] ?? MISTRAL_TTS_MODEL,
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error'   => $result['error'] ?? 'Synthèse vocale impossible',
        ]);
    }
} catch (Exception $e) {
    libreclaude_log("Speech exception: " . $e->getMessage(), 1);
    echo json_encode(['success' => false, 'error' => 'Erreur interne de synthèse vocale']);
}
