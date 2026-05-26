<?php
/**
 * Libre Claude - Paramètres utilisateur
 */
require_once dirname(__FILE__) . '/config.php';
require_once dirname(__FILE__) . '/auth.php';
require_once dirname(__FILE__) . '/i18n.php';
require_once dirname(__FILE__) . '/memory.php';
require_once dirname(__FILE__) . '/ui_confirm.php';

$db = Database::getInstance();
if (!$db->isInstalled()) {
    header('Location: setup.php');
    exit;
}

$auth = new Auth();
if (!$auth->isAuthenticated()) {
    header('Location: login.php');
    exit;
}

$user    = $auth->getCurrentUser();
$lang = current_language($user);
$t = fn($key) => t($key, $lang);
$userSettings = json_decode($user['settings'] ?? '{}', true);
if (!is_array($userSettings)) $userSettings = [];
$memorySettings = memory_user_settings($user);
$languageOptions = SUPPORTED_LANGUAGES;
$memoryRows = $db->fetchAll(
    "SELECT id, scope, content, updated_at FROM user_memories WHERE user_id = ? ORDER BY updated_at DESC LIMIT 30",
    [$user['id']]
);
$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'update_api_key') {
        $key = trim($_POST['api_key'] ?? '');
        $auth->updateApiKey($user['id'], $key);
        $success = $t('api_key_updated');
        $user    = $auth->getCurrentUser();
    } elseif ($action === 'update_language') {
        $language = $_POST['language'] ?? 'fr';
        if (!isset($languageOptions[$language])) $language = 'fr';
        $auth->updateSettings($user['id'], ['language' => $language]);
        $_SESSION['language'] = $language;
        $lang = $language;
        $t = fn($key) => t($key, $lang);
        $success = $t('language_updated');
        $user    = $auth->getCurrentUser();
        $userSettings = json_decode($user['settings'] ?? '{}', true);
        if (!is_array($userSettings)) $userSettings = [];
        $memorySettings = memory_user_settings($user);
    } elseif ($action === 'update_memory') {
        $auth->updateSettings($user['id'], [
            'auto_memory' => !empty($_POST['auto_memory']),
            'workspace_context' => !empty($_POST['workspace_context']),
        ]);
        $success = $t('memory_saved');
        $user = $auth->getCurrentUser();
        $userSettings = json_decode($user['settings'] ?? '{}', true);
        if (!is_array($userSettings)) $userSettings = [];
        $memorySettings = memory_user_settings($user);
    } elseif ($action === 'delete_memory') {
        $memoryId = (int)($_POST['memory_id'] ?? 0);
        if ($memoryId > 0) {
            $db->delete('user_memories', 'id = ? AND user_id = ?', [$memoryId, $user['id']]);
            $success = $t('memory_deleted');
            $memoryRows = $db->fetchAll(
                "SELECT id, scope, content, updated_at FROM user_memories WHERE user_id = ? ORDER BY updated_at DESC LIMIT 30",
                [$user['id']]
            );
        }
    }
}
$memoryRows = $db->fetchAll(
    "SELECT id, scope, content, updated_at FROM user_memories WHERE user_id = ? ORDER BY updated_at DESC LIMIT 30",
    [$user['id']]
);
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($t('settings')) ?> — Libre Claude</title>
<link rel="icon" href="libre-claude-icon.png" type="image/png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#0a0a0f;--card:#13131a;--border:#1e1e2e;--text:#e8e6f0;--muted:#6b6880;--accent:#e6122a;--accent2:#ff3b4f;--err:#f87171;--success:#4ade80}
body{background:var(--bg);color:var(--text);font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;min-height:100vh;padding:40px 20px}
body::before{content:'';position:fixed;inset:0;background:radial-gradient(ellipse 60% 40% at 50% -10%,rgba(230,18,42,.12),transparent);pointer-events:none;z-index:0}
.wrap{max-width:640px;margin:0 auto;position:relative;z-index:1}
.settings-logo{display:block;width:260px;max-width:100%;height:auto;margin:0 0 28px}
.back{display:inline-flex;align-items:center;gap:8px;color:var(--muted);text-decoration:none;font-size:14px;margin-bottom:28px;transition:.2s}
.back:hover{color:var(--text)}
h1{font-family:Georgia,"Times New Roman",serif;font-size:28px;margin-bottom:32px;background:linear-gradient(135deg,var(--text),var(--muted));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:28px;margin-bottom:20px}
.card h2{font-size:14px;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);margin-bottom:20px;font-weight:500}
.field{margin-bottom:16px}
.field label{display:block;font-size:12px;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);margin-bottom:7px}
.field input,.field select{width:100%;padding:12px 16px;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:10px;color:var(--text);font-size:14px;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;transition:.2s}
.field select option{background:#13131a;color:var(--text)}
.field input:focus,.field select:focus{outline:none;border-color:var(--accent)}
.field .hint{font-size:12px;color:var(--muted);margin-top:6px}
.toggle-row{display:flex;align-items:flex-start;gap:12px;padding:13px 0;border-bottom:1px solid rgba(255,255,255,.05)}
.toggle-row:last-child{border-bottom:none}
.toggle-row input{width:18px;height:18px;accent-color:var(--accent);margin-top:2px}
.toggle-row strong{display:block;font-size:14px;color:var(--text);margin-bottom:2px}
.toggle-row span{display:block;font-size:12px;color:var(--muted);line-height:1.45}
.btn{padding:11px 20px;background:linear-gradient(135deg,var(--accent),var(--accent2));border:none;border-radius:8px;color:#fff;font-size:14px;font-weight:500;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;cursor:pointer;transition:.2s}
.btn-ghost{padding:7px 10px;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:8px;color:var(--muted);font-size:12px;cursor:pointer}
.btn-ghost:hover{color:var(--text);border-color:rgba(230,18,42,.35)}
.btn:hover{opacity:.9}
.msg-ok{background:rgba(74,222,128,.08);border:1px solid rgba(74,222,128,.25);color:var(--success);padding:12px 16px;border-radius:10px;font-size:13.5px;margin-bottom:20px}
.info-row{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--border)}
.info-row:last-child{border-bottom:none}
.info-row .lbl{font-size:13px;color:var(--muted)}
.info-row .val{font-size:13px;color:var(--text);font-weight:500}
.memory-item{display:flex;gap:12px;align-items:flex-start;padding:12px 0;border-bottom:1px solid rgba(255,255,255,.05)}
.memory-item:last-child{border-bottom:none}
.memory-dot{width:28px;height:28px;border-radius:8px;background:rgba(230,18,42,.12);color:var(--accent2);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.memory-body{min-width:0;flex:1}
.memory-body p{font-size:13px;line-height:1.45;color:var(--text);margin-bottom:5px}
.memory-meta{font-size:11.5px;color:var(--muted)}
.memory-empty{font-size:13px;color:var(--muted);line-height:1.5}
</style>
</head>
<body>
<div class="wrap">
  <a href="index.php" class="back"><i class="fa-solid fa-arrow-left"></i> <?= htmlspecialchars($t('back_chat')) ?></a>
  <img class="settings-logo" src="libre-claude-red-black-dark.png" alt="Libre Claude">
  <h1><?= htmlspecialchars($t('settings')) ?></h1>

  <?php if ($success): ?>
  <div class="msg-ok"><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>

  <!-- Infos compte -->
  <div class="card">
    <h2><?= htmlspecialchars($t('account')) ?></h2>
    <div class="info-row">
      <span class="lbl"><?= htmlspecialchars($t('username')) ?></span>
      <span class="val"><?= htmlspecialchars($user['username']) ?></span>
    </div>
    <div class="info-row">
      <span class="lbl"><?= htmlspecialchars($t('email')) ?></span>
      <span class="val"><?= htmlspecialchars($user['email']) ?></span>
    </div>
    <div class="info-row">
      <span class="lbl"><?= htmlspecialchars($t('member_since')) ?></span>
      <span class="val"><?= date('d/m/Y', strtotime($user['created_at'])) ?></span>
    </div>
    <div class="info-row">
      <span class="lbl"><?= htmlspecialchars($t('last_login')) ?></span>
      <span class="val"><?= $user['last_login'] ? date('d/m/Y H:i', strtotime($user['last_login'])) : 'N/A' ?></span>
    </div>
  </div>

  <!-- Clé API personnelle -->
  <div class="card">
    <h2><?= htmlspecialchars($t('personal_key')) ?></h2>
    <form method="POST">
      <input type="hidden" name="action" value="update_api_key">
      <div class="field">
        <label><?= htmlspecialchars($t('your_key')) ?></label>
        <input type="password" name="api_key" placeholder="<?= htmlspecialchars($t('shared_key_placeholder')) ?>" value="<?= htmlspecialchars($user['mistral_api_key'] ?? '') ?>">
        <div class="hint"><?= htmlspecialchars($t('key_hint')) ?></div>
      </div>
      <button type="submit" class="btn"><?= htmlspecialchars($t('save')) ?></button>
    </form>
  </div>

  <!-- Langue -->
  <div class="card">
    <h2><?= htmlspecialchars($t('language')) ?></h2>
    <form method="POST">
      <input type="hidden" name="action" value="update_language">
      <div class="field">
        <label><?= htmlspecialchars($t('language_field')) ?></label>
        <select name="language">
          <?php foreach ($languageOptions as $code => $label): ?>
          <option value="<?= htmlspecialchars($code) ?>" <?= ($userSettings['language'] ?? 'fr') === $code ? 'selected' : '' ?>>
            <?= htmlspecialchars($label) ?>
          </option>
          <?php endforeach; ?>
        </select>
        <div class="hint"><?= htmlspecialchars($t('language_hint')) ?></div>
      </div>
      <button type="submit" class="btn"><?= htmlspecialchars($t('save')) ?></button>
    </form>
  </div>

  <!-- Mémoire -->
  <div class="card">
    <h2><?= htmlspecialchars($t('memory_title')) ?></h2>
    <form method="POST">
      <input type="hidden" name="action" value="update_memory">
      <label class="toggle-row">
        <input type="checkbox" name="auto_memory" value="1" <?= $memorySettings['auto_memory'] ? 'checked' : '' ?>>
        <span>
          <strong><?= htmlspecialchars($t('memory_enabled')) ?></strong>
          <span><?= htmlspecialchars($t('auto_memory_hint')) ?></span>
        </span>
      </label>
      <label class="toggle-row">
        <input type="checkbox" name="workspace_context" value="1" <?= $memorySettings['workspace_context'] ? 'checked' : '' ?>>
        <span>
          <strong><?= htmlspecialchars($t('workspace_context_enabled')) ?></strong>
          <span><?= htmlspecialchars($t('workspace_context_hint')) ?></span>
        </span>
      </label>
      <button type="submit" class="btn" style="margin-top:16px"><?= htmlspecialchars($t('save')) ?></button>
    </form>
  </div>

  <div class="card">
    <h2><?= htmlspecialchars($t('memory_list')) ?></h2>
    <?php if (!$memoryRows): ?>
      <p class="memory-empty"><?= htmlspecialchars($t('no_memory')) ?></p>
    <?php else: ?>
      <?php foreach ($memoryRows as $memory): ?>
      <div class="memory-item">
        <div class="memory-dot"><i class="fa-solid <?= ($memory['scope'] ?? '') === 'workspace' ? 'fa-folder' : 'fa-brain' ?>"></i></div>
        <div class="memory-body">
          <p><?= htmlspecialchars($memory['content']) ?></p>
          <div class="memory-meta"><?= htmlspecialchars((($memory['scope'] ?? '') === 'workspace') ? $t('workspace') : $t('memory_general')) ?> · <?= htmlspecialchars(date('d/m/Y H:i', strtotime($memory['updated_at']))) ?></div>
        </div>
        <form method="POST" data-confirm="<?= htmlspecialchars($t('delete_memory_confirm'), ENT_QUOTES) ?>">
          <input type="hidden" name="action" value="delete_memory">
          <input type="hidden" name="memory_id" value="<?= (int)$memory['id'] ?>">
          <button type="submit" class="btn-ghost"><?= htmlspecialchars($t('delete')) ?></button>
        </form>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Modèles disponibles -->
  <div class="card">
    <h2><?= htmlspecialchars($t('models_available')) ?> (<?= array_sum(array_map('count', MISTRAL_MODELS)) ?>)</h2>
    <?php foreach (MISTRAL_MODELS as $cat => $models): ?>
    <div style="margin-bottom:14px">
      <div style="font-size:12px;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);margin-bottom:8px"><?= ucfirst($cat) ?></div>
      <?php foreach ($models as $m): ?>
      <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid rgba(255,255,255,.04)">
        <span style="font-size:13px;color:var(--text)"><?= htmlspecialchars($m['name']) ?></span>
        <span style="font-size:11px;color:var(--muted);font-family:monospace"><?= htmlspecialchars($m['id']) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php render_confirm_ui($t); ?>
</body>
</html>
