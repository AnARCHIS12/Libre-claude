<?php
/**
 * Libre Claude - Administration des clés Claude serveur
 */
require_once dirname(__FILE__) . '/config.php';
require_once dirname(__FILE__) . '/auth.php';
require_once dirname(__FILE__) . '/i18n.php';

$db = Database::getInstance();
if (!$db->isInstalled()) {
    header('Location: setup.php');
    exit;
}

$auth = new Auth();
$user = $auth->getCurrentUser();
if (!$user || ($user['role'] ?? '') !== 'admin') {
    header('Location: index.php');
    exit;
}

$success = '';
$error   = '';
$lang = current_language($user);
$t = fn($key) => t($key, $lang);

function load_mistral_keys($db) {
    $items = json_decode($db->getSetting('mistral_api_keys', '[]'), true);
    if (!is_array($items)) return [];

    $normalized = [];
    foreach ($items as $item) {
        if (is_string($item)) {
            $key = trim($item);
            if ($key !== '') {
                $normalized[] = [
                    'id' => bin2hex(random_bytes(6)),
                    'name' => 'Clé importée',
                    'key' => $key,
                    'active' => true,
                    'status' => 'non testée',
                    'last_tested_at' => null,
                    'created_at' => date('Y-m-d H:i:s'),
                ];
            }
        } elseif (is_array($item) && trim($item['key'] ?? '') !== '') {
            $item['id'] = $item['id'] ?? bin2hex(random_bytes(6));
            $item['name'] = trim($item['name'] ?? 'Clé Claude');
            $item['active'] = (bool)($item['active'] ?? true);
            $item['status'] = $item['status'] ?? 'non testée';
            $item['last_tested_at'] = $item['last_tested_at'] ?? null;
            $item['created_at'] = $item['created_at'] ?? date('Y-m-d H:i:s');
            $normalized[] = $item;
        }
    }
    return $normalized;
}

function save_mistral_keys($db, $keys) {
    $db->setSetting('mistral_api_keys', json_encode(array_values($keys)));
}

function mask_key($key) {
    return substr($key, 0, 7) . '...' . substr($key, -4);
}

function test_mistral_key($key) {
    $ch = curl_init('https://api.mistral.ai/v1/models');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $key,
            'Accept: application/json',
        ],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) return ['ok' => false, 'status' => 'cURL: ' . $curlError];
    if ($httpCode >= 200 && $httpCode < 300) return ['ok' => true, 'status' => 'valide'];

    $decoded = json_decode($response, true);
    $message = $decoded['message'] ?? $decoded['error']['message'] ?? 'HTTP ' . $httpCode;
    return ['ok' => false, 'status' => $message];
}

$keys = load_mistral_keys($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? 'Production');
        $key  = trim($_POST['api_key'] ?? '');
        if ($key === '') {
            $error = $t('paste_claude_key_error');
        } else {
            $keys[] = [
                'id' => bin2hex(random_bytes(6)),
                'name' => $name ?: 'Production',
                'key' => $key,
                'active' => true,
                'status' => 'non testée',
                'last_tested_at' => null,
                'created_at' => date('Y-m-d H:i:s'),
            ];
            save_mistral_keys($db, $keys);
            $success = $t('server_key_added');
        }
    } elseif ($action === 'delete') {
        $id = $_POST['id'] ?? '';
        $keys = array_values(array_filter($keys, fn($item) => ($item['id'] ?? '') !== $id));
        save_mistral_keys($db, $keys);
        $success = $t('key_deleted');
    } elseif ($action === 'toggle') {
        $id = $_POST['id'] ?? '';
        foreach ($keys as &$item) {
            if (($item['id'] ?? '') === $id) $item['active'] = empty($item['active']);
        }
        unset($item);
        save_mistral_keys($db, $keys);
        $success = $t('state_updated');
    } elseif ($action === 'test') {
        $id = $_POST['id'] ?? '';
        foreach ($keys as &$item) {
            if (($item['id'] ?? '') === $id) {
                $result = test_mistral_key($item['key']);
                $item['status'] = $result['status'];
                $item['last_tested_at'] = date('Y-m-d H:i:s');
                $success = $result['ok'] ? $t('key_valid') : $t('test_done_error');
                break;
            }
        }
        unset($item);
        save_mistral_keys($db, $keys);
    }
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($t('server_keys_title')) ?> — Libre Claude</title>
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
.grid{display:grid;grid-template-columns:1fr 1.5fr auto;gap:12px;align-items:end}
label{display:block;font-size:12px;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);margin-bottom:7px}
input{width:100%;padding:12px 14px;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:10px;color:var(--text);font-size:14px}
input:focus{outline:none;border-color:var(--accent)}
.btn{padding:11px 16px;background:linear-gradient(135deg,var(--accent),var(--accent2));border:none;border-radius:8px;color:#fff;font-size:14px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:8px}
.btn.secondary{background:rgba(255,255,255,.05);border:1px solid var(--border);color:var(--text)}
.btn.danger{background:rgba(248,113,113,.12);border:1px solid rgba(248,113,113,.35);color:var(--err)}
.msg{padding:12px 14px;border-radius:10px;font-size:13.5px;margin-bottom:18px}
.ok{background:rgba(74,222,128,.08);border:1px solid rgba(74,222,128,.25);color:var(--success)}
.err{background:rgba(248,113,113,.08);border:1px solid rgba(248,113,113,.3);color:var(--err)}
.key-row{display:grid;grid-template-columns:1.2fr 1fr 1fr auto;gap:12px;align-items:center;padding:14px 0;border-bottom:1px solid var(--border)}
.key-row:last-child{border-bottom:none}
.name{font-weight:700}
.meta{color:var(--muted);font-size:12px;margin-top:3px}
.pill{display:inline-flex;padding:4px 8px;border-radius:999px;background:rgba(255,255,255,.06);color:var(--muted);font-size:12px}
.pill.on{background:rgba(74,222,128,.1);color:var(--success)}
.actions{display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap}
.actions form{display:inline}
@media(max-width:760px){.grid,.key-row{grid-template-columns:1fr}.actions{justify-content:flex-start}.logo{max-width:100%}.top{align-items:flex-start;flex-direction:column}}
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <img class="logo" src="libre-claude-red-black-dark.png" alt="Libre Claude">
    <a class="back" href="index.php"><?= htmlspecialchars($t('back_chat')) ?></a>
  </div>

  <h1><?= htmlspecialchars($t('server_keys_title')) ?></h1>
  <p class="sub"><?= htmlspecialchars($t('server_keys_sub')) ?></p>

  <?php if ($success): ?><div class="msg ok"><?= htmlspecialchars($success) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="msg err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <div class="card">
    <form method="POST" class="grid">
      <input type="hidden" name="action" value="add">
      <div>
        <label><?= htmlspecialchars($t('name')) ?></label>
        <input type="text" name="name" placeholder="Production">
      </div>
      <div>
        <label><?= htmlspecialchars($t('api_key')) ?></label>
        <input type="password" name="api_key" placeholder="<?= htmlspecialchars($t('paste_key')) ?>">
      </div>
      <button class="btn" type="submit"><?= htmlspecialchars($t('add')) ?></button>
    </form>
    <p class="meta" style="margin-top:12px"><?= htmlspecialchars($t('key_creation')) ?> : <a class="back" href="https://console.mistral.ai/api-keys" target="_blank" rel="noopener">Console API / keys</a></p>
  </div>

  <div class="card">
    <?php if (!$keys): ?>
      <p class="meta"><?= htmlspecialchars($t('no_server_key')) ?></p>
    <?php else: ?>
      <?php foreach ($keys as $item): ?>
      <div class="key-row">
        <div>
          <div class="name"><?= htmlspecialchars($item['name']) ?></div>
          <div class="meta"><?= htmlspecialchars(mask_key($item['key'])) ?></div>
        </div>
        <div>
          <span class="pill <?= !empty($item['active']) ? 'on' : '' ?>"><?= htmlspecialchars(!empty($item['active']) ? $t('active') : $t('disabled')) ?></span>
        </div>
        <div>
          <div><?= htmlspecialchars($item['status'] ?? 'non testée') ?></div>
          <div class="meta"><?= htmlspecialchars($item['last_tested_at'] ?? $t('never_tested')) ?></div>
        </div>
        <div class="actions">
          <form method="POST"><input type="hidden" name="action" value="test"><input type="hidden" name="id" value="<?= htmlspecialchars($item['id']) ?>"><button class="btn secondary" type="submit"><?= htmlspecialchars($t('test')) ?></button></form>
          <form method="POST"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= htmlspecialchars($item['id']) ?>"><button class="btn secondary" type="submit"><?= htmlspecialchars(!empty($item['active']) ? $t('disable') : $t('enable')) ?></button></form>
          <form method="POST" onsubmit="return confirm('<?= htmlspecialchars($t('delete_key_confirm')) ?>')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= htmlspecialchars($item['id']) ?>"><button class="btn danger" type="submit"><?= htmlspecialchars($t('delete')) ?></button></form>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
