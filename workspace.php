<?php
/**
 * Libre Claude - Workspace et GitHub
 */
require_once dirname(__FILE__) . '/config.php';
require_once dirname(__FILE__) . '/auth.php';
require_once dirname(__FILE__) . '/database.php';
require_once dirname(__FILE__) . '/i18n.php';
require_once dirname(__FILE__) . '/claude.php';

$db = Database::getInstance();
if (!$db->isInstalled()) {
    header('Location: setup.php');
    exit;
}

$auth = new Auth();
$user = $auth->getCurrentUser();
if (!$user) {
    header('Location: login.php');
    exit;
}

$lang = current_language($user);
$t = fn($key) => t($key, $lang);
$success = '';
$error = '';
$aiPrompt = '';
$aiContextPaths = '';
$aiFilesDraft = '';
$aiCodeReply = '';
$githubOauthErrorDetail = '';
if (!empty($_GET['github_error']) && !empty($_SESSION['github_oauth_error_detail'])) {
    $githubOauthErrorDetail = (string) $_SESSION['github_oauth_error_detail'];
    unset($_SESSION['github_oauth_error_detail']);
}

function parse_github_repo($value) {
    $value = trim($value);
    if ($value === '') return null;
    if (preg_match('#^git@github\.com:([^/]+)/(.+?)(?:\.git)?$#i', $value, $m)) {
        return [$m[1], preg_replace('/\.git$/', '', $m[2])];
    }
    if (preg_match('~github\.com/([^/]+)/([^/?#]+)~i', $value, $m)) {
        return [$m[1], preg_replace('/\.git$/', '', $m[2])];
    }
    if (preg_match('#^([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)$#', $value, $m)) {
        return [$m[1], preg_replace('/\.git$/', '', $m[2])];
    }
    return null;
}

function github_api_request($method, $url, $token, $body = null, &$error = '') {
    $headers = [
        'Accept: application/vnd.github+json',
        'User-Agent: Libre-Claude-Workspace',
    ];
    if ($token) $headers[] = 'Authorization: Bearer ' . $token;
    if ($body !== null) $headers[] = 'Content-Type: application/json';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
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

function github_path_url($path) {
    $parts = array_filter(explode('/', trim($path, '/')), fn($part) => $part !== '');
    return implode('/', array_map('rawurlencode', $parts));
}

function github_clean_path($path) {
    $path = str_replace('\\', '/', trim((string)$path));
    $parts = [];
    foreach (explode('/', trim($path, '/')) as $part) {
        if ($part === '' || $part === '.') continue;
        if ($part === '..') continue;
        $parts[] = $part;
    }
    return implode('/', $parts);
}

function github_tree($owner, $repo, $branch, $token, &$error) {
    if (!$owner || !$repo) return [];
    $repoBase = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo);
    $url = $repoBase . '/git/trees/' . rawurlencode($branch ?: 'main') . '?recursive=1';

    $data = github_api_request('GET', $url, $token, null, $error);
    if (!$data) {
        if (stripos($error, 'Git Repository is empty') !== false || stripos($error, 'HTTP 409') !== false) {
            $error = '';
        } elseif (stripos($error, 'HTTP 404') !== false) {
            $defaultError = '';
            $defaultBranch = github_default_branch($owner, $repo, $token, $defaultError);
            if ($defaultBranch !== '' && $defaultBranch !== ($branch ?: 'main')) {
                $data = github_api_request('GET', $repoBase . '/git/trees/' . rawurlencode($defaultBranch) . '?recursive=1', $token, null, $error);
                if ($data && isset($data['tree']) && is_array($data['tree'])) {
                    return array_values(array_filter($data['tree'], fn($item) => ($item['type'] ?? '') === 'blob'));
                }
            }
        }
        return [];
    }

    if (!isset($data['tree']) || !is_array($data['tree'])) {
        $error = 'Réponse GitHub invalide';
        return [];
    }

    return array_values(array_filter($data['tree'], fn($item) => ($item['type'] ?? '') === 'blob'));
}

function github_default_branch($owner, $repo, $token, &$error) {
    $data = github_api_request(
        'GET',
        'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo),
        $token,
        null,
        $error
    );
    return is_array($data) && !empty($data['default_branch']) ? (string)$data['default_branch'] : '';
}

function github_get_file($owner, $repo, $branch, $path, $token, &$error) {
    $repoBase = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo);
    $url = $repoBase
        . '/contents/' . github_path_url($path) . '?ref=' . rawurlencode($branch ?: 'main');
    $data = github_api_request('GET', $url, $token, null, $error);
    if (!$data && stripos($error, 'HTTP 404') !== false) {
        $defaultError = '';
        $defaultBranch = github_default_branch($owner, $repo, $token, $defaultError);
        if ($defaultBranch !== '' && $defaultBranch !== ($branch ?: 'main')) {
            $data = github_api_request('GET', $repoBase . '/contents/' . github_path_url($path) . '?ref=' . rawurlencode($defaultBranch), $token, null, $error);
        }
    }
    if (!$data || !isset($data['content'])) return null;
    $content = base64_decode(str_replace(["\r", "\n"], '', $data['content']));
    return [
        'path' => $data['path'] ?? $path,
        'sha' => $data['sha'] ?? '',
        'content' => $content === false ? '' : $content,
    ];
}

function github_save_file($owner, $repo, $branch, $path, $content, $message, $sha, $token, &$error) {
    $path = github_clean_path($path);
    $url = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo)
        . '/contents/' . github_path_url($path);
    $body = [
        'message' => $message ?: 'Update ' . $path,
        'content' => base64_encode($content),
    ];
    if ($branch !== '') $body['branch'] = $branch ?: 'main';
    if ($sha) $body['sha'] = $sha;
    return github_api_request('PUT', $url, $token, $body, $error);
}

function github_save_file_resilient($owner, $repo, $branch, $path, $content, $message, $sha, $token, &$error) {
    $targetBranch = $branch ?: 'main';
    $saved = github_save_file($owner, $repo, $targetBranch, $path, $content, $message, $sha, $token, $error);
    if ($saved || (stripos($error, 'HTTP 404') === false && stripos($error, 'HTTP 422') === false)) return $saved;

    $defaultError = '';
    $defaultBranch = github_default_branch($owner, $repo, $token, $defaultError);
    if ($defaultBranch !== '' && $defaultBranch !== $targetBranch) {
        $saved = github_save_file($owner, $repo, $defaultBranch, $path, $content, $message, $sha, $token, $error);
        if ($saved || (stripos($error, 'HTTP 404') === false && stripos($error, 'HTTP 422') === false)) return $saved;
    }

    return github_save_file($owner, $repo, '', $path, $content, $message, $sha, $token, $error);
}

function github_create_initial_file($owner, $repo, $branch, $path, $content, $message, $token, &$error) {
    $initial = github_save_file($owner, $repo, $branch ?: 'main', $path, $content, $message, '', $token, $error);
    if ($initial || (stripos($error, 'HTTP 422') === false && stripos($error, 'HTTP 404') === false)) return $initial;
    return github_save_file($owner, $repo, '', $path, $content, $message, '', $token, $error);
}

function github_commit_files_via_contents($owner, $repo, $branch, $files, $message, $token, &$error) {
    $last = null;
    foreach ($files as $file) {
        $path = github_clean_path($file['path'] ?? '');
        if ($path === '' || !array_key_exists('content', $file)) continue;

        $lookupError = '';
        $existing = github_get_file($owner, $repo, $branch, $path, $token, $lookupError);
        $sha = $existing['sha'] ?? '';
        $saveError = '';
        $saved = github_save_file_resilient(
            $owner,
            $repo,
            $branch,
            $path,
            (string)$file['content'],
            $message ?: 'Update ' . $path . ' from Libre Claude',
            $sha,
            $token,
            $saveError
        );
        if (!$saved) {
            $error = $path . ': ' . ($saveError ?: $lookupError ?: 'publication impossible');
            return null;
        }
        $last = $saved;
    }

    if (!$last) {
        $error = 'No files';
        return null;
    }
    return $last;
}

function github_create_repo($name, $description, $private, $token, &$error) {
    $body = [
        'name' => $name,
        'description' => $description,
        'private' => $private,
        'auto_init' => true,
    ];
    return github_api_request('POST', 'https://api.github.com/user/repos', $token, $body, $error);
}

function github_create_repo_error_message($apiError, $t) {
    if (stripos($apiError, 'Resource not accessible by integration') !== false) {
        return $t('repo_create_error') . ' ' . $t('github_repo_create_permission_error') . ' ' . $apiError;
    }
    return $t('repo_create_error') . ' ' . $apiError;
}

function github_publish_error_message($apiError, $t) {
    if (stripos($apiError, 'Resource not accessible by integration') !== false) {
        return $t('multi_file_error') . ' GitHub refuse l écriture avec ce jeton. Dans votre GitHub App, activez Repository permissions > Contents: Read and write, vérifiez que l app est installée sur ce dépôt, puis reconnectez GitHub dans Libre Claude. ' . $apiError;
    }
    return $t('multi_file_error') . ' ' . $apiError;
}

function github_user_repos($token, &$error) {
    if (!$token) return [];
    $url = 'https://api.github.com/user/repos?per_page=100&sort=updated&affiliation=owner,collaborator,organization_member';
    $data = github_api_request('GET', $url, $token, null, $error);
    if (!$data || !is_array($data)) return [];
    return $data;
}

function workspace_load_coder_state($db, $userId, $github) {
    if (!$github || empty($github['owner']) || empty($github['repo'])) return null;
    return $db->fetch(
        "SELECT * FROM workspace_coder_states
         WHERE user_id = ? AND owner = ? AND repo = ? AND branch = ?
         LIMIT 1",
        [$userId, $github['owner'], $github['repo'], $github['branch'] ?: 'main']
    );
}

function workspace_save_coder_state($db, $userId, $github, $prompt, $contextPaths, $rawReply, $filesJson) {
    if (!$github || empty($github['owner']) || empty($github['repo'])) return;
    $branch = $github['branch'] ?: 'main';
    $db->query(
        "INSERT OR REPLACE INTO workspace_coder_states
         (id, user_id, owner, repo, branch, prompt, context_paths, raw_reply, files_json, created_at, updated_at)
         VALUES (
            (SELECT id FROM workspace_coder_states WHERE user_id = ? AND owner = ? AND repo = ? AND branch = ?),
            ?, ?, ?, ?, ?, ?, ?, ?,
            COALESCE((SELECT created_at FROM workspace_coder_states WHERE user_id = ? AND owner = ? AND repo = ? AND branch = ?), datetime('now')),
            datetime('now')
         )",
        [
            $userId, $github['owner'], $github['repo'], $branch,
            $userId, $github['owner'], $github['repo'], $branch, $prompt, $contextPaths, $rawReply, $filesJson,
            $userId, $github['owner'], $github['repo'], $branch,
        ]
    );
}

function workspace_extract_json_array($text) {
    $text = trim($text);
    if ($text === '') return null;

    if (preg_match('/```(?:json)?\s*(\[.*?\])\s*```/is', $text, $m)) {
        $decoded = json_decode($m[1], true);
        if (is_array($decoded)) return $decoded;
    }

    $decoded = json_decode($text, true);
    if (is_array($decoded)) return $decoded;

    $start = strpos($text, '[');
    $end = strrpos($text, ']');
    if ($start !== false && $end !== false && $end > $start) {
        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
        if (is_array($decoded)) return $decoded;
    }

    return null;
}

function workspace_clean_generated_files($files) {
    if (!is_array($files)) return [];
    $clean = [];
    foreach ($files as $file) {
        if (!is_array($file)) continue;
        $path = trim($file['path'] ?? '');
        if ($path === '' || !array_key_exists('content', $file)) continue;
        $clean[] = ['path' => $path, 'content' => (string)$file['content']];
    }
    return $clean;
}

function workspace_generated_files_from_json($filesJson) {
    $decoded = json_decode((string)$filesJson, true);
    return workspace_clean_generated_files($decoded);
}

function workspace_coder_bubble_reply($filesJson, $rawReply, $t) {
    $files = workspace_generated_files_from_json($filesJson);
    if (!$files) {
        return $rawReply;
    }

    $paths = array_map(fn($file) => '- ' . $file['path'], array_slice($files, 0, 8));
    $extra = count($files) > 8 ? "\n- +" . (count($files) - 8) . " fichier(s)" : '';
    return sprintf($t('ai_coder_generated_summary'), count($files))
        . "\n" . implode("\n", $paths) . $extra
        . "\n\n" . $t('ai_coder_review_hint');
}

function workspace_ai_code($instruction, $contextFiles, $repoFiles, $user, &$rawReply, &$error) {
    $treeLines = [];
    foreach (array_slice($repoFiles, 0, 120) as $item) {
        $treeLines[] = '- ' . ($item['path'] ?? '');
    }

    $context = '';
    foreach ($contextFiles as $file) {
        $content = (string)($file['content'] ?? '');
        if (strlen($content) > 18000) {
            $content = substr($content, 0, 18000) . "\n/* tronque */";
        }
        $context .= "\n\n--- FILE: " . ($file['path'] ?? 'unknown') . " ---\n" . $content;
    }

    $system = "Tu es Libre Claude Coder, un agent de code integre a Libre Claude. Tu modifies un depot GitHub en proposant des fichiers complets ou nouveaux fichiers. Reponds uniquement avec un JSON valide, sans markdown, sous forme de tableau. Chaque entree doit avoir exactement: path, content. N'invente pas de fichiers inutiles. Respecte la demande utilisateur.";
    $userPrompt = "Depot connecte. Arborescence disponible:\n" . implode("\n", $treeLines)
        . "\n\nFichiers fournis en contexte:" . ($context ?: "\nAucun fichier complet fourni.")
        . "\n\nDemande utilisateur:\n" . $instruction
        . "\n\nRetour attendu: JSON array strict [{\"path\":\"...\",\"content\":\"...\"}].";

    $client = getClaudeClient($user['mistral_api_key'] ?? null);
    $result = $client->chat([
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $userPrompt],
    ], defined('MASTER_AGENT_MODEL') ? MASTER_AGENT_MODEL : 'mistral-large-2512', [
        'temperature' => 0.25,
        'max_tokens' => 8192,
    ]);

    if (empty($result['success'])) {
        $error = $result['error'] ?? 'Generation IA impossible.';
        return null;
    }

    $rawReply = trim($result['content'] ?? '');
    $files = workspace_extract_json_array($rawReply);
    if (!is_array($files)) {
        $error = 'La reponse IA ne contient pas de JSON multi-fichiers valide.';
        return null;
    }

    $clean = workspace_clean_generated_files($files);

    if (!$clean) {
        $error = 'Aucun fichier exploitable genere par l IA.';
        return null;
    }

    return $clean;
}

function github_commit_files($owner, $repo, $branch, $files, $message, $token, &$error) {
    $branch = $branch ?: 'main';
    $cleanFiles = [];
    $treeItems = [];
    foreach ($files as $file) {
        $path = github_clean_path($file['path'] ?? '');
        if ($path === '' || !array_key_exists('content', $file)) continue;
        $cleanFiles[] = ['path' => $path, 'content' => (string)$file['content']];
        $treeItems[] = [
            'path' => $path,
            'mode' => '100644',
            'type' => 'blob',
            'content' => (string)$file['content'],
        ];
    }
    if (!$treeItems) {
        $error = 'No files';
        return null;
    }

    $repoBase = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo);
    $refUrl = $repoBase . '/git/ref/heads/' . rawurlencode($branch);
    $refError = '';
    $ref = github_api_request('GET', $refUrl, $token, null, $refError);
    $isEmptyRepo = !$ref && (stripos($refError, 'Git Repository is empty') !== false || stripos($refError, 'HTTP 409') !== false);
    if (!$ref && !$isEmptyRepo && stripos($refError, 'HTTP 404') !== false) {
        $defaultError = '';
        $defaultBranch = github_default_branch($owner, $repo, $token, $defaultError);
        if ($defaultBranch !== '' && $defaultBranch !== $branch) {
            $branch = $defaultBranch;
            $refUrl = $repoBase . '/git/ref/heads/' . rawurlencode($branch);
            $refError = '';
            $ref = github_api_request('GET', $refUrl, $token, null, $refError);
            $isEmptyRepo = !$ref && (stripos($refError, 'Git Repository is empty') !== false || stripos($refError, 'HTTP 409') !== false);
        } elseif ($defaultError !== '') {
            $refError = $refError . ' - ' . $defaultError;
        }
    }
    $parentSha = '';
    $baseTreeSha = '';

    if ($isEmptyRepo) {
        $first = array_shift($cleanFiles);
        $initial = github_create_initial_file(
            $owner,
            $repo,
            $branch,
            $first['path'],
            $first['content'],
            $message ?: 'Initial commit from Libre Claude',
            $token,
            $error
        );
        if (!$initial) return null;
        if (!$cleanFiles) return $initial;

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
        $ref = github_api_request('GET', $refUrl, $token, null, $refError);
        $isEmptyRepo = false;
    }

    if (!$isEmptyRepo) {
        if (!$ref || empty($ref['object']['sha'])) {
            $error = $refError ?: 'Branche GitHub introuvable';
            return null;
        }
        $parentSha = $ref['object']['sha'];
        $parentCommit = github_api_request('GET', $repoBase . '/git/commits/' . rawurlencode($parentSha), $token, null, $error);
        if (!$parentCommit || empty($parentCommit['tree']['sha'])) return null;
        $baseTreeSha = $parentCommit['tree']['sha'];
    }

    $treeBody = ['tree' => $treeItems];
    if ($baseTreeSha !== '') $treeBody['base_tree'] = $baseTreeSha;
    $tree = github_api_request('POST', $repoBase . '/git/trees', $token, $treeBody, $error);
    if (!$tree || empty($tree['sha'])) return null;

    $commitBody = [
        'message' => $message ?: 'Update files from Libre Claude',
        'tree' => $tree['sha'],
        'parents' => $parentSha !== '' ? [$parentSha] : [],
    ];
    $newCommit = github_api_request('POST', $repoBase . '/git/commits', $token, $commitBody, $error);
    if (!$newCommit || empty($newCommit['sha'])) return null;

    if ($isEmptyRepo) {
        return github_api_request('POST', $repoBase . '/git/refs', $token, [
            'ref' => 'refs/heads/' . $branch,
            'sha' => $newCommit['sha'],
        ], $error);
    }

    return github_api_request('PATCH', $refUrl, $token, [
        'sha' => $newCommit['sha'],
        'force' => false,
    ], $error);
}

$github = $db->fetch("SELECT * FROM workspace_github WHERE user_id = ?", [$user['id']]);
if ($github && trim($github['owner'] ?? '') === '' && trim($github['repo'] ?? '') === '') {
    $github = array_merge($github, ['owner' => '', 'repo' => '']);
}
$oauthEnabled = GITHUB_OAUTH_CLIENT_ID !== '' && GITHUB_OAUTH_CLIENT_SECRET !== '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'connect';

    if ($action === 'disconnect_github') {
        $db->delete('workspace_github', 'user_id = ?', [$user['id']]);
        $github = null;
        $success = $t('github_disconnected');
    } elseif ($action === 'connect') {
        $repoUrl = trim($_POST['repo_url'] ?? '');
        if ($repoUrl === '' && !empty($_POST['repo_full_name'])) $repoUrl = trim($_POST['repo_full_name']);
        $branch = trim($_POST['branch'] ?? 'main') ?: 'main';
        $tokenInput = trim($_POST['token'] ?? '');
        $parsed = parse_github_repo($repoUrl);

        if (!$parsed) {
            $error = $t('github_invalid');
        } else {
            [$owner, $repo] = $parsed;
            $token = $tokenInput !== '' ? $tokenInput : ($github['token'] ?? '');
            $db->query(
                "INSERT OR REPLACE INTO workspace_github (user_id, repo_url, owner, repo, branch, token, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, datetime('now'))",
                [$user['id'], $repoUrl, $owner, $repo, $branch, $token]
            );
            $success = $t('github_saved');
            $github = $db->fetch("SELECT * FROM workspace_github WHERE user_id = ?", [$user['id']]);
        }
    } elseif ($action === 'create_repo') {
        $token = trim($_POST['create_token'] ?? '') ?: ($github['token'] ?? '');
        $repoName = trim($_POST['repo_name'] ?? '');
        $description = trim($_POST['repo_description'] ?? '');
        $isPrivate = !empty($_POST['private']);

        if ($token === '') {
            $error = $t('github_token_required');
        } elseif (!preg_match('/^[A-Za-z0-9_.-]+$/', $repoName)) {
            $error = $t('github_invalid');
        } else {
            $apiError = '';
            $created = github_create_repo($repoName, $description, $isPrivate, $token, $apiError);
            if (!$created) {
                $error = github_create_repo_error_message($apiError, $t);
            } else {
                $owner = $created['owner']['login'] ?? '';
                $repo = $created['name'] ?? $repoName;
                $branch = $created['default_branch'] ?? 'main';
                $repoUrl = $created['html_url'] ?? ($owner . '/' . $repo);
                $db->query(
                    "INSERT OR REPLACE INTO workspace_github (user_id, repo_url, owner, repo, branch, token, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, datetime('now'))",
                    [$user['id'], $repoUrl, $owner, $repo, $branch, $token]
                );
                $success = $t('repo_created');
                $github = $db->fetch("SELECT * FROM workspace_github WHERE user_id = ?", [$user['id']]);
            }
        }
    } elseif ($action === 'ai_code') {
        $aiPrompt = trim($_POST['ai_prompt'] ?? '');
        $aiContextPaths = trim($_POST['ai_context_paths'] ?? '');
        $selectedRepo = trim($_POST['repo_full_name'] ?? '');
        $selectedBranch = trim($_POST['branch'] ?? ($github['branch'] ?? 'main')) ?: 'main';
        if ($selectedRepo !== '') {
            $parsedRepo = parse_github_repo($selectedRepo);
            if ($parsedRepo) {
                [$owner, $repo] = $parsedRepo;
                $db->query(
                    "INSERT OR REPLACE INTO workspace_github (user_id, repo_url, owner, repo, branch, token, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, datetime('now'))",
                    [$user['id'], $selectedRepo, $owner, $repo, $selectedBranch, $github['token'] ?? '']
                );
                $github = $db->fetch("SELECT * FROM workspace_github WHERE user_id = ?", [$user['id']]);
            }
        }

        if (!$github || empty($github['owner']) || empty($github['repo'])) {
            $error = $t('no_repo_connected');
        } elseif (empty($github['token'])) {
            $error = $t('github_token_required');
        } elseif ($aiPrompt === '') {
            $error = $t('ai_coder_prompt_required');
        } else {
            $contextFiles = [];
            $paths = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $aiContextPaths))));
            foreach (array_slice($paths, 0, 8) as $path) {
                $ctxError = '';
                $file = github_get_file($github['owner'], $github['repo'], $github['branch'], $path, $github['token'], $ctxError);
                if ($file) $contextFiles[] = $file;
            }

            $apiError = '';
            $aiRepoFiles = github_tree($github['owner'], $github['repo'], $github['branch'], $github['token'], $apiError);
            $rawReply = '';
            $generated = workspace_ai_code($aiPrompt, $contextFiles, $aiRepoFiles, $user, $rawReply, $apiError);
            $aiCodeReply = $rawReply;
            if (!$generated) {
                $error = $t('ai_coder_error') . ' ' . $apiError;
                workspace_save_coder_state($db, $user['id'], $github, $aiPrompt, $aiContextPaths, $aiCodeReply, '');
            } else {
                $aiFilesDraft = json_encode($generated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                workspace_save_coder_state($db, $user['id'], $github, $aiPrompt, $aiContextPaths, $aiCodeReply, $aiFilesDraft);
                $success = $t('ai_coder_ready');
            }
        }
    } elseif ($action === 'save_file') {
        if (!$github) {
            $error = $t('no_repo_connected');
        } elseif (empty($github['token'])) {
            $error = $t('github_token_required');
        } else {
            $path = trim($_POST['file_path'] ?? '');
            $content = $_POST['file_content'] ?? '';
            $message = trim($_POST['commit_message'] ?? '');
            $sha = trim($_POST['file_sha'] ?? '');

            if ($path === '') {
                $error = $t('file_path') . ' ' . $t('required_suffix');
            } else {
                if ($sha === '') {
                    $lookupError = '';
                    $existing = github_get_file($github['owner'], $github['repo'], $github['branch'], $path, $github['token'], $lookupError);
                    if ($existing && !empty($existing['sha'])) $sha = $existing['sha'];
                }

                $apiError = '';
                $saved = github_save_file($github['owner'], $github['repo'], $github['branch'], $path, $content, $message, $sha, $github['token'], $apiError);
                if (!$saved) {
                    $error = $t('file_save_error') . ' ' . $apiError;
                } else {
                    $success = $t('file_saved');
                    $_GET['path'] = $path;
                }
            }
        }
    } elseif ($action === 'save_many_files') {
        if (!$github || empty($github['owner']) || empty($github['repo'])) {
            $error = $t('no_repo_connected');
        } elseif (empty($github['token'])) {
            $error = $t('github_token_required');
        } else {
            $payload = trim($_POST['multi_files'] ?? '');
            $message = trim($_POST['multi_commit_message'] ?? '');
            $items = json_decode($payload, true);
            if (!is_array($items)) {
                $error = $t('multi_file_invalid');
            } else {
                $filesToCommit = [];
                foreach ($items as $item) {
                    if (!is_array($item)) continue;
                    $path = trim($item['path'] ?? '');
                    if ($path === '' || !array_key_exists('content', $item)) continue;
                    $filesToCommit[] = [
                        'path' => $path,
                        'content' => (string)$item['content'],
                    ];
                }
                if (!$filesToCommit) {
                    $error = $t('multi_file_invalid');
                } else {
                    $apiError = '';
                    $committed = github_commit_files($github['owner'], $github['repo'], $github['branch'], $filesToCommit, $message, $github['token'], $apiError);
                    if (!$committed && stripos($apiError, 'HTTP 404') !== false) {
                        $fallbackError = '';
                        $committed = github_commit_files_via_contents($github['owner'], $github['repo'], $github['branch'], $filesToCommit, $message, $github['token'], $fallbackError);
                        if (!$committed) $apiError = $fallbackError ?: $apiError;
                    }
                    if (!$committed) {
                        $error = github_publish_error_message($apiError, $t);
                    } else {
                        $success = $t('multi_file_saved');
                    }
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $github && !empty($github['owner']) && !empty($github['repo'])) {
    $savedCoderState = workspace_load_coder_state($db, $user['id'], $github);
    if ($savedCoderState) {
        $aiPrompt = (string)($savedCoderState['prompt'] ?? '');
        $aiContextPaths = (string)($savedCoderState['context_paths'] ?? '');
        $aiCodeReply = (string)($savedCoderState['raw_reply'] ?? '');
        $aiFilesDraft = (string)($savedCoderState['files_json'] ?? '');
    }
}

$files = $db->fetchAll(
    "SELECT id, name, language, content, created_at, updated_at
     FROM workspace_files
     WHERE user_id = ?
     ORDER BY updated_at DESC
     LIMIT 40",
    [$user['id']]
);

$treeError = '';
$repoFiles = [];
$reposError = '';
$authorizedRepos = [];
if ($github && !empty($github['token'])) {
    $authorizedRepos = github_user_repos($github['token'], $reposError);
}
if ($github && !empty($github['owner']) && !empty($github['repo'])) {
    $repoFiles = github_tree($github['owner'], $github['repo'], $github['branch'], $github['token'], $treeError);
    $repoFiles = array_slice($repoFiles, 0, 80);
}

$selectedPath = trim($_GET['path'] ?? '');
$selectedFile = null;
$selectedError = '';
if ($github && !empty($github['owner']) && !empty($github['repo']) && $selectedPath !== '') {
    $selectedFile = github_get_file($github['owner'], $github['repo'], $github['branch'], $selectedPath, $github['token'], $selectedError);
    if (!$selectedFile && !$error) $error = $t('file_load_error') . ' ' . $selectedError;
}
$aiBubbleReply = workspace_coder_bubble_reply($aiFilesDraft, $aiCodeReply, $t);
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($t('workspace_title')) ?> - Libre Claude</title>
<link rel="icon" href="libre-claude-icon.png" type="image/png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#09090f;--sidebar:#0f0f18;--panel:#151520;--card:#11111a;--line:#282838;--text:#f2f0f6;--muted:#8d899d;--soft:#1c1c29;--brand:#e6122a;--brand2:#ff3b4f;--err:#ff8a8a;--success:#4ade80;--shadow:0 22px 70px rgba(0,0,0,.38)}
body{background:radial-gradient(ellipse 55% 35% at 55% -12%,rgba(230,18,42,.17),transparent),var(--bg);color:var(--text);font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;min-height:100vh}
button,input,textarea,select{font:inherit}
.app{display:grid;grid-template-columns:260px 1fr;min-height:100vh;transition:grid-template-columns .18s ease}
.app.sidebar-collapsed{grid-template-columns:72px 1fr}
.side{background:var(--sidebar);border-right:1px solid var(--line);padding:18px 16px;display:flex;flex-direction:column;gap:22px}
.app.sidebar-collapsed .side{padding-left:14px;padding-right:14px}
.side-top{display:flex;align-items:center;justify-content:space-between}
.mark{width:32px;height:32px;border-radius:8px;display:block;object-fit:contain}
.side-toggle{border:0;background:transparent;color:var(--text);font-size:17px}
.app.sidebar-collapsed .side-top{flex-direction:column;gap:14px;justify-content:flex-start}
.app.sidebar-collapsed .mark{width:34px;height:34px}
.app.sidebar-collapsed .side-toggle{width:34px;height:34px;display:grid;place-items:center;border-radius:10px;background:rgba(255,255,255,.04)}
.side-link,.task{display:flex;align-items:center;gap:11px;color:var(--text);text-decoration:none;border-radius:10px;padding:9px 7px;font-size:14px}
.side-link:hover,.task:hover{background:var(--soft)}
.side-section{display:flex;flex-direction:column;gap:8px}
.section-head{display:flex;align-items:center;justify-content:space-between;color:var(--muted);font-size:12px;margin:12px 7px 6px}
.task{align-items:flex-start}
.task i{margin-top:2px;color:var(--brand)}
.task-title{display:block;font-size:14px}
.task-meta{display:block;color:var(--muted);font-size:12px;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:180px}
.app.sidebar-collapsed .side-link{justify-content:center;padding-left:0;padding-right:0}
.app.sidebar-collapsed .side-link i,.app.sidebar-collapsed .task i{margin:0}
.app.sidebar-collapsed .side-link{font-size:0;gap:0}
.app.sidebar-collapsed .side-link i{font-size:15px}
.app.sidebar-collapsed .section-head span,.app.sidebar-collapsed .section-head i,.app.sidebar-collapsed .task span,.app.sidebar-collapsed .user-pill span:not(.avatar){display:none}
.app.sidebar-collapsed .task{justify-content:center;padding-left:0;padding-right:0}
.app.sidebar-collapsed .user-pill{justify-content:center}
.side-spacer{flex:1}
.user-pill{display:flex;align-items:center;gap:10px;font-weight:650}
.avatar{width:28px;height:28px;border-radius:999px;background:var(--brand);color:white;display:grid;place-items:center;font-size:13px}
.main{position:relative;display:flex;flex-direction:column;align-items:center;padding:44px 40px 64px}
.top-actions{position:absolute;right:22px;top:14px;display:flex;gap:10px}
.pill-btn,.chip,.btn{border:1px solid var(--line);background:rgba(255,255,255,.035);color:var(--text);border-radius:999px;padding:9px 14px;text-decoration:none;display:inline-flex;align-items:center;gap:8px;font-size:14px;cursor:pointer}
.pill-btn:hover,.chip:hover,.btn:hover{background:rgba(255,255,255,.065);border-color:#3b3b4e}
.hero{width:min(800px,100%);margin-top:150px;text-align:center}
h1{font-size:34px;line-height:1.1;font-weight:760;letter-spacing:0;margin-bottom:4px}
.sub{color:var(--muted);font-size:16px;margin-bottom:30px}
.composer{background:var(--panel);border:1px solid var(--line);border-radius:22px;padding:12px 12px 11px;box-shadow:var(--shadow);text-align:left}
.selectors{display:grid;grid-template-columns:1.4fr .85fr;gap:8px;margin-bottom:6px}
.select-shell{position:relative}
.select-shell i{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--text);pointer-events:none}
select,.branch-input,input,textarea{width:100%;border:1px solid var(--line);background:#0d0d15;color:var(--text);border-radius:18px;padding:10px 38px;font-size:14px;outline:none}
select option{background:#0d0d15;color:var(--text)}
.branch-input{padding-left:38px}
select:focus,input:focus,textarea:focus{border-color:rgba(255,59,79,.72);box-shadow:0 0 0 3px rgba(230,18,42,.16)}
.prompt-wrap{position:relative}
.prompt{min-height:120px;border:0;background:transparent;border-radius:14px;padding:16px 16px 58px;resize:vertical;font-size:16px;color:var(--text)}
.prompt::placeholder{color:#777486}
.send{position:absolute;right:10px;bottom:10px;border:0;border-radius:999px;background:#292938;color:#8f8c9b;padding:9px 16px;display:flex;align-items:center;gap:10px;font-weight:650;cursor:pointer}
.send.ready{background:linear-gradient(135deg,var(--brand),var(--brand2));color:white}
.quick{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-top:28px}
.chip{background:#0d0d15}
.status{width:min(800px,100%);margin-top:18px}
.msg{padding:12px 14px;border-radius:12px;font-size:13.5px;margin-bottom:10px;text-align:left}
.ok{background:rgba(74,222,128,.08);border:1px solid rgba(74,222,128,.25);color:var(--success)}
.err{background:rgba(255,138,138,.08);border:1px solid rgba(255,138,138,.28);color:var(--err)}
.workspace-panels{width:min(1040px,100%);display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:36px}
.workspace-tabs{width:min(1040px,100%);display:flex;gap:8px;margin-top:34px}
.workspace-tab{border:1px solid var(--line);background:#0d0d15;color:var(--text);border-radius:999px;padding:9px 14px;cursor:pointer}
.workspace-tab.active{background:linear-gradient(135deg,var(--brand),var(--brand2));border-color:transparent;color:white}
.tab-panel{display:none;width:min(1040px,100%)}
.tab-panel.active{display:block}
.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:18px;text-align:left}
.card h2{font-size:14px;font-weight:760;margin-bottom:12px}
label{display:block;font-size:12px;color:var(--muted);margin:12px 0 7px}
.hint,.meta{font-size:12px;color:var(--muted);line-height:1.45}
.wide{grid-column:1/-1}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
.draft{min-height:240px;border-radius:14px;padding:12px;font-size:13px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;line-height:1.5}
.generated-review{display:grid;grid-template-columns:280px 1fr;gap:14px;margin-top:14px}
.generated-list{border:1px solid var(--line);border-radius:14px;overflow:hidden;background:#0b0b12;max-height:420px;overflow-y:auto}
.generated-item{width:100%;border:0;border-bottom:1px solid var(--line);background:transparent;color:var(--text);padding:11px 12px;text-align:left;display:block;cursor:pointer}
.generated-item:hover,.generated-item.active{background:rgba(230,18,42,.12)}
.generated-item strong{display:block;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.generated-item span{display:block;color:var(--muted);font-size:12px;margin-top:3px}
.review-pane{border:1px solid var(--line);border-radius:14px;overflow:hidden;background:#0b0b12;min-height:420px;display:grid;grid-template-rows:auto 1fr}
.review-tabs{display:flex;gap:6px;padding:8px;border-bottom:1px solid var(--line);background:#10101a}
.review-tab{border:1px solid var(--line);background:#0d0d15;color:var(--text);border-radius:999px;padding:7px 11px;cursor:pointer;font-size:12px}
.review-tab.active{background:linear-gradient(135deg,var(--brand),var(--brand2));border-color:transparent;color:white}
.review-body{min-height:0}
.review-code,.review-diff{height:100%;min-height:360px;overflow:auto;padding:14px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12.5px;line-height:1.55;white-space:pre;color:#e5e1ec}
.review-frame{width:100%;height:100%;min-height:360px;border:0;background:white}
.review-empty{padding:18px;color:var(--muted);font-size:13px}
.review-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
.file-list{max-height:380px;overflow:auto}
.file-row{display:grid;grid-template-columns:1fr auto;gap:10px;align-items:center;padding:10px 0;border-bottom:1px solid var(--line)}
.file-row:last-child{border-bottom:0}
.file-name{font-weight:650;font-size:13.5px;overflow:hidden;text-overflow:ellipsis}
.path-link{color:var(--text);text-decoration:none}
.path-link:hover{color:var(--brand)}
.btn.primary{background:linear-gradient(135deg,var(--brand),var(--brand2));border-color:transparent;color:white}
.btn.secondary{background:#0d0d15}
.inline-check{display:flex;align-items:center;gap:8px;color:var(--muted);font-size:13px;margin:10px 0}
.inline-check input{width:auto}
.code-box{margin-top:10px;background:#08080d;border:1px solid var(--line);border-radius:12px;padding:12px;max-height:180px;overflow:auto;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12.5px;color:#ddd9e7;white-space:pre}
.preview-panel{position:fixed;inset:24px;background:#0d0d15;border:1px solid var(--line);border-radius:14px;z-index:50;display:none;flex-direction:column;box-shadow:0 26px 90px rgba(0,0,0,.6)}
.preview-panel.open{display:flex}
.preview-head{height:48px;display:flex;align-items:center;justify-content:space-between;padding:0 14px;border-bottom:1px solid var(--line)}
.preview-frame{flex:1;width:100%;border:0;background:white;border-radius:0 0 14px 14px}
.main{align-items:stretch;padding:0;min-width:0}
.top-actions{position:absolute;right:18px;top:10px;z-index:5}
.coder-shell{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:44px 40px 64px}
.coder-shell.coder-active{display:grid;grid-template-columns:minmax(360px,42%) minmax(0,58%);align-items:stretch;justify-content:stretch;height:100vh;min-height:720px;padding:0}
.coder-shell.environment-open:not(.coder-active){flex-direction:column;align-items:center;justify-content:flex-start;padding-top:82px}
.coder-chat{display:flex;flex-direction:column;gap:16px;min-width:0;width:min(800px,100%);color:var(--text)}
.coder-shell.coder-active .coder-chat{width:auto;padding:52px 20px 18px;background:#0b0b12;border-right:1px solid var(--line)}
.coder-chat h1{font-size:34px;line-height:1.1;color:var(--text);margin:0;text-align:center}
.coder-shell.coder-active .coder-chat h1{font-size:16px;text-align:left}
.coder-chat .sub{font-size:16px;color:var(--muted);margin:4px 0 14px;text-align:center}
.coder-shell.coder-active .coder-chat .sub{font-size:13px;margin:0;text-align:left}
.coder-chat .status{width:100%;margin:0}
.coder-thread{flex:1;overflow:auto;padding-right:4px}
.coder-shell:not(.coder-active) .coder-thread{display:none}
.coder-empty{height:100%;display:flex;align-items:center;justify-content:center;text-align:center;color:var(--muted);font-size:14px;line-height:1.5}
.coder-turn{margin-bottom:18px}
.coder-bubble{max-width:90%;padding:13px 15px;border-radius:18px;font-size:14px;line-height:1.55;white-space:pre-wrap}
.coder-bubble.user{margin-left:auto;background:#1e1e2b;color:var(--text)}
.coder-bubble.assistant{background:transparent;color:var(--text);padding-left:0}
.coder-work-label{display:flex;align-items:center;gap:9px;font-weight:750;color:var(--text);margin:0 0 10px}
.coder-work-label span{font-weight:500;color:var(--muted);font-size:13px}
.coder-pending{display:inline-flex;align-items:center;gap:8px;color:var(--muted)}
.coder-pending i{color:var(--brand)}
.composer{background:var(--panel);border-color:var(--line);border-radius:22px;box-shadow:var(--shadow)}
.selectors{grid-template-columns:1fr 128px}
.select-shell i{color:var(--text)}
select,.branch-input,input,textarea{background:#0d0d15;color:var(--text);border-color:var(--line)}
.prompt{min-height:96px;color:var(--text)}
.prompt::placeholder{color:#777486}
.send{background:#292938;color:#8f8c9b}
.send.ready{background:linear-gradient(135deg,var(--brand),var(--brand2));color:white}
.quick{justify-content:center;margin-top:22px;gap:10px}
.coder-shell.coder-active .quick{justify-content:flex-start;margin-top:0;gap:8px}
.chip{background:#0d0d15;color:var(--text);border-color:var(--line)}
.coder-code-panel{min-width:0;padding:48px 18px 18px;background:#09090f;color:var(--text);overflow:auto}
.coder-shell:not(.coder-active):not(.environment-open) .coder-code-panel{display:none}
.coder-shell:not(.coder-active) .coder-code-panel{width:min(1040px,100%);padding:0;margin-top:34px;background:transparent;overflow:visible}
.coder-code-panel .workspace-tabs{width:100%;margin:0 0 10px;justify-content:center}
.coder-code-panel .workspace-tab{background:#0d0d15;color:var(--text);border-color:var(--line);padding:7px 14px}
.coder-code-panel .workspace-tab.active{background:linear-gradient(135deg,var(--brand),var(--brand2));color:white}
.coder-code-panel .tab-panel{width:100%}
.coder-code-panel .workspace-panels{width:100%;margin:0;grid-template-columns:1fr}
.coder-shell:not(.coder-active) .coder-code-panel .workspace-panels{grid-template-columns:1fr 1fr}
.coder-code-panel .card{background:transparent;border:0;border-radius:0;padding:0;color:var(--text)}
.coder-shell:not(.coder-active) .coder-code-panel .card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:18px}
.coder-code-panel .card h2{display:none}
.coder-shell:not(.coder-active) .coder-code-panel .card h2{display:block}
.coder-code-panel label,.coder-code-panel .hint,.coder-code-panel .meta{color:var(--muted)}
.coder-code-panel .draft{min-height:110px;max-height:170px;background:#0d0d15;color:var(--text);border-color:var(--line)}
.coder-code-panel .generated-review{grid-template-columns:220px minmax(0,1fr);gap:14px;height:calc(100vh - 255px);min-height:470px}
.coder-code-panel .generated-list{background:#0b0b12;border-color:var(--line);max-height:none}
.coder-code-panel .generated-item{color:var(--text);border-color:var(--line)}
.coder-code-panel .generated-item:hover,.coder-code-panel .generated-item.active{background:rgba(230,18,42,.12)}
.coder-code-panel .review-pane{background:#0b0b12;border-color:var(--line);min-height:0;height:100%;border-radius:14px}
.coder-code-panel .review-tabs{justify-content:center;background:#10101a;border-color:var(--line)}
.coder-code-panel .review-tab{background:#0d0d15;color:var(--text);border-color:var(--line)}
.coder-code-panel .review-tab.active{background:linear-gradient(135deg,var(--brand),var(--brand2));color:#fff}
.coder-code-panel .review-code,.coder-code-panel .review-diff{min-height:0;color:#e5e1ec;background:#0b0b12}
.coder-code-panel .review-empty{color:var(--muted)}
.coder-code-panel .btn.secondary{background:#0d0d15;color:var(--text);border-color:var(--line)}
.coder-code-panel .btn.primary{background:linear-gradient(135deg,var(--brand),var(--brand2))}
.coder-code-panel .file-list{max-height:280px}
@media(max-width:1100px){.coder-shell{grid-template-columns:1fr;height:auto}.coder-chat{min-height:520px;border-right:0;border-bottom:1px solid #dbdce5}.coder-code-panel .generated-review{height:auto;min-height:520px}}
@media(max-width:900px){.app{grid-template-columns:1fr}.side{position:static;border-right:0;border-bottom:1px solid var(--line)}.main{padding:0}.coder-shell{height:auto;min-height:0}.coder-chat{padding:28px 14px}.selectors,.workspace-panels,.coder-code-panel .generated-review{grid-template-columns:1fr}.top-actions{position:fixed;right:10px;top:10px}.preview-panel{inset:8px}}
</style>
</head>
<body>
<div class="app">
  <aside class="side">
    <div class="side-top">
      <img class="mark" src="libre-claude-icon.png" alt="Libre Claude">
      <button class="side-toggle" type="button" aria-label="Réduire la barre" onclick="toggleWorkspaceSidebar()"><i class="fa-solid fa-table-columns"></i></button>
    </div>
    <div class="side-section">
      <a class="side-link" href="index.php"><i class="fa-solid fa-plus"></i><?= htmlspecialchars($t('chat_libre')) ?></a>
      <a class="side-link" href="index.php"><i class="fa-solid fa-magnifying-glass"></i><?= htmlspecialchars($t('search')) ?></a>
    </div>
    <div class="side-section">
      <div class="section-head"><span>All Tasks</span><i class="fa-solid fa-arrow-down-short-wide"></i></div>
      <div class="section-head"><span>Previous 7 days</span></div>
      <a class="task" href="workspace.php">
        <i class="fa-solid fa-code-branch"></i>
        <span>
          <span class="task-title"><?= htmlspecialchars($github && !empty($github['repo']) ? $github['repo'] : $t('libre_coder')) ?></span>
          <span class="task-meta"><?= htmlspecialchars($github && !empty($github['owner']) ? $github['owner'] . ' / ' . ($github['branch'] ?: 'main') : $t('workspace_empty_hint')) ?></span>
        </span>
      </a>
    </div>
    <div class="side-spacer"></div>
    <div class="side-section">
      <a class="side-link" href="settings.php"><i class="fa-solid fa-gear"></i><?= htmlspecialchars($t('settings')) ?></a>
      <a class="side-link" href="api_tokens.php"><i class="fa-solid fa-key"></i><?= htmlspecialchars($t('api_keys')) ?></a>
      <div class="user-pill"><span class="avatar"><?= htmlspecialchars(strtoupper(mb_substr($user['username'], 0, 1))) ?></span><span><?= htmlspecialchars($user['username']) ?></span></div>
    </div>
  </aside>

  <main class="main">
    <div class="top-actions">
      <?php if ($aiFilesDraft !== ''): ?>
      <button class="pill-btn" type="button" onclick="publishGenerated()"><i class="fa-brands fa-github"></i> Publier GitHub</button>
      <?php else: ?>
      <button class="pill-btn" type="button" onclick="openWorkspaceEnvironment()"><i class="fa-solid fa-sliders"></i> Environnement</button>
      <?php endif; ?>
    </div>

    <div class="coder-shell <?= $aiPrompt ? 'coder-active' : '' ?>">
    <section class="coder-chat">
      <div>
        <h1><?= htmlspecialchars($t('libre_coder')) ?></h1>
        <p class="sub"><?= htmlspecialchars($github && !empty($github['owner']) ? $github['owner'] . '/' . ($github['repo'] ?? '') . ' · ' . ($github['branch'] ?: 'main') : $t('ai_coder_sub')) ?></p>
      </div>

      <div class="status">
        <?php if (($_GET['github'] ?? '') === 'connected'): ?><div class="msg ok"><?= htmlspecialchars($t('github_oauth_success')) ?></div><?php endif; ?>
        <?php if (!empty($_GET['github_error'])): ?><div class="msg err"><?= htmlspecialchars($t('github_oauth_failed')) ?><?php if ($githubOauthErrorDetail): ?><br><small><?= htmlspecialchars($githubOauthErrorDetail) ?></small><?php endif; ?></div><?php endif; ?>
        <?php if ($success): ?><div class="msg ok"><?= htmlspecialchars($success) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="msg err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
      </div>

      <div class="coder-thread">
        <?php if ($aiPrompt): ?>
          <div class="coder-turn">
            <div class="coder-bubble user"><?= htmlspecialchars($aiPrompt) ?></div>
          </div>
          <div class="coder-turn">
            <div class="coder-work-label">Work completed <span>· <?= htmlspecialchars(date('H:i')) ?></span></div>
            <div class="coder-bubble assistant"><?= htmlspecialchars($aiBubbleReply ?: $t('ai_coder_ready')) ?></div>
          </div>
        <?php else: ?>
          <div class="coder-empty">
            <div>
              <strong><?= htmlspecialchars($t('libre_coder')) ?></strong><br>
              <?= htmlspecialchars($t('ai_coder_sub')) ?>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <form class="composer" method="POST" id="coder-form">
        <input type="hidden" name="action" value="ai_code">
        <div class="selectors">
          <div class="select-shell">
            <i class="fa-brands fa-github"></i>
            <select name="repo_full_name">
              <?php if ($github && !empty($github['owner']) && !empty($github['repo'])): ?>
              <option value="<?= htmlspecialchars($github['owner'] . '/' . $github['repo']) ?>"><?= htmlspecialchars($github['owner'] . '/' . $github['repo']) ?></option>
              <?php else: ?>
              <option value=""><?= htmlspecialchars($t('github_repo_select')) ?></option>
              <?php endif; ?>
              <?php foreach ($authorizedRepos as $repoOption): ?>
              <?php $full = $repoOption['full_name'] ?? ''; if ($full === '') continue; ?>
              <option value="<?= htmlspecialchars($full) ?>" <?= (($github['owner'] ?? '') . '/' . ($github['repo'] ?? '')) === $full ? 'selected' : '' ?>><?= htmlspecialchars($full) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="select-shell">
            <i class="fa-solid fa-code-branch"></i>
            <input class="branch-input" name="branch" value="<?= htmlspecialchars($github['branch'] ?? 'main') ?>" placeholder="main">
          </div>
        </div>
        <div class="prompt-wrap">
          <textarea class="prompt" name="ai_prompt" id="ai-prompt" placeholder="Code your creativity here."><?= htmlspecialchars($aiPrompt) ?></textarea>
          <button class="send" id="code-send" type="submit"><span>Coding</span><i class="fa-solid fa-arrow-right"></i></button>
        </div>
        <textarea name="ai_context_paths" id="ai-context" hidden><?= htmlspecialchars($aiContextPaths) ?></textarea>
      </form>

      <div class="quick">
        <button class="chip" type="button" data-prompt="Refactor the code in this repository">Refactor the code in this repository</button>
        <button class="chip" type="button" data-prompt="Write unit tests for the important parts">Write unit tests</button>
        <button class="chip" type="button" data-prompt="Optimize performance and explain the changes in code comments only where useful">Optimize performance</button>
        <button class="chip" type="button" data-prompt="Generate a clean README for this repository">Generate README</button>
        <button class="chip" type="button" data-prompt="Review the project and propose the smallest useful code improvement">More</button>
      </div>
    </section>

    <section class="coder-code-panel">
    <div class="workspace-tabs">
      <button class="workspace-tab active" type="button" data-workspace-tab="publish">Publication</button>
      <button class="workspace-tab" type="button" data-workspace-tab="settings">Paramètres</button>
    </div>

    <section class="tab-panel active" id="workspace-tab-publish">
      <div class="workspace-panels" style="grid-template-columns:1fr">
        <div class="card wide">
          <h2><?= htmlspecialchars($t('review_generated_files')) ?></h2>
          <?php if (!$github || empty($github['owner']) || empty($github['repo'])): ?>
            <p class="meta"><?= htmlspecialchars($t('no_repo_connected')) ?></p>
          <?php else: ?>
          <form method="POST">
            <input type="hidden" name="action" value="save_many_files">
            <label><?= htmlspecialchars($t('commit_message')) ?></label>
            <input name="multi_commit_message" value="<?= htmlspecialchars($aiPrompt ? mb_substr($aiPrompt, 0, 90) : '') ?>" placeholder="<?= htmlspecialchars($t('commit_message_placeholder')) ?>">
            <label><?= htmlspecialchars($t('multi_file_payload')) ?></label>
            <textarea class="draft" id="multi-files-draft" name="multi_files" placeholder="<?= htmlspecialchars($t('multi_file_placeholder')) ?>"><?= htmlspecialchars($aiFilesDraft) ?></textarea>
            <p class="hint"><?= htmlspecialchars($t('multi_file_hint')) ?></p>
            <div class="review-actions">
              <button class="btn secondary" type="button" onclick="refreshGeneratedReview()"><i class="fa-solid fa-list-check"></i> Prévisualiser</button>
              <button class="btn secondary" type="button" onclick="applyGeneratedToCommit()"><i class="fa-solid fa-wand-magic-sparkles"></i> Appliquer au commit</button>
            </div>
            <div class="generated-review" id="generated-review">
              <div class="generated-list" id="generated-list">
                <div class="review-empty">Aucun fichier à prévisualiser.</div>
              </div>
              <div class="review-pane">
                <div class="review-tabs">
                  <button class="review-tab active" type="button" data-review-tab="diff">Différence</button>
                  <button class="review-tab" type="button" data-review-tab="preview">Aperçu</button>
                  <button class="review-tab" type="button" data-review-tab="log">Log</button>
                </div>
                <div class="review-body" id="review-body">
                  <pre class="review-code" id="review-code" hidden>Sélectionnez un fichier généré.</pre>
                  <iframe class="review-frame" id="review-frame" sandbox="allow-scripts allow-forms allow-modals" hidden></iframe>
                  <pre class="review-diff" id="review-diff">Sélectionnez un fichier généré.</pre>
                </div>
              </div>
            </div>
            <button class="btn primary" type="submit"><i class="fa-solid fa-code-commit"></i><?= htmlspecialchars($t('multi_file_commit')) ?></button>
          </form>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <section class="tab-panel" id="workspace-tab-settings">
      <div class="workspace-panels" id="environment">
      <div class="card">
        <h2><?= htmlspecialchars($t('connect_github')) ?></h2>
        <p class="hint"><?= htmlspecialchars($t('github_oauth_hint')) ?></p>
        <?php if ($github && !empty($github['token'])): ?>
        <div class="msg ok">
          <?= htmlspecialchars($t('github_connected_as')) ?>
          <?= htmlspecialchars(trim(($github['owner'] ?? '') . '/' . ($github['repo'] ?? ''), '/')) ?>
        </div>
        <form method="POST" onsubmit="return confirm(<?= htmlspecialchars(json_encode($t('github_disconnect_confirm')), ENT_QUOTES) ?>)">
          <input type="hidden" name="action" value="disconnect_github">
          <button class="btn" type="submit"><i class="fa-solid fa-link-slash"></i><?= htmlspecialchars($t('disconnect_github')) ?></button>
        </form>
        <?php endif; ?>
        <?php if ($oauthEnabled): ?>
        <p style="margin:12px 0"><a class="btn primary" href="github_oauth.php"><i class="fa-brands fa-github"></i><?= htmlspecialchars($t('github_oauth')) ?></a></p>
        <?php else: ?>
        <div class="msg err"><?= htmlspecialchars($t('github_oauth_config_missing')) ?></div>
        <?php endif; ?>
        <form method="POST">
          <input type="hidden" name="action" value="connect">
          <label><?= htmlspecialchars($t('github_repo')) ?></label>
          <input name="repo_url" placeholder="<?= htmlspecialchars($t('repo_placeholder')) ?>" value="<?= htmlspecialchars($github['repo_url'] ?? '') ?>">
          <label><?= htmlspecialchars($t('github_branch')) ?></label>
          <input name="branch" placeholder="main" value="<?= htmlspecialchars($github['branch'] ?? 'main') ?>">
          <button class="btn" type="submit"><i class="fa-solid fa-plug"></i><?= htmlspecialchars($t('connect_github')) ?></button>
        </form>
      </div>

      <div class="card" id="create-workspace">
        <h2><?= htmlspecialchars($t('create_repo')) ?></h2>
        <form method="POST">
          <input type="hidden" name="action" value="create_repo">
          <label><?= htmlspecialchars($t('repo_name')) ?></label>
          <input name="repo_name" placeholder="libre-claude-app">
          <label><?= htmlspecialchars($t('repo_description')) ?></label>
          <input name="repo_description" placeholder="<?= htmlspecialchars($t('repo_description_placeholder')) ?>">
          <label><?= htmlspecialchars($t('github_create_token')) ?></label>
          <input type="password" name="create_token" autocomplete="off" placeholder="<?= htmlspecialchars($t('github_create_token_placeholder')) ?>">
          <p class="hint"><?= htmlspecialchars($t('github_create_token_hint')) ?></p>
          <label class="inline-check"><input type="checkbox" name="private" value="1"> <?= htmlspecialchars($t('private_repo')) ?></label>
          <button class="btn" type="submit"><i class="fa-brands fa-github"></i><?= htmlspecialchars($t('create_repo')) ?></button>
        </form>
      </div>

      <div class="card">
        <h2><?= htmlspecialchars($t('repo_files')) ?></h2>
        <div class="file-list">
        <?php if ($treeError): ?>
          <div class="msg err"><?= htmlspecialchars($t('github_fetch_error') . ' ' . $treeError) ?></div>
        <?php elseif (!$github || empty($github['owner']) || empty($github['repo'])): ?>
          <p class="meta"><?= htmlspecialchars($t('no_repo_connected')) ?></p>
        <?php elseif (!$repoFiles): ?>
          <p class="meta"><?= htmlspecialchars($t('github_fetch_error')) ?></p>
        <?php else: ?>
          <?php foreach ($repoFiles as $repoFile): ?>
          <div class="file-row">
            <div>
              <div class="file-name"><a class="path-link" href="workspace.php?path=<?= urlencode($repoFile['path']) ?>"><?= htmlspecialchars($repoFile['path']) ?></a></div>
              <div class="meta"><?= htmlspecialchars((string)($repoFile['size'] ?? 0)) ?> <?= htmlspecialchars($t('bytes')) ?></div>
            </div>
            <button class="btn secondary" type="button" onclick="addContextPath(<?= htmlspecialchars(json_encode($repoFile['path'])) ?>)"><i class="fa-solid fa-plus"></i></button>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
        </div>
      </div>

      <div class="card">
        <h2><?= htmlspecialchars($t('saved_blocks')) ?></h2>
        <?php if (!$files): ?>
          <p class="meta"><?= htmlspecialchars($t('no_saved_blocks')) ?></p>
        <?php else: ?>
          <?php foreach ($files as $file): ?>
          <div class="file-row">
            <div>
              <div class="file-name"><?= htmlspecialchars($file['name']) ?></div>
              <div class="meta"><?= htmlspecialchars($file['language']) ?> - <?= htmlspecialchars($file['updated_at']) ?></div>
            </div>
            <button class="btn secondary" type="button" onclick="previewCode(<?= htmlspecialchars(json_encode($file['language'])) ?>, <?= htmlspecialchars(json_encode($file['content'])) ?>)"><i class="fa-solid fa-eye"></i></button>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
      </div>
    </section>
    </section>
    </div>
  </main>
</div>

<div class="preview-panel" id="preview-panel">
  <div class="preview-head">
    <strong><?= htmlspecialchars($t('preview_title')) ?></strong>
    <button class="btn secondary" type="button" onclick="closePreview()"><?= htmlspecialchars($t('close')) ?></button>
  </div>
  <iframe class="preview-frame" id="preview-frame" sandbox="allow-scripts allow-forms allow-modals"></iframe>
</div>

<script>
function previewEscapeHtml(value) {
  return String(value ?? '').replace(/[&<>"]/g, c => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
  }[c]));
}

function previewInlineMarkdown(value) {
  let html = previewEscapeHtml(value);
  html = html.replace(/\[!\[([^\]]*)\]\(((?:https?:\/\/|\.?\/|[A-Za-z0-9_.-])[^)\s]*)\)\]\((https?:\/\/[^)\s]+)\)/g, '<a href="$3" target="_blank" rel="noopener noreferrer"><img src="$2" alt="$1"></a>');
  html = html.replace(/!\[([^\]]*)\]\(((?:https?:\/\/|\.?\/|[A-Za-z0-9_.-])[^)\s]*)\)/g, '<img src="$2" alt="$1">');
  html = html.replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');
  html = html.replace(/`([^`]+)`/g, '<code>$1</code>');
  html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
  html = html.replace(/\*([^*]+)\*/g, '<em>$1</em>');
  return html;
}

function markdownToHtml(markdown) {
  const lines = String(markdown || '').replace(/\r\n/g, '\n').split('\n');
  let html = '';
  let listType = '';
  let inCode = false;
  let codeLang = '';
  let codeLines = [];

  function closeList() {
    if (!listType) return;
    html += '</' + listType + '>';
    listType = '';
  }

  function openList(type) {
    if (listType === type) return;
    closeList();
    listType = type;
    html += '<' + type + '>';
  }

  function closeCode() {
    html += '<pre><code class="language-' + previewEscapeHtml(codeLang) + '">' + previewEscapeHtml(codeLines.join('\n')) + '</code></pre>';
    inCode = false;
    codeLang = '';
    codeLines = [];
  }

  lines.forEach(line => {
    const fence = line.match(/^```([A-Za-z0-9_-]*)\s*$/);
    if (fence) {
      if (inCode) {
        closeCode();
      } else {
        closeList();
        inCode = true;
        codeLang = fence[1] || '';
        codeLines = [];
      }
      return;
    }

    if (inCode) {
      codeLines.push(line);
      return;
    }

    if (!line.trim()) {
      closeList();
      return;
    }

    const heading = line.match(/^(#{1,6})\s+(.+)$/);
    if (heading) {
      closeList();
      const level = heading[1].length;
      html += '<h' + level + '>' + previewInlineMarkdown(heading[2]) + '</h' + level + '>';
      return;
    }

    const unordered = line.match(/^\s*[-*]\s+(.+)$/);
    if (unordered) {
      openList('ul');
      html += '<li>' + previewInlineMarkdown(unordered[1]) + '</li>';
      return;
    }

    const ordered = line.match(/^\s*\d+\.\s+(.+)$/);
    if (ordered) {
      openList('ol');
      html += '<li>' + previewInlineMarkdown(ordered[1]) + '</li>';
      return;
    }

    const quote = line.match(/^>\s?(.+)$/);
    if (quote) {
      closeList();
      html += '<blockquote>' + previewInlineMarkdown(quote[1]) + '</blockquote>';
      return;
    }

    closeList();
    html += '<p>' + previewInlineMarkdown(line) + '</p>';
  });

  if (inCode) closeCode();
  closeList();
  return html || '<p></p>';
}

function previewNormalizePath(path) {
  const parts = [];
  String(path || '').replace(/^\/+/, '').split('/').forEach(part => {
    if (!part || part === '.') return;
    if (part === '..') parts.pop();
    else parts.push(part);
  });
  return parts.join('/');
}

function previewDirname(path) {
  const normalized = previewNormalizePath(path);
  const index = normalized.lastIndexOf('/');
  return index === -1 ? '' : normalized.slice(0, index + 1);
}

function previewMime(path) {
  const ext = String(path || '').split('.').pop().toLowerCase();
  if (ext === 'svg') return 'image/svg+xml';
  if (ext === 'png') return 'image/png';
  if (ext === 'jpg' || ext === 'jpeg') return 'image/jpeg';
  if (ext === 'gif') return 'image/gif';
  if (ext === 'webp') return 'image/webp';
  if (ext === 'ico') return 'image/x-icon';
  return 'text/plain';
}

function previewAssetContent(files, currentPath, ref) {
  const file = previewAssetFile(files, currentPath, ref);
  return file ? String(file.content || '') : null;
}

function previewAssetFile(files, currentPath, ref) {
  ref = String(ref || '').trim();
  if (!ref || /^(?:[a-z][a-z0-9+.-]*:|#)/i.test(ref)) return null;
  const cleanRef = ref.split('#')[0].split('?')[0];
  const dir = previewDirname(currentPath);
  const candidates = [
    previewNormalizePath(cleanRef),
    previewNormalizePath(dir + cleanRef),
  ];
  if (previewNormalizePath(currentPath).startsWith('public/')) {
    candidates.push(previewNormalizePath('public/' + cleanRef.replace(/^\/+/, '')));
  }
  const match = (files || []).find(file => candidates.includes(previewNormalizePath(file.path)));
  return match || null;
}

function previewErrorOverlayScript() {
  return `<script>
(() => {
  const show = message => {
    let box = document.getElementById('__libre_preview_error');
    if (!box) {
      box = document.createElement('pre');
      box.id = '__libre_preview_error';
      box.style.cssText = 'position:fixed;left:12px;right:12px;bottom:12px;z-index:999999;margin:0;padding:12px 14px;border-radius:10px;background:#1a1115;color:#ffdce2;border:1px solid #e6122a;font:12px/1.5 ui-monospace,SFMono-Regular,Menlo,monospace;white-space:pre-wrap;max-height:45vh;overflow:auto';
      document.body.appendChild(box);
    }
    box.textContent = 'Erreur aperçu Libre Claude:\\n' + String(message || 'Erreur inconnue');
  };
  window.addEventListener('error', event => show(event.message || event.error));
  window.addEventListener('unhandledrejection', event => show(event.reason && (event.reason.stack || event.reason.message) || event.reason));
  setTimeout(() => {
    if (!document.body || document.body.children.length > 1 || document.body.textContent.trim()) return;
    show('La page est vide. Vérifiez que index.html cible le bon fichier JS/CSS, par exemple /src/app.js ou ./src/app.js.');
  }, 900);
})();
<\/script>`;
}

function previewModuleBootstrap(entryPath, files) {
  const sourceFiles = {};
  (files || []).forEach(file => {
    const path = previewNormalizePath(file.path);
    if (!path) return;
    sourceFiles[path] = String(file.content || '');
  });
  return `<script type="module">
const __lcFiles = ${JSON.stringify(sourceFiles)};
const __lcEntry = ${JSON.stringify(previewNormalizePath(entryPath))};
const __lcCache = {};
function __lcNormalize(path) {
  const parts = [];
  String(path || '').replace(/^\\/+/, '').split('/').forEach(part => {
    if (!part || part === '.') return;
    if (part === '..') parts.pop();
    else parts.push(part);
  });
  return parts.join('/');
}
function __lcDir(path) {
  path = __lcNormalize(path);
  const i = path.lastIndexOf('/');
  return i === -1 ? '' : path.slice(0, i + 1);
}
function __lcExt(path) {
  return String(path || '').split('.').pop().toLowerCase();
}
function __lcResolve(from, spec) {
  if (spec === 'react') return 'https://esm.sh/react@18';
  if (spec === 'react-dom/client') return 'https://esm.sh/react-dom@18/client';
  if (spec === 'react-dom') return 'https://esm.sh/react-dom@18';
  if (/^(?:https?:|data:|blob:)/.test(spec)) return spec;
  const base = spec.startsWith('/') ? spec : __lcDir(from) + spec;
  const clean = __lcNormalize(base);
  const tries = [clean, clean + '.js', clean + '.jsx', clean + '.ts', clean + '.tsx', clean + '/index.js', clean + '/index.jsx'];
  return tries.find(path => Object.prototype.hasOwnProperty.call(__lcFiles, path)) || clean;
}
function __lcHasJsx(path, code) {
  const ext = __lcExt(path);
  return ext === 'jsx' || ext === 'tsx' || /<[A-Za-z][A-Za-z0-9]*[\\s>/]/.test(code);
}
function __lcNeedsBabel(path, code) {
  const ext = __lcExt(path);
  return ext === 'jsx' || ext === 'tsx' || ext === 'ts' || __lcHasJsx(path, code);
}
async function __lcLoadBabel() {
  if (window.Babel) return;
  await new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = 'https://unpkg.com/@babel/standalone/babel.min.js';
    script.onload = resolve;
    script.onerror = () => reject(new Error('Impossible de charger Babel pour l aperçu JSX.'));
    document.head.appendChild(script);
  });
}
async function __lcBuild(path) {
  path = __lcNormalize(path);
  if (__lcCache[path]) return __lcCache[path];
  let code = __lcFiles[path];
  if (code == null) throw new Error('Module introuvable: ' + path);
  if (__lcNeedsBabel(path, code)) {
    await __lcLoadBabel();
    code = Babel.transform(code, {presets: ['react', 'typescript'], filename: path, sourceType: 'module'}).code;
  }
  const replacements = [];
  const importRegex = /import\\s+(?:[^'"]*?\\s+from\\s+)?['"]([^'"]+)['"]/g;
  let match;
  while ((match = importRegex.exec(code))) {
    const spec = match[1];
    const resolved = __lcResolve(path, spec);
    if (/\\.css$/i.test(resolved) && __lcFiles[resolved] != null) {
      replacements.push([match[0], 'const __s=document.createElement("style");__s.textContent=' + JSON.stringify(__lcFiles[resolved]) + ';document.head.appendChild(__s)']);
    } else if (__lcFiles[resolved] != null) {
      replacements.push([spec, await __lcBuild(resolved)]);
    } else if (/^(?:https?:|data:|blob:)/.test(resolved)) {
      replacements.push([spec, resolved]);
    }
  }
  replacements.forEach(([from, to]) => { code = code.split(from).join(to); });
  const url = URL.createObjectURL(new Blob([code], {type: 'text/javascript'}));
  __lcCache[path] = url;
  return url;
}
await import(await __lcBuild(__lcEntry));
<\/script>`;
}

function buildHtmlProjectPreview(code, currentPath, files) {
  const parser = new DOMParser();
  const doc = parser.parseFromString(String(code || ''), 'text/html');
  doc.querySelectorAll('link[rel~="stylesheet"][href]').forEach(link => {
    const css = previewAssetContent(files, currentPath, link.getAttribute('href'));
    if (css === null) return;
    const style = doc.createElement('style');
    style.textContent = css;
    link.replaceWith(style);
  });
  let moduleEntry = null;
  doc.querySelectorAll('script[src]').forEach(script => {
    const asset = previewAssetFile(files, currentPath, script.getAttribute('src'));
    if (!asset) return;
    const js = String(asset.content || '');
    const assetPath = previewNormalizePath(asset.path);
    const isModule = (script.getAttribute('type') || '').toLowerCase() === 'module'
      || /\.(jsx|tsx|ts)$/i.test(assetPath)
      || /\bimport\s+|\bexport\s+|<[A-Za-z][A-Za-z0-9]*[\s>/]/.test(js);
    if (isModule) {
      moduleEntry = assetPath;
      script.remove();
      return;
    }
    const inline = doc.createElement('script');
    inline.textContent = js;
    script.replaceWith(inline);
  });
  doc.querySelectorAll('img[src]').forEach(img => {
    const content = previewAssetContent(files, currentPath, img.getAttribute('src'));
    if (content === null) return;
    const path = img.getAttribute('src').split('#')[0].split('?')[0];
    img.setAttribute('src', 'data:' + previewMime(path) + ';base64,' + btoa(unescape(encodeURIComponent(content))));
  });
  doc.body.insertAdjacentHTML('beforeend', previewErrorOverlayScript());
  if (moduleEntry) {
    doc.body.insertAdjacentHTML('beforeend', previewModuleBootstrap(moduleEntry, files));
  }
  return '<!doctype html>\n' + doc.documentElement.outerHTML;
}

function buildPreviewDoc(lang, code, currentPath = '', files = []) {
  lang = String(lang || '').toLowerCase();
  if (lang === 'html') return buildHtmlProjectPreview(code, currentPath, files);
  if (lang === 'svg') return code;
  if (lang === 'css') return '<!doctype html><html><head><style>' + code + '</style></head><body><main class="preview-root"><?= htmlspecialchars($t('css_preview_label')) ?></main></body></html>';
  if (lang === 'js' || lang === 'javascript') return '<!doctype html><html><body><main id="app"></main><script>' + code.replace(/<\/script/gi, '<\\/script') + '<\/script></body></html>';
  if (lang === 'md' || lang === 'markdown') {
    return '<!doctype html><html><head><meta charset="utf-8"><style>body{margin:0;background:#fff;color:#17151f;font:16px/1.65 Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.markdown{max-width:920px;margin:0 auto;padding:34px 38px}h1,h2,h3,h4,h5,h6{line-height:1.18;margin:1.25em 0 .55em;color:#11101a}h1{font-size:2.15rem;border-bottom:1px solid #e6e2ec;padding-bottom:.35em}h2{font-size:1.55rem;border-bottom:1px solid #eeeaf3;padding-bottom:.3em}p,ul,ol,blockquote,pre{margin:0 0 1rem}ul,ol{padding-left:1.45rem}li+li{margin-top:.25rem}a{color:#d6112a;text-decoration:none}a:hover{text-decoration:underline}code{background:#f3f1f6;border:1px solid #e2deea;border-radius:6px;padding:.12em .35em;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.9em}pre{background:#11111a;color:#f2f0f6;border-radius:12px;padding:16px;overflow:auto}pre code{background:transparent;border:0;color:inherit;padding:0}blockquote{border-left:4px solid #e6122a;background:#faf7fa;padding:10px 14px;color:#4f4b5c}img{max-width:100%;height:auto;border-radius:10px}</style></head><body><article class="markdown">' + markdownToHtml(code) + '</article></body></html>';
  }
  return '<!doctype html><html><body><pre>' + previewEscapeHtml(code) + '</pre></body></html>';
}
function toggleWorkspaceSidebar() {
  const app = document.querySelector('.app');
  if (!app) return;
  app.classList.toggle('sidebar-collapsed');
  try {
    localStorage.setItem('libreClaudeWorkspaceSidebar', app.classList.contains('sidebar-collapsed') ? 'collapsed' : 'open');
  } catch (e) {}
}
try {
  if (localStorage.getItem('libreClaudeWorkspaceSidebar') === 'collapsed') {
    document.querySelector('.app')?.classList.add('sidebar-collapsed');
  }
} catch (e) {}
function previewCode(lang, code) {
  document.getElementById('preview-frame').srcdoc = buildPreviewDoc(lang, code);
  document.getElementById('preview-panel').classList.add('open');
}
function closePreview() {
  document.getElementById('preview-frame').srcdoc = '';
  document.getElementById('preview-panel').classList.remove('open');
}
let generatedFiles = [];
let selectedGeneratedIndex = 0;
let reviewTab = 'diff';

function generatedDraftField() {
  return document.getElementById('multi-files-draft');
}

function parseGeneratedFiles() {
  const field = generatedDraftField();
  if (!field) return [];
  try {
    const parsed = JSON.parse(field.value || '[]');
    if (!Array.isArray(parsed)) return [];
    return parsed
      .filter(item => item && typeof item === 'object' && item.path && Object.prototype.hasOwnProperty.call(item, 'content'))
      .map(item => ({ path: String(item.path), content: String(item.content) }));
  } catch (e) {
    return [];
  }
}

function fileKind(path) {
  const ext = String(path || '').split('.').pop().toLowerCase();
  if (ext === 'html' || ext === 'htm' || ext === 'hmtl') return 'html';
  if (ext === 'md' || ext === 'markdown') return 'markdown';
  if (ext === 'css') return 'css';
  if (ext === 'js' || ext === 'mjs') return 'javascript';
  if (ext === 'svg') return 'svg';
  return ext || 'file';
}

function formatGeneratedDiff(file) {
  const lines = String(file.content || '').split('\n');
  const header = [
    'diff --git a/' + file.path + ' b/' + file.path,
    '--- a/' + file.path,
    '+++ b/' + file.path,
    '@@ generated by Libre Claude Coder @@',
  ];
  return header.concat(lines.map(line => '+ ' + line)).join('\n');
}

function formatGeneratedLog(file) {
  const lineCount = String(file.content || '').split('\n').length;
  return [
    '$ preparing generated file',
    'path: ' + file.path,
    'type: ' + fileKind(file.path),
    'lines: ' + lineCount,
    '',
    String(file.content || ''),
  ].join('\n');
}

function renderGeneratedList() {
  const list = document.getElementById('generated-list');
  if (!list) return;
  if (!generatedFiles.length) {
    list.innerHTML = '<div class="review-empty">Aucun fichier à prévisualiser.</div>';
    return;
  }
  list.innerHTML = '';
  generatedFiles.forEach((file, index) => {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'generated-item' + (index === selectedGeneratedIndex ? ' active' : '');
    btn.innerHTML = '<strong></strong><span></span>';
    btn.querySelector('strong').textContent = file.path;
    btn.querySelector('span').textContent = fileKind(file.path) + ' · ' + String(file.content || '').split('\n').length + ' lignes';
    btn.addEventListener('click', () => {
      selectedGeneratedIndex = index;
      renderGeneratedList();
      renderGeneratedReview();
    });
    list.appendChild(btn);
  });
}

function setReviewTab(tab) {
  reviewTab = tab;
  document.querySelectorAll('[data-review-tab]').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.reviewTab === tab);
  });
  renderGeneratedReview();
}

function renderGeneratedReview() {
  const file = generatedFiles[selectedGeneratedIndex];
  const code = document.getElementById('review-code');
  const diff = document.getElementById('review-diff');
  const frame = document.getElementById('review-frame');
  if (!code || !diff || !frame) return;

  if (!file) {
    code.textContent = 'Aucun fichier sélectionné.';
    diff.textContent = 'Aucun fichier sélectionné.';
    frame.srcdoc = '';
    code.hidden = reviewTab !== 'log';
    diff.hidden = reviewTab !== 'diff';
    frame.hidden = reviewTab !== 'preview';
    return;
  }

  code.textContent = formatGeneratedLog(file);
  diff.textContent = formatGeneratedDiff(file);
  frame.srcdoc = buildPreviewDoc(fileKind(file.path), file.content, file.path, generatedFiles);
  code.hidden = reviewTab !== 'log';
  diff.hidden = reviewTab !== 'diff';
  frame.hidden = reviewTab !== 'preview';
}

function refreshGeneratedReview() {
  generatedFiles = parseGeneratedFiles();
  selectedGeneratedIndex = Math.min(selectedGeneratedIndex, Math.max(generatedFiles.length - 1, 0));
  renderGeneratedList();
  renderGeneratedReview();
}

function applyGeneratedToCommit() {
  const field = generatedDraftField();
  generatedFiles = parseGeneratedFiles();
  if (!field || !generatedFiles.length) {
    refreshGeneratedReview();
    return;
  }
  field.value = JSON.stringify(generatedFiles, null, 2);
  refreshGeneratedReview();
  field.focus();
}

function publishGenerated() {
  applyGeneratedToCommit();
  const field = generatedDraftField();
  const files = parseGeneratedFiles();
  if (!field || !files.length) {
    document.getElementById('generated-review')?.scrollIntoView({behavior: 'smooth', block: 'center'});
    return;
  }
  field.form.requestSubmit();
}

function setWorkspaceTab(name) {
  const shell = document.querySelector('.coder-shell');
  if (shell && name === 'settings') shell.classList.add('environment-open');
  document.querySelectorAll('[data-workspace-tab]').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.workspaceTab === name);
  });
  document.querySelectorAll('.tab-panel').forEach(panel => {
    panel.classList.toggle('active', panel.id === 'workspace-tab-' + name);
  });
}

function openWorkspaceEnvironment() {
  setWorkspaceTab('settings');
  document.getElementById('workspace-tab-settings')?.scrollIntoView({behavior: 'smooth', block: 'start'});
}

document.querySelectorAll('[data-workspace-tab]').forEach(btn => {
  btn.addEventListener('click', () => setWorkspaceTab(btn.dataset.workspaceTab || 'publish'));
});

function addContextPath(path) {
  const field = document.getElementById('ai-context');
  const current = field.value.split(/\r?\n/).map(v => v.trim()).filter(Boolean);
  if (!current.includes(path)) current.push(path);
  field.value = current.join('\n');
  const prompt = document.getElementById('ai-prompt');
  prompt.focus();
}
document.querySelectorAll('[data-prompt]').forEach(btn => {
  btn.addEventListener('click', () => {
    const prompt = document.getElementById('ai-prompt');
    prompt.value = btn.dataset.prompt || '';
    prompt.dispatchEvent(new Event('input'));
    prompt.focus();
  });
});
const promptInput = document.getElementById('ai-prompt');
const sendButton = document.getElementById('code-send');
const coderForm = document.getElementById('coder-form');

function escapeText(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function showCodingStarted() {
  const prompt = promptInput.value.trim();
  if (!prompt) return;
  const shell = document.querySelector('.coder-shell');
  const thread = document.querySelector('.coder-thread');
  const topActions = document.querySelector('.top-actions');
  if (shell) {
    shell.classList.add('coder-active');
    shell.classList.remove('environment-open');
  }
  if (thread) {
    thread.innerHTML = `
      <div class="coder-turn">
        <div class="coder-bubble user">${escapeText(prompt)}</div>
      </div>
      <div class="coder-turn">
        <div class="coder-work-label">Coding <span>en cours</span></div>
        <div class="coder-bubble assistant">
          <span class="coder-pending"><i class="fa-solid fa-circle-notch fa-spin"></i> Libre Claude prépare les modifications...</span>
        </div>
      </div>
    `;
  }
  if (topActions) topActions.hidden = true;
  sendButton.disabled = true;
  sendButton.innerHTML = '<span>Coding</span><i class="fa-solid fa-circle-notch fa-spin"></i>';
}

function syncSendState() {
  sendButton.classList.toggle('ready', promptInput.value.trim().length > 0);
}
promptInput.addEventListener('input', syncSendState);
if (coderForm) {
  coderForm.addEventListener('submit', showCodingStarted);
}
syncSendState();
document.querySelectorAll('[data-review-tab]').forEach(btn => {
  btn.addEventListener('click', () => setReviewTab(btn.dataset.reviewTab || 'diff'));
});
refreshGeneratedReview();
</script>
</body>
</html>
