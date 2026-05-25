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
    $scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    return $scheme . '://' . $host . ($base ? $base : '') . '/github_oauth.php';
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

if (empty($_GET['code'])) {
    $state = bin2hex(random_bytes(16));
    $_SESSION['github_oauth_state'] = $state;
    $params = [
        'client_id' => $clientId,
        'redirect_uri' => github_oauth_redirect_uri(),
        'scope' => 'repo',
        'state' => $state,
        'allow_signup' => 'true',
    ];
    header('Location: https://github.com/login/oauth/authorize?' . http_build_query($params));
    exit;
}

if (empty($_GET['state']) || empty($_SESSION['github_oauth_state']) || !hash_equals($_SESSION['github_oauth_state'], $_GET['state'])) {
    header('Location: workspace.php?github_error=state');
    exit;
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
    header('Location: workspace.php?github_error=oauth');
    exit;
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
