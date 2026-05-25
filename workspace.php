<?php
/**
 * Libre Claude - Workspace et GitHub
 */
require_once dirname(__FILE__) . '/config.php';
require_once dirname(__FILE__) . '/auth.php';
require_once dirname(__FILE__) . '/database.php';
require_once dirname(__FILE__) . '/i18n.php';

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
    if (preg_match('#github\.com/([^/]+)/([^/?#]+)#i', $value, $m)) {
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

function github_tree($owner, $repo, $branch, $token, &$error) {
    if (!$owner || !$repo) return [];
    $url = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo)
        . '/git/trees/' . rawurlencode($branch ?: 'main') . '?recursive=1';

    $data = github_api_request('GET', $url, $token, null, $error);
    if (!$data) {
        return [];
    }

    if (!isset($data['tree']) || !is_array($data['tree'])) {
        $error = 'Réponse GitHub invalide';
        return [];
    }

    return array_values(array_filter($data['tree'], fn($item) => ($item['type'] ?? '') === 'blob'));
}

function github_get_file($owner, $repo, $branch, $path, $token, &$error) {
    $url = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo)
        . '/contents/' . github_path_url($path) . '?ref=' . rawurlencode($branch ?: 'main');
    $data = github_api_request('GET', $url, $token, null, $error);
    if (!$data || !isset($data['content'])) return null;
    $content = base64_decode(str_replace(["\r", "\n"], '', $data['content']));
    return [
        'path' => $data['path'] ?? $path,
        'sha' => $data['sha'] ?? '',
        'content' => $content === false ? '' : $content,
    ];
}

function github_save_file($owner, $repo, $branch, $path, $content, $message, $sha, $token, &$error) {
    $url = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo)
        . '/contents/' . github_path_url($path);
    $body = [
        'message' => $message ?: 'Update ' . $path,
        'content' => base64_encode($content),
        'branch' => $branch ?: 'main',
    ];
    if ($sha) $body['sha'] = $sha;
    return github_api_request('PUT', $url, $token, $body, $error);
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

function github_user_repos($token, &$error) {
    if (!$token) return [];
    $url = 'https://api.github.com/user/repos?per_page=100&sort=updated&affiliation=owner,collaborator,organization_member';
    $data = github_api_request('GET', $url, $token, null, $error);
    if (!$data || !is_array($data)) return [];
    return $data;
}

function github_commit_files($owner, $repo, $branch, $files, $message, $token, &$error) {
    $branch = $branch ?: 'main';
    $refUrl = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo)
        . '/git/ref/heads/' . rawurlencode($branch);
    $ref = github_api_request('GET', $refUrl, $token, null, $error);
    if (!$ref || empty($ref['object']['sha'])) return null;

    $parentSha = $ref['object']['sha'];
    $commitUrl = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo)
        . '/git/commits/' . rawurlencode($parentSha);
    $parentCommit = github_api_request('GET', $commitUrl, $token, null, $error);
    if (!$parentCommit || empty($parentCommit['tree']['sha'])) return null;

    $treeItems = [];
    foreach ($files as $file) {
        $path = trim($file['path'] ?? '');
        if ($path === '' || !array_key_exists('content', $file)) continue;
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

    $treeUrl = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/git/trees';
    $tree = github_api_request('POST', $treeUrl, $token, [
        'base_tree' => $parentCommit['tree']['sha'],
        'tree' => $treeItems,
    ], $error);
    if (!$tree || empty($tree['sha'])) return null;

    $newCommit = github_api_request('POST', 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/git/commits', $token, [
        'message' => $message ?: 'Update files from Libre Claude',
        'tree' => $tree['sha'],
        'parents' => [$parentSha],
    ], $error);
    if (!$newCommit || empty($newCommit['sha'])) return null;

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

    if ($action === 'connect') {
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
                $error = $t('repo_create_error') . ' ' . $apiError;
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
                    if (!$committed) {
                        $error = $t('multi_file_error') . ' ' . $apiError;
                    } else {
                        $success = $t('multi_file_saved');
                    }
                }
            }
        }
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
:root{--bg:#0a0a0f;--card:#13131a;--surface:#181824;--border:#252538;--text:#e8e6f0;--muted:#858298;--accent:#e6122a;--accent2:#ff3b4f;--err:#f87171;--success:#4ade80}
body{background:var(--bg);color:var(--text);font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;min-height:100vh;padding:36px 20px}
body::before{content:'';position:fixed;inset:0;background:radial-gradient(ellipse 60% 40% at 50% -10%,rgba(230,18,42,.13),transparent);pointer-events:none}
.wrap{max-width:1120px;margin:0 auto;position:relative;z-index:1}
.top{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:28px}
.logo{width:250px;max-width:56vw;height:auto}
.back{color:var(--muted);text-decoration:none;font-size:14px}
.back:hover{color:var(--text)}
h1{font-family:Georgia,"Times New Roman",serif;font-size:32px;margin-bottom:8px}
.sub{color:var(--muted);line-height:1.5;margin-bottom:24px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:22px;margin-bottom:18px}
.card h2{font-size:13px;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);margin-bottom:16px}
label{display:block;font-size:12px;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);margin-bottom:7px}
input,textarea,select{width:100%;padding:12px 14px;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:9px;color:var(--text);font-size:14px;margin-bottom:12px;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
textarea{min-height:360px;resize:vertical;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;line-height:1.55}
select option{background:#13131a;color:var(--text)}
input:focus,textarea:focus,select:focus{outline:none;border-color:var(--accent)}
input[type="checkbox"]{width:auto;margin:0 8px 0 0}
.hint{font-size:12px;color:var(--muted);margin-top:-5px;margin-bottom:12px}
.btn{padding:11px 15px;border:none;border-radius:8px;background:linear-gradient(135deg,var(--accent),var(--accent2));color:white;font-weight:650;cursor:pointer;text-decoration:none;display:inline-flex;gap:8px;align-items:center}
.btn.secondary{background:rgba(255,255,255,.05);border:1px solid var(--border);color:var(--text)}
.msg{padding:12px 14px;border-radius:9px;font-size:13.5px;margin-bottom:16px}
.ok{background:rgba(74,222,128,.08);border:1px solid rgba(74,222,128,.25);color:var(--success)}
.err{background:rgba(248,113,113,.08);border:1px solid rgba(248,113,113,.3);color:var(--err)}
.file-row{display:grid;grid-template-columns:1fr auto;gap:12px;align-items:center;padding:12px 0;border-bottom:1px solid var(--border)}
.file-row:last-child{border-bottom:none}
.file-name{font-weight:700;font-size:14px}
.meta{font-size:12px;color:var(--muted);margin-top:3px}
.wide{grid-column:1/-1}
.inline-check{display:flex;align-items:center;color:var(--muted);font-size:13px;margin:0 0 14px;text-transform:none;letter-spacing:0}
.editor-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.path-link{color:var(--text);text-decoration:none}
.path-link:hover{color:var(--accent2)}
.code-box{margin-top:10px;background:#07070d;border:1px solid var(--border);border-radius:9px;padding:12px;max-height:220px;overflow:auto;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12.5px;color:#d5d1dc;white-space:pre}
.repo-link{color:var(--accent2);text-decoration:none}
.preview-panel{position:fixed;inset:24px;background:#09090f;border:1px solid var(--border);border-radius:14px;z-index:50;display:none;flex-direction:column;box-shadow:0 26px 90px rgba(0,0,0,.65)}
.preview-panel.open{display:flex}
.preview-head{height:48px;display:flex;align-items:center;justify-content:space-between;padding:0 14px;border-bottom:1px solid var(--border)}
.preview-frame{flex:1;width:100%;border:0;background:white;border-radius:0 0 14px 14px}
@media(max-width:820px){.grid{grid-template-columns:1fr}.top{align-items:flex-start;flex-direction:column}.logo{max-width:100%}.preview-panel{inset:8px}}
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <img class="logo" src="libre-claude-red-black-dark.png" alt="Libre Claude">
    <a class="back" href="index.php"><i class="fa-solid fa-arrow-left"></i> <?= htmlspecialchars($t('back_chat')) ?></a>
  </div>

  <h1><?= htmlspecialchars($t('workspace_title')) ?></h1>
  <p class="sub"><?= htmlspecialchars($t('workspace_sub')) ?></p>

  <?php if (($_GET['github'] ?? '') === 'connected'): ?><div class="msg ok"><?= htmlspecialchars($t('github_oauth_success')) ?></div><?php endif; ?>
  <?php if (!empty($_GET['github_error'])): ?><div class="msg err"><?= htmlspecialchars($t('github_oauth_failed')) ?><?php if ($githubOauthErrorDetail): ?><br><small><?= htmlspecialchars($githubOauthErrorDetail) ?></small><?php endif; ?></div><?php endif; ?>
  <?php if ($success): ?><div class="msg ok"><?= htmlspecialchars($success) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="msg err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <div class="grid">
    <section class="card">
      <h2><?= htmlspecialchars($t('connect_github')) ?></h2>
      <p class="hint" style="margin-top:0"><?= htmlspecialchars($t('github_oauth_hint')) ?></p>
      <?php if ($oauthEnabled): ?>
        <p style="margin-bottom:16px"><a class="btn" href="github_oauth.php"><i class="fa-brands fa-github"></i> <?= htmlspecialchars($t('github_oauth')) ?></a></p>
      <?php else: ?>
        <div class="msg err"><?= htmlspecialchars($t('github_oauth_config_missing')) ?></div>
      <?php endif; ?>
      <form method="POST">
        <input type="hidden" name="action" value="connect">
        <?php if ($authorizedRepos): ?>
        <label><?= htmlspecialchars($t('github_repo_select')) ?></label>
        <select name="repo_full_name">
          <option value="">--</option>
          <?php foreach ($authorizedRepos as $repoOption): ?>
          <option value="<?= htmlspecialchars($repoOption['full_name'] ?? '') ?>" <?= (($github['owner'] ?? '') . '/' . ($github['repo'] ?? '')) === ($repoOption['full_name'] ?? '') ? 'selected' : '' ?>>
            <?= htmlspecialchars($repoOption['full_name'] ?? '') ?>
          </option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <label><?= htmlspecialchars($t('github_repo')) ?></label>
        <input name="repo_url" placeholder="<?= htmlspecialchars($t('repo_placeholder')) ?>" value="<?= htmlspecialchars($github['repo_url'] ?? '') ?>">
        <label><?= htmlspecialchars($t('github_branch')) ?></label>
        <input name="branch" placeholder="main" value="<?= htmlspecialchars($github['branch'] ?? 'main') ?>">
        <label><?= htmlspecialchars($t('github_token')) ?></label>
        <input type="password" name="token" placeholder="ghp_...">
        <div class="hint"><?= htmlspecialchars($t('github_token_hint')) ?></div>
        <button class="btn" type="submit"><i class="fa-brands fa-github"></i> <?= htmlspecialchars($t('connect_github')) ?></button>
      </form>
    </section>

    <section class="card">
      <h2><?= htmlspecialchars($t('connected_repo')) ?></h2>
      <?php if ($github && !empty($github['owner']) && !empty($github['repo'])): ?>
        <div class="file-name"><?= htmlspecialchars($github['owner'] . '/' . $github['repo']) ?></div>
        <div class="meta"><?= htmlspecialchars($t('github_branch')) ?>: <?= htmlspecialchars($github['branch'] ?: 'main') ?></div>
        <p style="margin-top:14px"><a class="btn secondary" target="_blank" rel="noopener" href="https://github.com/<?= htmlspecialchars(rawurlencode($github['owner'])) ?>/<?= htmlspecialchars(rawurlencode($github['repo'])) ?>"><i class="fa-brands fa-github"></i> <?= htmlspecialchars($t('open_github')) ?></a></p>
      <?php elseif ($github && !empty($github['token'])): ?>
        <p class="meta"><?= htmlspecialchars($t('github_oauth_success')) ?> <?= htmlspecialchars($t('github_repo_select')) ?>.</p>
      <?php else: ?>
        <p class="meta"><?= htmlspecialchars($t('no_repo_connected')) ?></p>
      <?php endif; ?>
    </section>
  </div>

  <div class="grid">
    <section class="card" id="create-workspace">
      <h2><?= htmlspecialchars($t('create_repo')) ?></h2>
      <form method="POST">
        <input type="hidden" name="action" value="create_repo">
        <label><?= htmlspecialchars($t('repo_name')) ?></label>
        <input name="repo_name" placeholder="libre-claude-app">
        <label><?= htmlspecialchars($t('repo_description')) ?></label>
        <input name="repo_description" placeholder="<?= htmlspecialchars($t('repo_description_placeholder')) ?>">
        <label><?= htmlspecialchars($t('github_token')) ?></label>
        <input type="password" name="create_token" placeholder="ghp_...">
        <label class="inline-check"><input type="checkbox" name="private" value="1"> <?= htmlspecialchars($t('private_repo')) ?></label>
        <button class="btn" type="submit"><i class="fa-brands fa-github"></i> <?= htmlspecialchars($t('create_repo')) ?></button>
      </form>
    </section>

    <section class="card">
      <h2><?= htmlspecialchars($t('file_editor')) ?></h2>
      <?php if (!$github || empty($github['owner']) || empty($github['repo'])): ?>
        <p class="meta"><?= htmlspecialchars($t('no_repo_connected')) ?></p>
      <?php else: ?>
      <form method="POST">
        <input type="hidden" name="action" value="save_file">
        <input type="hidden" name="file_sha" value="<?= htmlspecialchars($selectedFile['sha'] ?? '') ?>">
        <label><?= htmlspecialchars($t('file_path')) ?></label>
        <input name="file_path" placeholder="src/app.js" value="<?= htmlspecialchars($selectedFile['path'] ?? $selectedPath) ?>">
        <div class="hint"><?= htmlspecialchars($t('new_file_hint')) ?></div>
        <label><?= htmlspecialchars($t('commit_message')) ?></label>
        <input name="commit_message" placeholder="<?= htmlspecialchars($t('commit_message_placeholder')) ?>">
        <label><?= htmlspecialchars($t('file_content')) ?></label>
        <textarea name="file_content"><?= htmlspecialchars($selectedFile['content'] ?? '') ?></textarea>
        <div class="editor-actions">
          <button class="btn" type="submit"><i class="fa-solid fa-code-commit"></i> <?= htmlspecialchars($t('commit_file')) ?></button>
          <?php if ($github && !empty($github['owner']) && !empty($github['repo'])): ?>
          <a class="btn secondary" target="_blank" rel="noopener" href="https://github.com/<?= htmlspecialchars(rawurlencode($github['owner'])) ?>/<?= htmlspecialchars(rawurlencode($github['repo'])) ?>"><i class="fa-brands fa-github"></i> <?= htmlspecialchars($t('open_github')) ?></a>
          <?php endif; ?>
        </div>
      </form>
      <?php endif; ?>
    </section>
  </div>

  <div class="grid">
    <section class="card wide">
      <h2><?= htmlspecialchars($t('multi_file_commit')) ?></h2>
      <?php if (!$github || empty($github['owner']) || empty($github['repo'])): ?>
        <p class="meta"><?= htmlspecialchars($t('no_repo_connected')) ?></p>
      <?php else: ?>
      <form method="POST">
        <input type="hidden" name="action" value="save_many_files">
        <label><?= htmlspecialchars($t('commit_message')) ?></label>
        <input name="multi_commit_message" placeholder="<?= htmlspecialchars($t('commit_message_placeholder')) ?>">
        <label><?= htmlspecialchars($t('multi_file_payload')) ?></label>
        <textarea name="multi_files" style="min-height:190px" placeholder="<?= htmlspecialchars($t('multi_file_placeholder')) ?>"></textarea>
        <div class="hint"><?= htmlspecialchars($t('multi_file_hint')) ?></div>
        <button class="btn" type="submit"><i class="fa-solid fa-code-commit"></i> <?= htmlspecialchars($t('multi_file_commit')) ?></button>
      </form>
      <?php endif; ?>
    </section>
  </div>

  <div class="grid">
    <section class="card">
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
          <button class="btn secondary" type="button" onclick="previewCode(<?= htmlspecialchars(json_encode($file['language'])) ?>, <?= htmlspecialchars(json_encode($file['content'])) ?>)"><i class="fa-solid fa-eye"></i> <?= htmlspecialchars($t('code_preview')) ?></button>
        </div>
        <pre class="code-box"><?= htmlspecialchars($file['content']) ?></pre>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>

    <section class="card">
      <h2><?= htmlspecialchars($t('repo_files')) ?></h2>
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
          <a class="btn secondary" href="workspace.php?path=<?= urlencode($repoFile['path']) ?>"><i class="fa-solid fa-pen-to-square"></i> <?= htmlspecialchars($t('load_file')) ?></a>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>
  </div>
</div>

<div class="preview-panel" id="preview-panel">
  <div class="preview-head">
    <strong><?= htmlspecialchars($t('preview_title')) ?></strong>
    <button class="btn secondary" type="button" onclick="closePreview()"><?= htmlspecialchars($t('close')) ?></button>
  </div>
  <iframe class="preview-frame" id="preview-frame" sandbox="allow-scripts allow-forms allow-modals"></iframe>
</div>

<script>
function buildPreviewDoc(lang, code) {
  lang = String(lang || '').toLowerCase();
  if (lang === 'html' || lang === 'svg') return code;
  if (lang === 'css') return '<!doctype html><html><head><style>' + code + '</style></head><body><main class="preview-root"><?= htmlspecialchars($t('css_preview_label')) ?></main></body></html>';
  if (lang === 'js' || lang === 'javascript') return '<!doctype html><html><body><main id="app"></main><script>' + code.replace(/<\/script/gi, '<\\/script') + '<\/script></body></html>';
  return '<!doctype html><html><body><pre>' + String(code).replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])) + '</pre></body></html>';
}
function previewCode(lang, code) {
  document.getElementById('preview-frame').srcdoc = buildPreviewDoc(lang, code);
  document.getElementById('preview-panel').classList.add('open');
}
function closePreview() {
  document.getElementById('preview-frame').srcdoc = '';
  document.getElementById('preview-panel').classList.remove('open');
}
</script>
</body>
</html>
