<?php
/**
 * Libre Claude - Connexion
 */
require_once dirname(__FILE__) . '/config.php';
require_once dirname(__FILE__) . '/auth.php';
require_once dirname(__FILE__) . '/i18n.php';

$db = Database::getInstance();
if (!$db->isInstalled()) {
    header('Location: setup.php');
    exit;
}

$auth  = new Auth();
$lang = current_language();
$t = fn($key) => t($key, $lang);
$error = '';

if ($auth->isAuthenticated()) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = $t('fill_fields');
    } else {
        $result = $auth->login($username, $password);
        if ($result['success']) {
            header('Location: index.php');
            exit;
        }
        $error = $result['error'];
    }
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($t('login')) ?> — Libre Claude</title>
<link rel="icon" href="libre-claude-icon.png" type="image/png">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#0a0a0f;--card:#13131a;--border:#1e1e2e;--text:#e8e6f0;--muted:#6b6880;--accent:#e6122a;--accent2:#ff3b4f;--err:#f87171;--success:#4ade80}
body{background:var(--bg);color:var(--text);font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
body::before{content:'';position:fixed;inset:0;background:radial-gradient(ellipse 80% 60% at 50% -10%,rgba(230,18,42,.18),transparent);pointer-events:none}
.card{background:var(--card);border:1px solid var(--border);border-radius:20px;padding:48px 40px;width:100%;max-width:420px;position:relative}
.card::before{content:'';position:absolute;inset:-1px;border-radius:20px;background:linear-gradient(135deg,rgba(230,18,42,.3),transparent 60%);z-index:-1;pointer-events:none}
.brand{text-align:center;margin-bottom:36px}
.brand-logo{display:block;width:260px;max-width:100%;height:auto;margin:0 auto}
.brand-sub{font-size:13px;color:var(--muted);margin-top:4px;letter-spacing:.5px;text-transform:uppercase}
.err{background:rgba(248,113,113,.08);border:1px solid rgba(248,113,113,.3);color:var(--err);padding:12px 16px;border-radius:10px;font-size:13.5px;margin-bottom:24px}
.field{margin-bottom:18px}
.field label{display:block;font-size:12px;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);margin-bottom:8px;font-weight:500}
.field input{width:100%;padding:13px 16px;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:10px;color:var(--text);font-size:15px;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;transition:.2s}
.field input:focus{outline:none;border-color:var(--accent);background:rgba(230,18,42,.06)}
.btn{width:100%;padding:14px;background:linear-gradient(135deg,var(--accent),var(--accent2));border:none;border-radius:10px;color:#fff;font-size:15px;font-weight:500;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;cursor:pointer;margin-top:8px;transition:.2s;letter-spacing:.3px}
.btn:hover{opacity:.9;transform:translateY(-1px)}
.btn:active{transform:translateY(0)}
.links{text-align:center;margin-top:24px;font-size:13.5px;color:var(--muted)}
.links a{color:var(--accent2);text-decoration:none;font-weight:500}
.links a:hover{text-decoration:underline}
.lang-switch{text-align:center;margin-top:18px;font-size:12px;color:var(--muted)}
.lang-switch a{color:var(--muted);text-decoration:none;margin:0 5px}
.lang-switch a.active,.lang-switch a:hover{color:var(--accent2)}
</style>
<link rel="stylesheet" href="responsive.css">
</head>
<body>
<div class="card">
  <div class="brand">
    <img class="brand-logo" src="libre-claude-red-black-dark.png" alt="Libre Claude">
    <div class="brand-sub"><?= htmlspecialchars($t('app_subtitle')) ?></div>
  </div>

  <?php if ($error): ?>
  <div class="err"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST" autocomplete="on">
    <div class="field">
      <label for="username"><?= htmlspecialchars($t('identifier')) ?></label>
      <input type="text" id="username" name="username" placeholder="<?= htmlspecialchars($t('username')) ?> / <?= htmlspecialchars($t('email')) ?>" autofocus autocomplete="username">
    </div>
    <div class="field">
      <label for="password"><?= htmlspecialchars($t('password')) ?></label>
      <input type="password" id="password" name="password" placeholder="••••••••" autocomplete="current-password">
    </div>
    <button type="submit" class="btn"><?= htmlspecialchars($t('sign_in')) ?></button>
  </form>

  <div class="links">
    <?= htmlspecialchars($t('no_account')) ?> <a href="register.php"><?= htmlspecialchars($t('register')) ?></a>
  </div>
  <div class="lang-switch">
    <?php foreach (SUPPORTED_LANGUAGES as $code => $label): ?>
      <a class="<?= $lang === $code ? 'active' : '' ?>" href="?lang=<?= htmlspecialchars($code) ?>"><?= strtoupper($code) ?></a>
    <?php endforeach; ?>
  </div>
</div>
</body>
</html>
