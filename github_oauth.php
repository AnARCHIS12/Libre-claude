<?php
/**
 * Libre Claude - Connexion OAuth GitHub
 */
require_once dirname(__FILE__) . '/config.php';
require_once dirname(__FILE__) . '/auth.php';
require_once dirname(__FILE__) . '/database.php';
require_once dirname(__FILE__) . '/i18n.php';

$auth = new Auth();
$user = $auth->getCurrentUser();
if (!$user) {
    header('Location: login.php');
    exit;
}

$lang = current_language($user);
$db = Database::getInstance();
$clientId = GITHUB_OAUTH_CLIENT_ID;
$clientSecret = GITHUB_OAUTH_CLIENT_SECRET;

if ($clientId === '' || $clientSecret === '') {
    header('Location: workspace.php?github_error=config');
    exit;
}

function github_oauth_redirect_uri() {
    if (defined('PUBLIC_URL') && PUBLIC_URL !== '') {
        return rtrim(PUBLIC_URL, '/') . '/github_oauth.php';
    }

    $scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $forwardedProto = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]));
        if (in_array($forwardedProto, ['http', 'https'], true)) {
            $scheme = $forwardedProto;
        }
    }

    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $host = trim(explode(',', $host)[0]);
    $prefix = '';
    if (!empty($_SERVER['HTTP_X_FORWARDED_PREFIX'])) {
        $prefix = '/' . trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PREFIX'])[0], '/');
        if ($prefix === '/') $prefix = '';
    }

    $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    return $scheme . '://' . $host . $prefix . ($base ? $base : '') . '/github_oauth.php';
}

function github_oauth_post($url, $fields) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: Libre-Claude-Workspace',
        ],
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    if ($error) return null;
    return json_decode($response ?: '{}', true);
}

function github_oauth_scope($clientId) {
    if (defined('GITHUB_OAUTH_SCOPE') && GITHUB_OAUTH_SCOPE !== '') {
        return GITHUB_OAUTH_SCOPE;
    }

    // GitHub App client IDs usually start with Iv and use fine-grained app permissions,
    // not OAuth scopes. OAuth App client IDs usually start with Ov and can request repo.
    return preg_match('/^Iv/i', $clientId) ? '' : 'repo';
}

function github_oauth_fail($code, $details = '') {
    if ($details !== '') {
        $_SESSION['github_oauth_error_detail'] = $details;
    }
    header('Location: workspace.php?github_error=' . rawurlencode($code));
    exit;
}

if (!empty($_GET['error'])) {
    $details = $_GET['error_description'] ?? $_GET['error'] ?? '';
    github_oauth_fail((string) $_GET['error'], (string) $details);
}

if (empty($_GET['code'])) {
    $state = bin2hex(random_bytes(16));
    $_SESSION['github_oauth_state'] = $state;
    $params = [
        'client_id' => $clientId,
        'redirect_uri' => github_oauth_redirect_uri(),
        'state' => $state,
        'allow_signup' => 'true',
    ];
    $scope = github_oauth_scope($clientId);
    if ($scope !== '') {
        $params['scope'] = $scope;
    }
    header('Location: https://github.com/login/oauth/authorize?' . http_build_query($params));
    exit;
}

if (empty($_GET['state']) || empty($_SESSION['github_oauth_state']) || !hash_equals($_SESSION['github_oauth_state'], $_GET['state'])) {
    github_oauth_fail('state', 'Etat OAuth invalide ou session expiree. Relancez la connexion GitHub.');
}
unset($_SESSION['github_oauth_state']);

$tokenResponse = github_oauth_post('https://github.com/login/oauth/access_token', [
    'client_id' => $clientId,
    'client_secret' => $clientSecret,
    'code' => $_GET['code'],
    'redirect_uri' => github_oauth_redirect_uri(),
]);

$token = $tokenResponse['access_token'] ?? '';
if ($token === '') {
    $details = '';
    if (is_array($tokenResponse)) {
        $details = $tokenResponse['error_description'] ?? $tokenResponse['error'] ?? '';
    }
    github_oauth_fail('oauth', $details ?: 'GitHub n a pas renvoye de jeton. Verifiez le Client Secret et le callback URL.');
}

$current = $db->fetch("SELECT * FROM workspace_github WHERE user_id = ?", [$user['id']]);
$db->query(
    "INSERT OR REPLACE INTO workspace_github (user_id, repo_url, owner, repo, branch, token, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, datetime('now'))",
    [
        $user['id'],
        $current['repo_url'] ?? '',
        $current['owner'] ?? '',
        $current['repo'] ?? '',
        $current['branch'] ?? 'main',
        $token,
    ]
);

header('Location: workspace.php?github=connected');
exit;
