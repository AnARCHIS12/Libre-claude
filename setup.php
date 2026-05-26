<?php
/**
 * Libre Claude - Installation de l'instance
 */
require_once dirname(__FILE__) . '/config.php';
require_once dirname(__FILE__) . '/database.php';
require_once dirname(__FILE__) . '/auth.php';
require_once dirname(__FILE__) . '/i18n.php';

$db = Database::getInstance();
if ($db->isInstalled()) {
    header('Location: index.php');
    exit;
}

$error = '';
$lang = current_language();
$t = fn($key) => t($key, $lang);

function parse_setup_api_keys($value) {
    $parts = preg_split('/[\r\n,]+/', $value);
    return array_values(array_filter(array_map('trim', $parts)));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $instanceName = trim($_POST['instance_name'] ?? 'Libre Claude');
    $username     = trim($_POST['username'] ?? '');
    $email        = trim($_POST['email'] ?? '');
    $password     = $_POST['password'] ?? '';
    $confirm      = $_POST['confirm'] ?? '';
    $apiKeys      = parse_setup_api_keys($_POST['api_keys'] ?? '');

    if ($instanceName === '') {
        $error = $t('instance_name_required');
    } elseif ($username === '' || $email === '' || $password === '') {
        $error = $t('admin_fields_required');
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = $t('invalid_email');
    } elseif (strlen($password) < PASSWORD_MIN_LENGTH) {
        $error = sprintf($t('password_too_short'), PASSWORD_MIN_LENGTH);
    } elseif ($password !== $confirm) {
        $error = $t('passwords_mismatch');
    } else {
        try {
            $db->beginTransaction();
            $db->insert('users', [
                'username'      => $username,
                'email'         => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role'          => 'admin',
            ]);
            $db->setSetting('instance_name', $instanceName);
            if ($apiKeys) {
                $db->setSetting('mistral_api_keys', json_encode($apiKeys));
            }
            $db->commit();

            $auth = new Auth();
            $auth->login($username, $password);

            header('Location: index.php');
            exit;
        } catch (Exception $e) {
            $db->rollback();
            libreclaude_log("Setup error: " . $e->getMessage(), 1);
            $error = $t('setup_failed');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($t('setup_title')) ?> — Libre Claude</title>
<link rel="icon" href="libre-claude-icon.png" type="image/png">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#0a0a0f;--card:#13131a;--border:#1e1e2e;--text:#e8e6f0;--muted:#6b6880;--accent:#e6122a;--accent2:#ff3b4f;--err:#f87171}
body{background:var(--bg);color:var(--text);font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:28px 18px}
body::before{content:'';position:fixed;inset:0;background:radial-gradient(ellipse 80% 60% at 50% -10%,rgba(230,18,42,.18),transparent);pointer-events:none}
.card{background:var(--card);border:1px solid var(--border);border-radius:20px;padding:42px 38px;width:100%;max-width:560px;position:relative}
.card::before{content:'';position:absolute;inset:-1px;border-radius:20px;background:linear-gradient(135deg,rgba(230,18,42,.3),transparent 60%);z-index:-1;pointer-events:none}
.brand{text-align:center;margin-bottom:30px}
.brand-logo{display:block;width:280px;max-width:100%;height:auto;margin:0 auto}
.brand-sub{font-size:13px;color:var(--muted);margin-top:4px;letter-spacing:.5px;text-transform:uppercase}
.err{background:rgba(248,113,113,.08);border:1px solid rgba(248,113,113,.3);color:var(--err);padding:12px 16px;border-radius:10px;font-size:13.5px;margin-bottom:22px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.field{margin-bottom:16px}
.field.full{grid-column:1/-1}
.field label{display:block;font-size:12px;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);margin-bottom:7px;font-weight:500}
.field input,.field textarea{width:100%;padding:13px 16px;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:10px;color:var(--text);font-size:15px;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;transition:.2s}
.field textarea{min-height:92px;resize:vertical;line-height:1.45}
.field input:focus,.field textarea:focus{outline:none;border-color:var(--accent);background:rgba(230,18,42,.06)}
.hint{font-size:12px;color:var(--muted);margin-top:6px;line-height:1.45}
.btn{width:100%;padding:14px;background:linear-gradient(135deg,var(--accent),var(--accent2));border:none;border-radius:10px;color:#fff;font-size:15px;font-weight:500;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;cursor:pointer;margin-top:8px;transition:.2s}
.btn:hover{opacity:.9;transform:translateY(-1px)}
.lang-switch{text-align:center;margin-top:18px;font-size:12px;color:var(--muted)}
.lang-switch a{color:var(--muted);text-decoration:none;margin:0 5px}
.lang-switch a.active,.lang-switch a:hover{color:var(--accent2)}
@media (max-width:640px){.card{padding:34px 24px}.grid{grid-template-columns:1fr}}
</style>
<link rel="stylesheet" href="responsive.css">
</head>
<body>
<div class="card">
  <div class="brand">
    <img class="brand-logo" src="libre-claude-red-black-dark.png" alt="Libre Claude">
    <div class="brand-sub"><?= htmlspecialchars($t('setup_title')) ?></div>
  </div>

  <?php if ($error): ?>
  <div class="err"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST" autocomplete="on">
    <div class="field">
      <label><?= htmlspecialchars($t('instance_name')) ?></label>
      <input type="text" name="instance_name" value="<?= htmlspecialchars($_POST['instance_name'] ?? 'Libre Claude') ?>" required>
    </div>

    <div class="grid">
      <div class="field">
        <label><?= htmlspecialchars($t('admin')) ?></label>
        <input type="text" name="username" placeholder="<?= htmlspecialchars($t('username_placeholder')) ?>" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autocomplete="username">
      </div>
      <div class="field">
        <label><?= htmlspecialchars($t('email')) ?></label>
        <input type="email" name="email" placeholder="<?= htmlspecialchars($t('email_placeholder')) ?>" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autocomplete="email">
      </div>
      <div class="field">
        <label><?= htmlspecialchars($t('password')) ?></label>
        <input type="password" name="password" placeholder="<?= htmlspecialchars(sprintf($t('password_min_placeholder'), PASSWORD_MIN_LENGTH)) ?>" required autocomplete="new-password">
      </div>
      <div class="field">
        <label><?= htmlspecialchars($t('confirm')) ?></label>
        <input type="password" name="confirm" placeholder="<?= htmlspecialchars($t('repeat_password_placeholder')) ?>" required autocomplete="new-password">
      </div>
      <div class="field full">
        <label><?= htmlspecialchars($t('shared_keys')) ?></label>
        <textarea name="api_keys" placeholder="<?= htmlspecialchars($t('one_key_per_line')) ?>"><?= htmlspecialchars($_POST['api_keys'] ?? '') ?></textarea>
        <div class="hint"><?= htmlspecialchars($t('setup_keys_hint')) ?></div>
      </div>
    </div>

    <button type="submit" class="btn"><?= htmlspecialchars($t('initialize')) ?></button>
  </form>
  <div class="lang-switch">
    <?php foreach (SUPPORTED_LANGUAGES as $code => $label): ?>
      <a class="<?= $lang === $code ? 'active' : '' ?>" href="?lang=<?= htmlspecialchars($code) ?>"><?= strtoupper($code) ?></a>
    <?php endforeach; ?>
  </div>
</div>
</body>
</html>
