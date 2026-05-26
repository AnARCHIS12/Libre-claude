<?php
/**
 * Libre Claude - Inscription
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
    $username  = trim($_POST['username'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $password  = $_POST['password'] ?? '';
    $confirm   = $_POST['confirm'] ?? '';

    if (!$username || !$email || !$password) {
        $error = $t('all_fields_required');
    } elseif ($password !== $confirm) {
        $error = $t('passwords_mismatch');
    } else {
        $result = $auth->register($username, $email, $password);
        if ($result['success']) {
            $auth->login($username, $password);
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
<title><?= htmlspecialchars($t('register')) ?> — Libre Claude</title>
<link rel="icon" href="libre-claude-icon.png" type="image/png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#0a0a0f;--card:#13131a;--border:#1e1e2e;--text:#e8e6f0;--muted:#6b6880;--accent:#e6122a;--accent2:#ff3b4f;--err:#f87171;--success:#4ade80}
body{background:var(--bg);color:var(--text);font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
body::before{content:'';position:fixed;inset:0;background:radial-gradient(ellipse 80% 60% at 50% -10%,rgba(230,18,42,.18),transparent);pointer-events:none}
.card{background:var(--card);border:1px solid var(--border);border-radius:20px;padding:48px 40px;width:100%;max-width:440px;position:relative}
.card::before{content:'';position:absolute;inset:-1px;border-radius:20px;background:linear-gradient(135deg,rgba(230,18,42,.3),transparent 60%);z-index:-1;pointer-events:none}
.brand{text-align:center;margin-bottom:36px}
.brand-logo{display:block;width:260px;max-width:100%;height:auto;margin:0 auto}
.brand-sub{font-size:13px;color:var(--muted);margin-top:4px;letter-spacing:.5px;text-transform:uppercase}
.err{background:rgba(248,113,113,.08);border:1px solid rgba(248,113,113,.3);color:var(--err);padding:12px 16px;border-radius:10px;font-size:13.5px;margin-bottom:24px}
.field{margin-bottom:16px}
.field label{display:block;font-size:12px;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);margin-bottom:7px;font-weight:500}
.field input{width:100%;padding:13px 16px;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:10px;color:var(--text);font-size:15px;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;transition:.2s}
.field input:focus{outline:none;border-color:var(--accent);background:rgba(230,18,42,.06)}
.perks{margin:20px 0;padding:16px;background:rgba(230,18,42,.06);border:1px solid rgba(230,18,42,.15);border-radius:10px}
.perks p{font-size:12px;color:var(--muted);margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px;font-weight:500}
.perks ul{list-style:none}
.perks li{font-size:13px;color:var(--muted);padding:3px 0}
.perks li::before{content:'\f00c';font-family:"Font Awesome 6 Free";font-weight:900;color:var(--accent2);margin-right:8px}
.btn{width:100%;padding:14px;background:linear-gradient(135deg,var(--accent),var(--accent2));border:none;border-radius:10px;color:#fff;font-size:15px;font-weight:500;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;cursor:pointer;margin-top:8px;transition:.2s}
.btn:hover{opacity:.9;transform:translateY(-1px)}
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
    <div class="brand-sub"><?= htmlspecialchars($t('register')) ?></div>
  </div>

  <?php if ($error): ?>
  <div class="err"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST">
    <div class="field">
      <label><?= htmlspecialchars($t('username')) ?></label>
      <input type="text" name="username" placeholder="<?= htmlspecialchars($t('username_placeholder')) ?>" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
    </div>
    <div class="field">
      <label><?= htmlspecialchars($t('email')) ?></label>
      <input type="email" name="email" placeholder="<?= htmlspecialchars($t('email_placeholder')) ?>" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
    </div>
    <div class="field">
      <label><?= htmlspecialchars($t('password')) ?></label>
      <input type="password" name="password" placeholder="<?= htmlspecialchars(sprintf($t('password_min_placeholder'), PASSWORD_MIN_LENGTH)) ?>" required>
    </div>
    <div class="field">
      <label><?= htmlspecialchars($t('confirm')) ?></label>
      <input type="password" name="confirm" placeholder="<?= htmlspecialchars($t('repeat_password_placeholder')) ?>" required>
    </div>

    <div class="perks">
      <p><?= htmlspecialchars($t('included')) ?></p>
      <ul>
        <li><?= htmlspecialchars($t('perk_models')) ?></li>
        <li><?= htmlspecialchars($t('perk_history')) ?></li>
        <li><?= htmlspecialchars($t('perk_rotation')) ?></li>
        <li><?= htmlspecialchars($t('perk_interface')) ?></li>
      </ul>
    </div>

    <button type="submit" class="btn"><?= htmlspecialchars($t('create_account')) ?></button>
  </form>

  <div class="links">
    <?= htmlspecialchars($t('already_account')) ?> <a href="login.php"><?= htmlspecialchars($t('sign_in')) ?></a>
  </div>
  <div class="lang-switch">
    <?php foreach (SUPPORTED_LANGUAGES as $code => $label): ?>
      <a class="<?= $lang === $code ? 'active' : '' ?>" href="?lang=<?= htmlspecialchars($code) ?>"><?= strtoupper($code) ?></a>
    <?php endforeach; ?>
  </div>
</div>
</body>
</html>
