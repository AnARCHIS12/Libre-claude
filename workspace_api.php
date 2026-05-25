<?php
/**
 * Libre Claude - Workspace API
 * POST JSON with a session cookie or Authorization: Bearer lc_sk_...
 */

require_once dirname(__FILE__) . '/config.php';
require_once dirname(__FILE__) . '/database.php';
require_once dirname(__FILE__) . '/auth.php';
require_once dirname(__FILE__) . '/claude.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    exit;
}

function workspace_api_response($status, $payload) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    workspace_api_response(405, ['success' => false, 'error' => 'Méthode non autorisée']);
}

$db = Database::getInstance();
if (!$db->isInstalled()) {
    workspace_api_response(409, ['success' => false, 'setup_required' => true, 'error' => 'Instance non configurée']);
}

$auth = new Auth();
$bearer = '';
if (preg_match('/Bearer\s+(.+)/i', $_SERVER['HTTP_AUTHORIZATION'] ?? '', $m)) {
    $bearer = trim($m[1]);
} elseif (preg_match('/Bearer\s+(.+)/i', $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '', $m)) {
    $bearer = trim($m[1]);
}
$user = $bearer ? $auth->getUserByApiToken($bearer) : $auth->getCurrentUser();
if (!$user) {
    workspace_api_response(401, ['success' => false, 'error' => 'Connexion requise']);
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($input)) {
    workspace_api_response(400, ['success' => false, 'error' => 'JSON invalide']);
}

function workspace_parse_repo($value) {
    $value = trim((string)$value);
    if ($value === '') return null;
    if (preg_match('~github\.com/([^/]+)/([^/?#]+)~i', $value, $m)) {
        return [$m[1], preg_replace('/\.git$/', '', $m[2])];
    }
    if (preg_match('#^([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)$#', $value, $m)) {
        return [$m[1], preg_replace('/\.git$/', '', $m[2])];
    }
    return null;
}

function workspace_github_request($method, $url, $token, $body = null, &$error = '') {
    $headers = [
        'Accept: application/vnd.github+json',
        'User-Agent: Libre-Claude-Workspace-API',
    ];
    if ($token) $headers[] = 'Authorization: Bearer ' . $token;
    if ($body !== null) $headers[] = 'Content-Type: application/json';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));

    $response = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError || $http < 200 || $http >= 300) {
        $decoded = json_decode($response ?: '', true);
        $error = $curlError ?: (($decoded['message'] ?? null) ? $decoded['message'] . ' (HTTP ' . $http . ')' : 'HTTP ' . $http);
        return null;
    }

    return json_decode($response ?: '{}', true);
}

function workspace_github_path($path) {
    $parts = array_filter(explode('/', trim((string)$path, '/')), fn($part) => $part !== '');
    return implode('/', array_map('rawurlencode', $parts));
}

function workspace_clean_path($path) {
    $path = str_replace('\\', '/', trim((string)$path));
    $parts = [];
    foreach (explode('/', trim($path, '/')) as $part) {
        if ($part === '' || $part === '.') continue;
        if ($part === '..') continue;
        $parts[] = $part;
    }
    return implode('/', $parts);
}

function workspace_github_tree($owner, $repo, $branch, $token, &$error) {
    $repoBase = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo);
    $url = $repoBase . '/git/trees/' . rawurlencode($branch ?: 'main') . '?recursive=1';
    $data = workspace_github_request('GET', $url, $token, null, $error);
    if (!$data && (stripos($error, 'Git Repository is empty') !== false || stripos($error, 'HTTP 409') !== false)) {
        $error = '';
        return [];
    }
    if (!$data && stripos($error, 'HTTP 404') !== false) {
        $defaultError = '';
        $defaultBranch = workspace_github_default_branch($owner, $repo, $token, $defaultError);
        if ($defaultBranch !== '' && $defaultBranch !== ($branch ?: 'main')) {
            $data = workspace_github_request('GET', $repoBase . '/git/trees/' . rawurlencode($defaultBranch) . '?recursive=1', $token, null, $error);
        }
    }
    if (!$data || !isset($data['tree']) || !is_array($data['tree'])) return [];
    return array_values(array_filter($data['tree'], fn($item) => ($item['type'] ?? '') === 'blob'));
}

function workspace_github_default_branch($owner, $repo, $token, &$error) {
    $data = workspace_github_request(
        'GET',
        'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo),
        $token,
        null,
        $error
    );
    return is_array($data) && !empty($data['default_branch']) ? (string)$data['default_branch'] : '';
}

function workspace_publish_error_message($error) {
    if (stripos($error, 'Resource not accessible by integration') !== false) {
        return 'GitHub refuse l écriture avec ce jeton. Activez Repository permissions > Contents: Read and write dans la GitHub App, vérifiez que l app est installée sur ce dépôt, puis reconnectez GitHub dans Libre Claude. ' . $error;
    }
    return $error;
}

function workspace_github_get_file($owner, $repo, $branch, $path, $token, &$error) {
    $repoBase = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo);
    $url = $repoBase
        . '/contents/' . workspace_github_path($path) . '?ref=' . rawurlencode($branch ?: 'main');
    $data = workspace_github_request('GET', $url, $token, null, $error);
    if (!$data && stripos($error, 'HTTP 404') !== false) {
        $defaultError = '';
        $defaultBranch = workspace_github_default_branch($owner, $repo, $token, $defaultError);
        if ($defaultBranch !== '' && $defaultBranch !== ($branch ?: 'main')) {
            $data = workspace_github_request('GET', $repoBase . '/contents/' . workspace_github_path($path) . '?ref=' . rawurlencode($defaultBranch), $token, null, $error);
        }
    }
    if (!$data || !isset($data['content'])) return null;
    $content = base64_decode(str_replace(["\r", "\n"], '', $data['content']));
    return [
        'path' => $data['path'] ?? $path,
        'content' => $content === false ? '' : $content,
    ];
}

function workspace_github_save_file($owner, $repo, $branch, $path, $content, $message, $token, &$error) {
    $path = workspace_clean_path($path);
    $url = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo)
        . '/contents/' . workspace_github_path($path);
    $body = [
        'message' => $message ?: 'Initial commit from Libre Claude Coder',
        'content' => base64_encode((string)$content),
    ];
    if ($branch !== '') $body['branch'] = $branch ?: 'main';
    return workspace_github_request('PUT', $url, $token, $body, $error);
}

function workspace_github_create_initial_file($owner, $repo, $branch, $path, $content, $message, $token, &$error) {
    $initial = workspace_github_save_file($owner, $repo, $branch ?: 'main', $path, $content, $message, $token, $error);
    if ($initial || (stripos($error, 'HTTP 422') === false && stripos($error, 'HTTP 404') === false)) return $initial;
    return workspace_github_save_file($owner, $repo, '', $path, $content, $message, $token, $error);
}

function workspace_clean_files($items) {
    if (!is_array($items)) return [];
    $files = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $path = workspace_clean_path($item['path'] ?? '');
        if ($path === '' || !array_key_exists('content', $item)) continue;
        $files[] = ['path' => $path, 'content' => (string)$item['content']];
    }
    return $files;
}

function workspace_commit_files($owner, $repo, $branch, $files, $message, $token, &$error) {
    $branch = $branch ?: 'main';
    $cleanFiles = [];
    $treeItems = [];
    foreach ($files as $file) {
        $cleanFiles[] = ['path' => $file['path'], 'content' => (string)$file['content']];
        $treeItems[] = [
            'path' => $file['path'],
            'mode' => '100644',
            'type' => 'blob',
            'content' => (string)$file['content'],
        ];
    }
    if (!$treeItems) {
        $error = 'Aucun fichier à publier';
        return null;
    }

    $repoBase = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo);
    $refUrl = $repoBase . '/git/ref/heads/' . rawurlencode($branch);
    $refError = '';
    $ref = workspace_github_request('GET', $refUrl, $token, null, $refError);
    $isEmptyRepo = !$ref && (stripos($refError, 'Git Repository is empty') !== false || stripos($refError, 'HTTP 409') !== false);
    if (!$ref && !$isEmptyRepo && stripos($refError, 'HTTP 404') !== false) {
        $defaultError = '';
        $defaultBranch = workspace_github_default_branch($owner, $repo, $token, $defaultError);
        if ($defaultBranch !== '' && $defaultBranch !== $branch) {
            $branch = $defaultBranch;
            $refUrl = $repoBase . '/git/ref/heads/' . rawurlencode($branch);
            $refError = '';
            $ref = workspace_github_request('GET', $refUrl, $token, null, $refError);
            $isEmptyRepo = !$ref && (stripos($refError, 'Git Repository is empty') !== false || stripos($refError, 'HTTP 409') !== false);
        } elseif ($defaultError !== '') {
            $refError = $refError . ' - ' . $defaultError;
        }
    }
    $parentSha = '';
    $baseTreeSha = '';

    if ($isEmptyRepo) {
        $first = array_shift($cleanFiles);
        $initial = workspace_github_create_initial_file(
            $owner,
            $repo,
            $branch,
            $first['path'],
            $first['content'],
            $message ?: 'Initial commit from Libre Claude Coder',
            $token,
            $error
        );
        if (!$initial) return null;
        $initialCommitSha = $initial['commit']['sha'] ?? '';
        if (!$cleanFiles) {
            return [
                'sha' => $initialCommitSha,
                'branch' => $branch,
                'url' => $initialCommitSha ? 'https://github.com/' . $owner . '/' . $repo . '/commit/' . $initialCommitSha : 'https://github.com/' . $owner . '/' . $repo,
            ];
        }

        $treeItems = [];
        foreach ($cleanFiles as $file) {
            $treeItems[] = [
                'path' => $file['path'],
                'mode' => '100644',
                'type' => 'blob',
                'content' => $file['content'],
            ];
        }

        $refError = '';
        $ref = workspace_github_request('GET', $refUrl, $token, null, $refError);
        $isEmptyRepo = false;
    }

    if (!$isEmptyRepo) {
        if (!$ref || empty($ref['object']['sha'])) {
            $error = $refError ?: 'Branche GitHub introuvable';
            return null;
        }
        $parentSha = $ref['object']['sha'];
        $parentCommit = workspace_github_request('GET', $repoBase . '/git/commits/' . rawurlencode($parentSha), $token, null, $error);
        if (!$parentCommit || empty($parentCommit['tree']['sha'])) return null;
        $baseTreeSha = $parentCommit['tree']['sha'];
    }

    $treeBody = ['tree' => $treeItems];
    if ($baseTreeSha !== '') $treeBody['base_tree'] = $baseTreeSha;
    $tree = workspace_github_request('POST', $repoBase . '/git/trees', $token, $treeBody, $error);
    if (!$tree || empty($tree['sha'])) return null;

    $commit = workspace_github_request('POST', $repoBase . '/git/commits', $token, [
        'message' => $message ?: 'Update files from Libre Claude Coder',
        'tree' => $tree['sha'],
        'parents' => $parentSha !== '' ? [$parentSha] : [],
    ], $error);
    if (!$commit || empty($commit['sha'])) return null;

    if ($isEmptyRepo) {
        $updated = workspace_github_request('POST', $repoBase . '/git/refs', $token, [
            'ref' => 'refs/heads/' . $branch,
            'sha' => $commit['sha'],
        ], $error);
    } else {
        $updated = workspace_github_request('PATCH', $refUrl, $token, [
            'sha' => $commit['sha'],
            'force' => false,
        ], $error);
    }
    if (!$updated) return null;

    return [
        'sha' => $commit['sha'],
        'branch' => $branch,
        'url' => 'https://github.com/' . $owner . '/' . $repo . '/commit/' . $commit['sha'],
    ];
}

function workspace_extract_files_from_ai($text) {
    $text = trim((string)$text);
    if ($text === '') return [];
    if (preg_match('/```(?:json)?\s*(\[.*?\])\s*```/is', $text, $m)) {
        return workspace_clean_files(json_decode($m[1], true));
    }
    $decoded = json_decode($text, true);
    if (is_array($decoded)) return workspace_clean_files($decoded);
    $start = strpos($text, '[');
    $end = strrpos($text, ']');
    if ($start !== false && $end !== false && $end > $start) {
        return workspace_clean_files(json_decode(substr($text, $start, $end - $start + 1), true));
    }
    return [];
}

function workspace_generate_files($prompt, $contextFiles, $tree, $user, &$raw, &$error) {
    $treeLines = [];
    foreach (array_slice($tree, 0, 120) as $item) {
        $treeLines[] = '- ' . ($item['path'] ?? '');
    }

    $context = '';
    foreach ($contextFiles as $file) {
        $content = (string)($file['content'] ?? '');
        if (strlen($content) > 18000) $content = substr($content, 0, 18000) . "\n/* tronque */";
        $context .= "\n\n--- FILE: " . ($file['path'] ?? 'unknown') . " ---\n" . $content;
    }

    $client = getClaudeClient($user['mistral_api_key'] ?? null);
    $result = $client->chat([
        ['role' => 'system', 'content' => 'Tu es Libre Claude Coder. Retourne uniquement un JSON valide: [{"path":"...","content":"..."}].'],
        ['role' => 'user', 'content' => "Arborescence:\n" . implode("\n", $treeLines) . "\n\nContexte:" . ($context ?: "\nAucun fichier complet fourni.") . "\n\nDemande:\n" . $prompt],
    ], defined('MASTER_AGENT_MODEL') ? MASTER_AGENT_MODEL : 'mistral-large-2512', [
        'temperature' => 0.25,
        'max_tokens' => 8192,
    ]);

    if (empty($result['success'])) {
        $error = $result['error'] ?? 'Generation IA impossible';
        return [];
    }
    $raw = trim($result['content'] ?? '');
    $files = workspace_extract_files_from_ai($raw);
    if (!$files) $error = 'La réponse IA ne contient pas de fichiers JSON valides';
    return $files;
}

$github = $db->fetch("SELECT * FROM workspace_github WHERE user_id = ?", [(int)$user['id']]);
if (!$github || empty($github['token'])) {
    workspace_api_response(409, ['success' => false, 'error' => 'Aucun compte GitHub connecté']);
}

$owner = $github['owner'] ?? '';
$repo = $github['repo'] ?? '';
$branch = trim($input['branch'] ?? ($github['branch'] ?? 'main')) ?: 'main';
if (!empty($input['repo'])) {
    $parsed = workspace_parse_repo($input['repo']);
    if (!$parsed) workspace_api_response(400, ['success' => false, 'error' => 'Dépôt invalide']);
    [$owner, $repo] = $parsed;
}
if ($owner === '' || $repo === '') {
    workspace_api_response(409, ['success' => false, 'error' => 'Aucun dépôt GitHub sélectionné']);
}

$action = $input['action'] ?? 'publish';
$publish = !empty($input['publish']) || $action === 'publish' || $action === 'generate_and_publish';
$files = workspace_clean_files($input['files'] ?? []);
$rawReply = '';

if ($action === 'generate' || $action === 'generate_and_publish') {
    $prompt = trim($input['prompt'] ?? '');
    if ($prompt === '') workspace_api_response(400, ['success' => false, 'error' => 'Prompt requis']);

    $treeError = '';
    $tree = workspace_github_tree($owner, $repo, $branch, $github['token'], $treeError);
    $contextFiles = [];
    foreach (array_slice(($input['context_paths'] ?? []), 0, 8) as $path) {
        $fileError = '';
        $file = workspace_github_get_file($owner, $repo, $branch, $path, $github['token'], $fileError);
        if ($file) $contextFiles[] = $file;
    }

    $genError = '';
    $files = workspace_generate_files($prompt, $contextFiles, $tree, $user, $rawReply, $genError);
    if (!$files) workspace_api_response(502, ['success' => false, 'error' => $genError, 'raw' => $rawReply]);
}

if (!$publish) {
    workspace_api_response(200, ['success' => true, 'files' => $files, 'raw' => $rawReply]);
}

if (!$files) {
    workspace_api_response(400, ['success' => false, 'error' => 'Aucun fichier à publier']);
}

$commitError = '';
$commit = workspace_commit_files($owner, $repo, $branch, $files, trim($input['commit_message'] ?? ''), $github['token'], $commitError);
if (!$commit) {
    workspace_api_response(502, ['success' => false, 'error' => workspace_publish_error_message($commitError)]);
}

workspace_api_response(200, [
    'success' => true,
    'published' => true,
    'merged' => true,
    'repo' => $owner . '/' . $repo,
    'files' => array_map(fn($file) => $file['path'], $files),
    'commit' => $commit,
    'raw' => $rawReply,
]);
