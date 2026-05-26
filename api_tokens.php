<?php
/**
 * Libre Claude - Clés API internes
 */
require_once dirname(__FILE__) . '/config.php';
require_once dirname(__FILE__) . '/auth.php';
require_once dirname(__FILE__) . '/i18n.php';
require_once dirname(__FILE__) . '/ui_confirm.php';

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

$success = '';
$error   = '';
$newToken = '';
$lang = current_language($user);
$t = fn($key) => t($key, $lang);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $name = trim($_POST['name'] ?? 'Integration');
        $token = 'lc_sk_' . bin2hex(random_bytes(32));
        $db->insert('api_tokens', [
            'user_id' => $user['id'],
            'name' => $name ?: 'Integration',
            'token_hash' => hash('sha256', $token),
            'prefix' => substr($token, 0, 8),
            'last_four' => substr($token, -4),
        ]);
        $newToken = $token;
        $success = $t('key_created_message');
    } elseif ($action === 'revoke') {
        $id = (int)($_POST['id'] ?? 0);
        $db->update('api_tokens', ['is_active' => 0], 'id = ? AND user_id = ?', [$id, $user['id']]);
        $success = $t('key_revoked_message');
    }
}

$tokens = $db->fetchAll(
    "SELECT id, name, prefix, last_four, is_active, created_at, last_used_at, expires_at
     FROM api_tokens
     WHERE user_id = ?
     ORDER BY created_at DESC",
    [$user['id']]
);

$baseUrl = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
$chatUrl = $baseUrl . '/chat.php';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($t('internal_keys_title')) ?> — Libre Claude</title>
<link rel="icon" href="libre-claude-icon.png" type="image/png">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#0a0a0f;--card:#13131a;--border:#1e1e2e;--text:#e8e6f0;--muted:#6b6880;--accent:#e6122a;--accent2:#ff3b4f;--err:#f87171;--success:#4ade80}
body{background:var(--bg);color:var(--text);font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;min-height:100vh;padding:40px 20px}
body::before{content:'';position:fixed;inset:0;background:radial-gradient(ellipse 60% 40% at 50% -10%,rgba(230,18,42,.14),transparent);pointer-events:none;z-index:0}
.wrap{max-width:860px;margin:0 auto;position:relative;z-index:1}
.top{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:28px}
.logo{width:260px;max-width:60%;height:auto}
.back{color:var(--muted);text-decoration:none;font-size:14px}
.back:hover{color:var(--text)}
h1{font-family:Georgia,"Times New Roman",serif;font-size:30px;margin-bottom:10px}
.sub{color:var(--muted);font-size:14px;margin-bottom:24px;line-height:1.5}
.card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:24px;margin-bottom:18px}
.grid{display:grid;grid-template-columns:1fr auto;gap:12px;align-items:end}
label{display:block;font-size:12px;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);margin-bottom:7px}
input{width:100%;padding:12px 14px;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:10px;color:var(--text);font-size:14px}
input:focus{outline:none;border-color:var(--accent)}
.btn{padding:11px 16px;background:linear-gradient(135deg,var(--accent),var(--accent2));border:none;border-radius:8px;color:#fff;font-size:14px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}
.btn.danger{background:rgba(248,113,113,.12);border:1px solid rgba(248,113,113,.35);color:var(--err)}
.msg{padding:12px 14px;border-radius:10px;font-size:13.5px;margin-bottom:18px}
.ok{background:rgba(74,222,128,.08);border:1px solid rgba(74,222,128,.25);color:var(--success)}
.err{background:rgba(248,113,113,.08);border:1px solid rgba(248,113,113,.3);color:var(--err)}
.secret{background:#050506;border:1px solid var(--border);border-radius:10px;padding:14px;overflow:auto;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;color:#fff;margin-top:10px}
.token-row{display:grid;grid-template-columns:1.3fr 1fr 1fr auto;gap:12px;align-items:center;padding:14px 0;border-bottom:1px solid var(--border)}
.token-row:last-child{border-bottom:none}
.name{font-weight:700}
.meta{color:var(--muted);font-size:12px;margin-top:3px}
.pill{display:inline-flex;padding:4px 8px;border-radius:999px;background:rgba(255,255,255,.06);color:var(--muted);font-size:12px}
.pill.on{background:rgba(74,222,128,.1);color:var(--success)}
pre{white-space:pre-wrap;background:#050506;border:1px solid var(--border);border-radius:10px;padding:14px;color:#e8e6f0;overflow:auto;font-size:13px}
@media(max-width:760px){.grid,.token-row{grid-template-columns:1fr}.logo{max-width:100%}.top{align-items:flex-start;flex-direction:column}}
</style>
<link rel="stylesheet" href="responsive.css">
</head>
<body>
<div class="wrap">
  <div class="top">
    <img class="logo" src="libre-claude-red-black-dark.png" alt="Libre Claude">
    <a class="back" href="index.php"><?= htmlspecialchars($t('back_chat')) ?></a>
  </div>

  <h1><?= htmlspecialchars($t('internal_keys_title')) ?></h1>
  <p class="sub"><?= htmlspecialchars($t('internal_keys_sub')) ?></p>

  <?php if ($success): ?><div class="msg ok"><?= htmlspecialchars($success) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="msg err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <?php if ($newToken): ?>
    <div class="card">
      <label><?= htmlspecialchars($t('new_key')) ?></label>
      <div class="secret"><?= htmlspecialchars($newToken) ?></div>
    </div>
  <?php endif; ?>

  <div class="card">
    <form method="POST" class="grid">
      <input type="hidden" name="action" value="create">
      <div>
        <label><?= htmlspecialchars($t('key_name')) ?></label>
        <input type="text" name="name" placeholder="<?= htmlspecialchars($t('local_script_placeholder')) ?>">
      </div>
      <button class="btn" type="submit"><?= htmlspecialchars($t('generate')) ?></button>
    </form>
  </div>

  <div class="card">
    <?php if (!$tokens): ?>
      <p class="meta"><?= htmlspecialchars($t('no_internal_key')) ?></p>
    <?php else: ?>
      <?php foreach ($tokens as $token): ?>
      <div class="token-row">
        <div>
          <div class="name"><?= htmlspecialchars($token['name']) ?></div>
          <div class="meta"><?= htmlspecialchars($token['prefix']) ?>...<?= htmlspecialchars($token['last_four']) ?></div>
        </div>
        <div><span class="pill <?= $token['is_active'] ? 'on' : '' ?>"><?= htmlspecialchars($token['is_active'] ? $t('active') : $t('revoked')) ?></span></div>
        <div>
          <div class="meta"><?= htmlspecialchars($t('created_at')) ?> : <?= htmlspecialchars($token['created_at']) ?></div>
          <div class="meta"><?= htmlspecialchars($t('last_used')) ?> : <?= htmlspecialchars($token['last_used_at'] ?: $t('never')) ?></div>
        </div>
        <div>
          <?php if ($token['is_active']): ?>
          <form method="POST" data-confirm="<?= htmlspecialchars($t('revoke_key_confirm'), ENT_QUOTES) ?>">
            <input type="hidden" name="action" value="revoke">
            <input type="hidden" name="id" value="<?= (int)$token['id'] ?>">
            <button class="btn danger" type="submit"><?= htmlspecialchars($t('revoke')) ?></button>
          </form>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div class="card">
    <label><?= htmlspecialchars($t('call_example')) ?></label>
    <pre>curl <?= htmlspecialchars($chatUrl) ?> \
  -H 'Authorization: Bearer lc_sk_votre_cle' \
  -H 'Content-Type: application/json' \
  -d '{"message":"<?= htmlspecialchars($t('hello_message')) ?>","model":"claude-opus-4.5"}'

# <?= htmlspecialchars($t('response_contains_id')) ?>
# <?= htmlspecialchars($t('reuse_id_hint')) ?>
curl <?= htmlspecialchars($chatUrl) ?> \
  -H 'Authorization: Bearer lc_sk_votre_cle' \
  -H 'Content-Type: application/json' \
  -d '{"conversation_id":1,"message":"<?= htmlspecialchars($t('continue_message')) ?>","model":"claude-opus-4.5"}'</pre>
  </div>
</div>
<?php render_confirm_ui($t); ?>
</body>
</html>
