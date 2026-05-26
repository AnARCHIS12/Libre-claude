<?php
/**
 * Libre Claude - Interface principale conversationnelle
 * Tout est à la racine — index.php
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
$lang = current_language($user);
$t = fn($key) => t($key, $lang);
$jsText = fn($key) => htmlspecialchars(json_encode($t($key), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
$githubRepoUrl = 'https://github.com/AnARCHIS12/Libre-claude';
$githubReleaseApi = 'https://api.github.com/repos/AnARCHIS12/Libre-claude/releases/latest';

// Conversations récentes si connecté
$recentConvs = [];
$workspaceGithub = null;
$workspaceFileCount = 0;
if ($user) {
    $recentConvs = $db->fetchAll(
        "SELECT id, title, model_used, updated_at FROM conversations 
         WHERE user_id = ? AND is_archived = 0 
         ORDER BY updated_at DESC LIMIT 30",
        [$user['id']]
    );
    $workspaceGithub = $db->fetch("SELECT owner, repo, branch, token, updated_at FROM workspace_github WHERE user_id = ?", [$user['id']]);
    $workspaceFileCountRow = $db->fetch("SELECT COUNT(*) AS total FROM workspace_files WHERE user_id = ?", [$user['id']]);
    $workspaceFileCount = (int)($workspaceFileCountRow['total'] ?? 0);
}

// Modèle par défaut sélectionné
$defaultModel = MASTER_AGENT_MODEL;
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Libre Claude — <?= htmlspecialchars($t('app_subtitle')) ?></title>
<link rel="icon" href="libre-claude-icon.png" type="image/png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

<style>
/* ============================================================
   RESET & ROOT
   ============================================================ */
*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
:root {
  --bg: #0a0a0f;
  --sidebar: #0f0f18;
  --card: #13131e;
  --surface: #18182a;
  --border: #1e1e30;
  --border2: #252538;
  --text: #e8e6f5;
  --muted: #5c5a72;
  --muted2: #8886a0;
  --accent: #e6122a;
  --accent2: #ff3b4f;
  --accent3: #ff7887;
  --user-bg: #1a1a2e;
  --user-border: #2a2a48;
  --ai-accent: #e6122a;
  --success: #4ade80;
  --err: #f87171;
  --warn: #fbbf24;
  --sidebar-w: 260px;
  --input-h: 52px;
  --radius: 14px;
  --radius-sm: 8px;
  --app-height: 100dvh;
}

html, body {
  height: 100%;
  min-height: 100%;
  overflow: hidden;
}
body {
  background: var(--bg);
  color: var(--text);
  font-family: system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
  font-size: 15px;
  line-height: 1.65;
  display: flex;
  height: var(--app-height);
  min-height: var(--app-height);
}

/* ============================================================
   SCROLLBAR
   ============================================================ */
::-webkit-scrollbar { width: 5px; height: 5px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--border2); border-radius: 99px; }
::-webkit-scrollbar-thumb:hover { background: var(--muted); }

/* ============================================================
   SIDEBAR
   ============================================================ */
.sidebar {
  width: var(--sidebar-w);
  min-width: var(--sidebar-w);
  height: var(--app-height);
  background: var(--sidebar);
  border-right: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  overflow: hidden;
  transition: transform .3s ease;
  position: relative;
  z-index: 10;
}

.sidebar-top {
  padding: 20px 16px 12px;
  border-bottom: 1px solid var(--border);
}

.brand {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 16px;
}

.brand-logo {
  display: block;
  width: 190px;
  height: auto;
}

.new-chat-btn {
  width: 100%;
  padding: 10px 14px;
  background: linear-gradient(135deg, var(--accent), var(--accent2));
  border: none;
  border-radius: var(--radius-sm);
  color: #fff;
  font-size: 13.5px;
  font-weight: 500;
  font-family: system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 8px;
  transition: opacity .2s, transform .15s;
}
.new-chat-btn:hover { opacity: .9; transform: translateY(-1px); }
.new-chat-btn svg { width: 15px; height: 15px; flex-shrink: 0; }

/* History */
.sidebar-search {
  padding: 10px 16px;
}
.sidebar-search input {
  width: 100%;
  padding: 8px 12px;
  background: rgba(255,255,255,.04);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  color: var(--text);
  font-size: 13px;
  font-family: system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
  outline: none;
}
.sidebar-search input::placeholder { color: var(--muted); }
.sidebar-search input:focus { border-color: var(--accent); }

.conv-list {
  flex: 1;
  overflow-y: auto;
  padding: 4px 8px;
}

.conv-section-label {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: .8px;
  color: var(--muted);
  padding: 10px 8px 4px;
  font-weight: 500;
}

.workspace-menu {
  padding: 4px 0 8px;
  margin: 0 0 4px;
}
.workspace-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 8px 4px;
}
.workspace-head .conv-section-label {
  padding: 10px 0 4px;
}
.workspace-add {
  width: 26px;
  height: 26px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 7px;
  color: var(--muted2);
  text-decoration: none;
  font-size: 12px;
  transition: background .15s, color .15s;
}
.workspace-add:hover {
  background: var(--surface);
  color: var(--text);
}
.workspace-item {
  display: flex;
  align-items: center;
  gap: 10px;
  min-height: 40px;
  padding: 9px 10px;
  border-radius: var(--radius-sm);
  color: var(--muted2);
  text-decoration: none;
  transition: background .15s, color .15s;
}
.workspace-item:hover {
  background: var(--surface);
  color: var(--text);
}
.workspace-item i {
  width: 16px;
  color: var(--muted2);
  text-align: center;
  flex-shrink: 0;
}
.workspace-item:hover i { color: var(--accent2); }
.workspace-copy {
  min-width: 0;
  flex: 1;
}
.workspace-title {
  display: block;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
  font-size: 13.5px;
  font-weight: 500;
}
.workspace-meta {
  display: block;
  margin-top: 1px;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
  color: var(--muted2);
  font-size: 11.5px;
  line-height: 1.35;
}

.conv-item {
  display: flex;
  align-items: center;
  padding: 9px 10px;
  border-radius: var(--radius-sm);
  cursor: pointer;
  transition: background .15s;
  gap: 8px;
  position: relative;
  overflow: hidden;
  text-decoration: none;
  color: var(--muted2);
  font-size: 13.5px;
}
.conv-item:hover { background: var(--surface); color: var(--text); }
.conv-item.active { background: rgba(230,18,42,.15); color: var(--text); }
.conv-item .conv-title {
  flex: 1;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  font-size: 13.5px;
}
.conv-item .conv-del {
  opacity: 0;
  color: var(--muted);
  background: none;
  border: none;
  cursor: pointer;
  padding: 2px 4px;
  border-radius: 4px;
  font-size: 14px;
  transition: opacity .15s, color .15s;
  flex-shrink: 0;
}
.conv-item:hover .conv-del { opacity: 1; }
.conv-item .conv-del:hover { color: var(--err); }

/* Sidebar footer */
.sidebar-footer {
  border-top: 1px solid var(--border);
  padding: 12px 10px;
}

.nav-link {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 10px;
  border-radius: var(--radius-sm);
  color: var(--muted2);
  text-decoration: none;
  font-size: 13.5px;
  transition: background .15s, color .15s;
}
.nav-link:hover { background: var(--surface); color: var(--text); }
.nav-link svg { width: 16px; height: 16px; flex-shrink: 0; }
.nav-link i { width: 16px; text-align: center; flex-shrink: 0; }

.user-pill {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 10px;
  border-radius: var(--radius-sm);
  margin-top: 4px;
}
.user-avatar {
  width: 30px; height: 30px;
  background: linear-gradient(135deg, var(--accent), var(--accent2));
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 12px;
  font-weight: 600;
  color: #fff;
  flex-shrink: 0;
}
.user-name { font-size: 13px; color: var(--muted2); flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.logout-link {
  color: var(--muted);
  font-size: 18px;
  text-decoration: none;
  padding: 4px;
  border-radius: 4px;
  transition: color .15s;
}
.logout-link:hover { color: var(--err); }

/* ============================================================
   MAIN
   ============================================================ */
.main {
  flex: 1;
  display: flex;
  flex-direction: column;
  height: var(--app-height);
  min-width: 0;
  overflow: hidden;
  position: relative;
}

/* Ambient bg glow */
.main::before {
  content: '';
  position: absolute;
  top: -200px; left: 50%;
  transform: translateX(-50%);
  width: 700px; height: 500px;
  background: radial-gradient(ellipse, rgba(230,18,42,.08) 0%, transparent 70%);
  pointer-events: none;
  z-index: 0;
}

/* ============================================================
   MESSAGES AREA
   ============================================================ */
.messages-wrap {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  position: relative;
  z-index: 1;
  overscroll-behavior: contain;
  scroll-padding-bottom: 32px;
}

.messages-inner {
  max-width: 740px;
  margin: 0 auto;
  padding: 30px 24px 26px;
}

/* Welcome screen */
.welcome {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: flex-start;
  min-height: auto;
  text-align: center;
  padding: 24px 20px 14px;
  animation: fadeUp .5s ease;
}

.welcome-logo {
  display: block;
  width: min(240px, 70vw);
  height: auto;
  margin-bottom: 12px;
  filter: drop-shadow(0 0 34px rgba(230,18,42,.22));
}

.welcome h1 {
  font-family: Georgia,"Times New Roman",serif;
  font-size: clamp(25px, 4vw, 32px);
  background: linear-gradient(135deg, var(--text) 40%, var(--muted2));
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
  margin-bottom: 6px;
  line-height: 1.2;
}

.welcome-sub {
  color: var(--muted2);
  font-size: 14px;
  margin-bottom: 12px;
  font-weight: 300;
}

.welcome-manifesto {
  width: 100%;
  max-width: 620px;
  margin: 0 0 16px;
  padding: 14px 16px;
  border: 1px solid rgba(230,18,42,.28);
  border-radius: var(--radius);
  background: linear-gradient(135deg, rgba(230,18,42,.12), rgba(255,255,255,.03));
  text-align: left;
  box-shadow: 0 18px 50px rgba(0,0,0,.22);
}
.welcome-manifesto strong {
  display: block;
  color: var(--text);
  font-size: 14px;
  margin-bottom: 6px;
}
.welcome-manifesto span {
  display: block;
  color: var(--muted2);
  font-size: 12.5px;
  line-height: 1.45;
}
.project-links {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  justify-content: center;
  margin: 0 0 16px;
}
.project-link {
  min-height: 34px;
  display: inline-flex;
  align-items: center;
  gap: 10px;
  color: var(--text);
  text-decoration: none;
  border: 1px solid rgba(230,18,42,.28);
  border-radius: 999px;
  background: rgba(255,255,255,.045);
  padding: 7px 11px;
  font-size: 12.5px;
  font-weight: 700;
  box-shadow: 0 12px 34px rgba(0,0,0,.24);
  transition: transform .2s, border-color .2s, background .2s;
}
.project-link:hover {
  transform: translateY(-1px);
  border-color: rgba(255,59,79,.65);
  background: rgba(230,18,42,.1);
}
.release-pill {
  color: #fff;
  background: linear-gradient(135deg, var(--accent), var(--accent2));
  border: 1px solid rgba(255,255,255,.12);
}
.release-version {
  display: inline-flex;
  align-items: center;
  min-height: 22px;
  padding: 2px 8px;
  border-radius: 999px;
  color: var(--accent2);
  background: rgba(0,0,0,.25);
  border: 1px solid rgba(255,255,255,.12);
  font-size: 11px;
  font-family: ui-monospace,SFMono-Regular,Menlo,monospace;
}

.starters {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 10px;
  width: 100%;
  max-width: 560px;
}

.starter-card {
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 14px 14px 12px;
  cursor: pointer;
  text-align: left;
  transition: border-color .2s, transform .2s, background .2s;
  position: relative;
  overflow: hidden;
}
.starter-card::before {
  content: '';
  position: absolute;
  inset: 0;
  background: linear-gradient(135deg, rgba(230,18,42,.05), transparent);
  opacity: 0;
  transition: opacity .2s;
}
.starter-card:hover { border-color: var(--accent); transform: translateY(-2px); }
.starter-card:hover::before { opacity: 1; }
.starter-icon { font-size: 20px; display: block; margin-bottom: 7px; color: var(--accent2); }
.starter-title { font-size: 13.5px; font-weight: 500; color: var(--text); margin-bottom: 4px; }
.starter-desc { font-size: 12px; color: var(--muted2); line-height: 1.5; }

/* Messages */
.msg {
  margin-bottom: 28px;
  animation: fadeUp .3s ease;
}

@keyframes fadeUp {
  from { opacity: 0; transform: translateY(12px); }
  to   { opacity: 1; transform: translateY(0); }
}

.msg-user {
  display: flex;
  justify-content: flex-end;
}

.msg-user .bubble {
  background: var(--user-bg);
  border: 1px solid var(--user-border);
  border-radius: var(--radius) var(--radius) 4px var(--radius);
  padding: 14px 18px;
  max-width: 80%;
  font-size: 15px;
  line-height: 1.65;
  white-space: pre-wrap;
  word-wrap: break-word;
}

.msg-ai {
  display: flex;
  gap: 14px;
  align-items: flex-start;
}

.ai-avatar {
  width: 32px; height: 32px;
  border-radius: 8px;
  object-fit: cover;
  flex-shrink: 0;
  margin-top: 2px;
}

.ai-body { flex: 1; min-width: 0; }

.ai-meta {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 8px;
}

.ai-name {
  font-size: 13px;
  font-weight: 500;
  color: var(--accent2);
}

.ai-model {
  font-size: 11px;
  color: var(--muted);
  background: rgba(230,18,42,.1);
  border: 1px solid rgba(230,18,42,.2);
  border-radius: 99px;
  padding: 2px 8px;
  font-family: monospace;
}

.ai-content {
  font-size: 15px;
  line-height: 1.75;
  color: var(--text);
  white-space: pre-wrap;
  word-wrap: break-word;
}

/* Code blocks */
.ai-content pre {
  background: #0d0d1a;
  border: 1px solid var(--border2);
  border-radius: 10px;
  padding: 16px 18px;
  overflow-x: auto;
  margin: 14px 0;
  font-size: 13px;
  line-height: 1.6;
  position: relative;
}
.ai-content code {
  font-family: 'SF Mono', 'Fira Code', 'Cascadia Code', monospace;
  font-size: 13px;
  color: #c9d1d9;
}
.ai-content p code {
  background: rgba(230,18,42,.12);
  border: 1px solid rgba(230,18,42,.2);
  border-radius: 4px;
  padding: 1px 6px;
  font-size: 13px;
  color: var(--accent3);
}
.ai-content p { margin-bottom: 10px; }
.ai-content ul, .ai-content ol { margin: 8px 0 8px 20px; }
.ai-content li { margin-bottom: 4px; }
.ai-content h1,.ai-content h2,.ai-content h3 { margin: 16px 0 8px; font-weight: 600; }
.ai-content h1 { font-size: 20px; }
.ai-content h2 { font-size: 17px; }
.ai-content h3 { font-size: 15px; }
.ai-content blockquote {
  border-left: 3px solid var(--accent);
  padding-left: 16px;
  margin: 12px 0;
  color: var(--muted2);
  font-style: italic;
}
.ai-content strong { color: var(--accent3); font-weight: 500; }
.ai-content table { border-collapse: collapse; width: 100%; margin: 12px 0; font-size: 13.5px; }
.ai-content th { background: var(--surface); padding: 8px 12px; border: 1px solid var(--border2); text-align: left; font-weight: 500; color: var(--accent3); }
.ai-content td { padding: 8px 12px; border: 1px solid var(--border2); }
.code-artifact {
  margin: 14px 0;
  border: 1px solid var(--border2);
  border-radius: 10px;
  overflow: hidden;
  background: #0d0d1a;
}
.code-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 8px 10px;
  border-bottom: 1px solid var(--border2);
  background: rgba(255,255,255,.03);
}
.code-lang {
  font-size: 11px;
  color: var(--muted2);
  text-transform: uppercase;
  letter-spacing: .7px;
  font-weight: 700;
}
.code-actions { display: flex; gap: 7px; flex-wrap: wrap; }
.code-action {
  border: 1px solid var(--border2);
  background: rgba(255,255,255,.05);
  color: var(--text);
  border-radius: 7px;
  padding: 6px 9px;
  font-size: 12px;
  cursor: pointer;
}
.code-action:hover {
  border-color: rgba(230,18,42,.55);
  color: var(--accent2);
}
.code-artifact pre {
  border: 0;
  border-radius: 0;
  margin: 0;
}
.preview-panel {
  position: fixed;
  inset: 22px;
  z-index: 200;
  display: none;
  flex-direction: column;
  background: #09090f;
  border: 1px solid var(--border2);
  border-radius: 14px;
  box-shadow: 0 28px 110px rgba(0,0,0,.72);
}
.preview-panel.open { display: flex; }
.preview-head {
  height: 50px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 0 14px;
  border-bottom: 1px solid var(--border2);
}
.preview-title {
  font-size: 13px;
  font-weight: 750;
  color: var(--accent2);
}
.preview-head-actions { display: flex; gap: 8px; }
.preview-frame {
  flex: 1;
  width: 100%;
  border: 0;
  background: white;
  border-radius: 0 0 14px 14px;
}
@media (max-width: 768px) {
  .preview-panel { inset: 8px; }
  .preview-head { height: auto; padding: 10px; align-items: flex-start; flex-direction: column; }
}

/* Thinking indicator */
.thinking {
  display: flex;
  gap: 5px;
  align-items: center;
  padding: 6px 0;
}
.thinking span {
  width: 7px; height: 7px;
  background: var(--accent);
  border-radius: 50%;
  animation: blink 1.4s ease infinite;
}
.thinking span:nth-child(2) { animation-delay: .2s; }
.thinking span:nth-child(3) { animation-delay: .4s; }

@keyframes blink {
  0%, 80%, 100% { opacity: .25; transform: scale(.8); }
  40% { opacity: 1; transform: scale(1); }
}

/* ============================================================
   INPUT AREA
   ============================================================ */
.input-wrap {
  position: relative;
  z-index: 2;
  flex-shrink: 0;
  padding: 16px 24px calc(20px + env(safe-area-inset-bottom));
  background: linear-gradient(to top, var(--bg) 60%, transparent);
}

.input-inner {
  max-width: 740px;
  margin: 0 auto;
}

/* Model selector */
.model-bar {
  margin-bottom: 10px;
  position: relative;
}

.model-label {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0,0,0,0);
}

.model-select {
  position: absolute;
  opacity: 0;
  pointer-events: none;
  width: 1px;
  height: 1px;
}

.model-picker-btn {
  min-width: 184px;
  height: 34px;
  display: inline-flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: 999px;
  color: var(--text);
  padding: 0 12px;
  font-family: system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  transition: background .2s, border-color .2s;
}
.model-picker-btn:hover,
.model-picker-btn.open {
  border-color: rgba(230,18,42,.55);
  background: rgba(230,18,42,.08);
}
.model-picker-btn i { color: var(--muted2); font-size: 11px; }
.model-menu {
  position: absolute;
  left: 0;
  bottom: 42px;
  width: min(430px, calc(100vw - 32px));
  max-height: 360px;
  overflow-y: auto;
  padding: 8px;
  background: #101016;
  border: 1px solid var(--border2);
  border-radius: 12px;
  box-shadow: 0 24px 70px rgba(0,0,0,.55);
  display: none;
  z-index: 20;
}
.model-menu.open { display: block; }
.model-group-label {
  padding: 8px 10px 5px;
  font-size: 10.5px;
  letter-spacing: .7px;
  text-transform: uppercase;
  color: var(--muted);
}
.model-option {
  width: 100%;
  display: grid;
  grid-template-columns: 1fr auto;
  gap: 12px;
  align-items: center;
  padding: 10px;
  border: none;
  border-radius: 8px;
  background: transparent;
  color: var(--text);
  text-align: left;
  cursor: pointer;
  font-family: system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
}
.model-option:hover {
  background: rgba(255,255,255,.05);
}
.model-option.active {
  background: rgba(230,18,42,.12);
}
.model-option-name {
  display: block;
  font-size: 13.5px;
  font-weight: 650;
}
.model-option-desc {
  display: block;
  margin-top: 2px;
  font-size: 11.5px;
  color: var(--muted2);
}
.model-option-check {
  color: var(--accent2);
  font-size: 12px;
  opacity: 0;
}
.model-option.active .model-option-check {
  opacity: 1;
}

/* Input box */
.input-box {
  background: var(--card);
  border: 1px solid var(--border2);
  border-radius: var(--radius);
  overflow: hidden;
  transition: border-color .2s, box-shadow .2s;
  box-shadow: 0 4px 24px rgba(0,0,0,.3);
}
.input-box:focus-within {
  border-color: var(--accent);
  box-shadow: 0 4px 24px rgba(230,18,42,.15);
}

#msg-input {
  width: 100%;
  min-height: 56px;
  max-height: 200px;
  padding: 16px 18px 8px;
  background: transparent;
  border: none;
  color: var(--text);
  font-size: 15px;
  font-family: system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
  line-height: 1.6;
  resize: none;
  outline: none;
  overflow-y: auto;
}
#msg-input::placeholder { color: var(--muted); }

.input-actions {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 12px 12px;
  gap: 8px;
}

.quick-btns {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}

.quick-btn {
  background: rgba(255,255,255,.04);
  border: 1px solid var(--border);
  border-radius: 99px;
  color: var(--muted2);
  font-size: 12px;
  font-family: system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
  padding: 5px 12px;
  cursor: pointer;
  transition: all .2s;
  white-space: nowrap;
}
.quick-btn i { margin-right: 6px; color: var(--accent2); }
.quick-btn:hover {
  background: rgba(230,18,42,.12);
  border-color: var(--accent);
  color: var(--accent2);
}

.send-btn {
  width: 38px; height: 38px;
  background: linear-gradient(135deg, var(--accent), var(--accent2));
  border: none;
  border-radius: var(--radius-sm);
  color: #fff;
  cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
  transition: opacity .2s, transform .15s;
}
.send-btn:hover { opacity: .9; transform: scale(1.05); }
.send-btn:disabled { opacity: .4; cursor: not-allowed; transform: none; }
.send-btn svg { width: 16px; height: 16px; }

.input-tools {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-shrink: 0;
}
.voice-btn,
.voice-call-btn {
  width: 38px;
  height: 38px;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  background: rgba(255,255,255,.04);
  color: var(--muted2);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background .2s, border-color .2s, color .2s, transform .15s;
}
.voice-btn:hover {
  border-color: var(--accent);
  color: var(--accent2);
  transform: scale(1.05);
}
.voice-call-btn:hover {
  border-color: var(--accent);
  color: var(--accent2);
  transform: scale(1.05);
}
.voice-btn.recording {
  background: rgba(230,18,42,.18);
  border-color: var(--accent);
  color: var(--accent2);
}
.voice-btn.transcribing {
  pointer-events: none;
  opacity: .75;
}
.voice-call-btn.active,
.voice-call-btn.listening,
.voice-call-btn.speaking {
  background: rgba(230,18,42,.18);
  border-color: var(--accent);
  color: var(--accent2);
}
.voice-call-btn.thinking {
  background: rgba(255,255,255,.08);
  color: var(--warn);
}

.input-hint {
  text-align: center;
  font-size: 11.5px;
  color: var(--muted);
  margin-top: 10px;
}

/* ============================================================
   MOBILE TOGGLE
   ============================================================ */
.sidebar-toggle {
  display: none;
  position: fixed;
  top: 14px; left: 14px;
  z-index: 100;
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  padding: 8px;
  cursor: pointer;
  color: var(--muted2);
}

@media (max-width: 768px) {
  .sidebar {
    position: fixed;
    transform: translateX(-100%);
    z-index: 50;
  }
  .sidebar.open { transform: translateX(0); }
  .main { width: 100%; }
  .sidebar-toggle { display: flex; }
  .starters { grid-template-columns: 1fr; }
  .messages-inner { padding: 44px 14px 24px; }
  .welcome { padding: 18px 4px 8px; }
  .welcome-logo { width: min(220px, 68vw); }
  .project-links { gap: 8px; }
  .input-wrap { padding: 10px 14px calc(16px + env(safe-area-inset-bottom)); }
  .model-picker-btn { width: 100%; }
  .model-menu { width: 100%; }
}

@media (max-height: 760px) {
  .messages-inner { padding-top: 18px; padding-bottom: 18px; }
  .welcome { padding-top: 12px; }
  .welcome-logo { width: min(190px, 58vw); margin-bottom: 8px; }
  .welcome h1 { font-size: 26px; }
  .welcome-sub { margin-bottom: 8px; }
  .welcome-manifesto { display: none; }
  .project-links { margin-bottom: 10px; }
  .starter-card { padding: 12px; }
  .starter-title { margin-bottom: 2px; }
  .starter-desc { line-height: 1.35; }
}
</style>
</head>
<body>

<!-- Sidebar Toggle (mobile) -->
<button class="sidebar-toggle" id="sidebar-toggle" aria-label="Menu" onclick="toggleSidebar()">
  <svg viewBox="0 0 20 20" fill="currentColor" width="18" height="18">
    <path fill-rule="evenodd" d="M3 5h14a1 1 0 110 2H3a1 1 0 110-2zm0 4h14a1 1 0 110 2H3a1 1 0 110-2zm0 4h14a1 1 0 110 2H3a1 1 0 110-2z" clip-rule="evenodd"/>
  </svg>
</button>

<!-- ===== SIDEBAR ===== -->
<aside class="sidebar" id="sidebar">

  <div class="sidebar-top">
    <div class="brand">
      <img class="brand-logo" src="libre-claude-red-black-dark.png" alt="Libre Claude">
    </div>
    <button class="new-chat-btn" onclick="newChat()">
      <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" clip-rule="evenodd"/></svg>
      <?= htmlspecialchars($t('new_chat')) ?>
    </button>
  </div>

  <?php if ($user): ?>
  <div class="sidebar-search">
    <input type="text" id="conv-search" placeholder="<?= htmlspecialchars($t('search')) ?>" oninput="filterConvs(this.value)">
  </div>

  <div class="conv-list" id="conv-list">
    <div class="workspace-menu">
      <div class="workspace-head">
        <div class="conv-section-label"><?= htmlspecialchars($t('my_workspaces')) ?></div>
        <a class="workspace-add" href="workspace.php#create-workspace" title="<?= htmlspecialchars($t('new_workspace')) ?>" aria-label="<?= htmlspecialchars($t('new_workspace')) ?>"><i class="fa-solid fa-plus"></i></a>
      </div>
      <?php if ($workspaceGithub && !empty($workspaceGithub['owner']) && !empty($workspaceGithub['repo'])): ?>
      <a class="workspace-item" href="workspace.php">
        <i class="fa-solid fa-folder"></i>
        <span class="workspace-copy">
          <span class="workspace-title"><?= htmlspecialchars($workspaceGithub['owner'] . '/' . $workspaceGithub['repo']) ?></span>
          <span class="workspace-meta"><?= htmlspecialchars($t('github_branch')) ?> <?= htmlspecialchars($workspaceGithub['branch'] ?: 'main') ?> · <?= (int)$workspaceFileCount ?> <?= htmlspecialchars($t('saved_blocks_short')) ?></span>
        </span>
      </a>
      <?php elseif ($workspaceGithub && !empty($workspaceGithub['token'])): ?>
      <a class="workspace-item" href="workspace.php">
        <i class="fa-solid fa-folder-open"></i>
        <span class="workspace-copy">
          <span class="workspace-title"><?= htmlspecialchars($t('github_repo_select')) ?></span>
          <span class="workspace-meta"><?= htmlspecialchars($t('github_oauth_success')) ?></span>
        </span>
      </a>
      <?php else: ?>
      <a class="workspace-item" href="workspace.php">
        <i class="fa-regular fa-folder"></i>
        <span class="workspace-copy">
          <span class="workspace-title"><?= htmlspecialchars($t('no_workspace')) ?></span>
          <span class="workspace-meta"><?= htmlspecialchars($t('workspace_empty_hint')) ?></span>
        </span>
      </a>
      <?php endif; ?>
    </div>

    <?php if ($recentConvs): ?>
    <div class="conv-section-label"><?= htmlspecialchars($t('recent')) ?></div>
    <?php foreach ($recentConvs as $c): ?>
    <div class="conv-item" id="conv-<?= (int)$c['id'] ?>" data-id="<?= (int)$c['id'] ?>" onclick="loadConversation(<?= (int)$c['id'] ?>)">
      <span class="conv-title"><?= htmlspecialchars($c['title'] ?: $t('conversation')) ?></span>
      <button class="conv-del" title="<?= htmlspecialchars($t('delete')) ?>" onclick="event.stopPropagation(); deleteConversation(<?= (int)$c['id'] ?>)">×</button>
    </div>
    <?php endforeach; ?>
    <?php else: ?>
    <div data-empty style="padding:16px 8px;font-size:13px;color:var(--muted);text-align:center"><?= htmlspecialchars($t('no_conversation')) ?></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="sidebar-footer">
    <?php if ($user && ($user['role'] ?? '') === 'admin'): ?>
    <a href="admin_keys.php" class="nav-link">
      <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 8a6 6 0 01-7.743 5.743L7 17H4v-3l3.257-3.257A6 6 0 1118 8zm-6-2a1 1 0 100 2 1 1 0 000-2z" clip-rule="evenodd"/></svg>
      <?= htmlspecialchars($t('claude_keys')) ?>
    </a>
    <?php endif; ?>
    <?php if ($user): ?>
    <a href="index.php" class="nav-link">
      <i class="fa-solid fa-message"></i>
      <?= htmlspecialchars($t('chat_libre')) ?>
    </a>
    <a href="workspace.php" class="nav-link">
      <i class="fa-solid fa-code"></i>
      <?= htmlspecialchars($t('libre_coder')) ?>
    </a>
    <?php endif; ?>
    <?php if ($user): ?>
    <a href="api_tokens.php" class="nav-link">
      <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 4a2 2 0 012-2h8a2 2 0 012 2v12a2 2 0 01-2 2H6a2 2 0 01-2-2V4zm3 2a1 1 0 000 2h6a1 1 0 100-2H7zm0 4a1 1 0 100 2h6a1 1 0 100-2H7zm0 4a1 1 0 100 2h3a1 1 0 100-2H7z" clip-rule="evenodd"/></svg>
      <?= htmlspecialchars($t('api_keys')) ?>
    </a>
    <?php endif; ?>
    <a href="settings.php" class="nav-link">
      <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M11.49 3.17c-.38-1.56-2.6-1.56-2.98 0a1.532 1.532 0 01-2.286.948c-1.372-.836-2.942.734-2.106 2.106.54.886.061 2.042-.947 2.287-1.561.379-1.561 2.6 0 2.978a1.532 1.532 0 01.947 2.287c-.836 1.372.734 2.942 2.106 2.106a1.532 1.532 0 012.287.947c.379 1.561 2.6 1.561 2.978 0a1.533 1.533 0 012.287-.947c1.372.836 2.942-.734 2.106-2.106a1.533 1.533 0 01.947-2.287c1.561-.379 1.561-2.6 0-2.978a1.532 1.532 0 01-.947-2.287c.836-1.372-.734-2.942-2.106-2.106a1.532 1.532 0 01-2.287-.947zM10 13a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd"/></svg>
      <?= htmlspecialchars($t('settings')) ?>
    </a>

    <?php if ($user): ?>
    <div class="user-pill">
      <div class="user-avatar"><?= strtoupper(mb_substr($user['username'], 0, 2)) ?></div>
      <span class="user-name"><?= htmlspecialchars($user['username']) ?></span>
      <a href="logout.php" class="logout-link" title="<?= htmlspecialchars($t('logout')) ?>" aria-label="<?= htmlspecialchars($t('logout')) ?>"><i class="fa-solid fa-right-from-bracket"></i></a>
    </div>
    <?php else: ?>
    <a href="login.php" class="nav-link" style="background:rgba(230,18,42,.1);border:1px solid rgba(230,18,42,.2);justify-content:center;color:var(--accent2)">
      <?= htmlspecialchars($t('login')) ?>
    </a>
    <a href="register.php" class="nav-link" style="justify-content:center;margin-top:6px">
      <?= htmlspecialchars($t('register')) ?>
    </a>
    <?php endif; ?>
  </div>
</aside>

<!-- ===== MAIN ===== -->
<main class="main">

  <!-- Messages -->
  <div class="messages-wrap" id="messages-wrap">
    <div class="messages-inner" id="messages-inner">

      <!-- Welcome screen -->
      <div id="welcome" class="welcome">
        <img class="welcome-logo" src="libre-claude-red-black-dark.png" alt="Libre Claude">
        <h1><?= htmlspecialchars($t('hello')) ?><?= $user ? ', ' . htmlspecialchars($user['username']) : '' ?></h1>
        <p class="welcome-sub"><?= htmlspecialchars($t('welcome_sub')) ?></p>
        <div class="welcome-manifesto">
          <strong><?= htmlspecialchars($t('manifest_title')) ?></strong>
          <span><?= htmlspecialchars($t('manifest_text')) ?></span>
        </div>
        <div class="project-links">
          <a class="project-link" href="<?= htmlspecialchars($githubRepoUrl) ?>" target="_blank" rel="noopener">
            <i class="fa-brands fa-github"></i>
            <span><?= htmlspecialchars($t('github_source')) ?></span>
          </a>
          <a class="project-link release-pill" href="<?= htmlspecialchars($githubRepoUrl) ?>/releases" target="_blank" rel="noopener">
            <i class="fa-solid fa-code-branch"></i>
            <span><?= htmlspecialchars($t('latest_release')) ?></span>
            <span class="release-version" id="release-version"><?= htmlspecialchars($t('release_loading')) ?></span>
          </a>
        </div>

        <div class="starters">
          <div class="starter-card" onclick="setPrompt(<?= $jsText('concept_prompt') ?>)">
            <i class="starter-icon fa-solid fa-diagram-project"></i>
            <div class="starter-title"><?= htmlspecialchars($t('concept_title')) ?></div>
            <div class="starter-desc"><?= htmlspecialchars($t('concept_desc')) ?></div>
          </div>
          <div class="starter-card" onclick="window.location.href='workspace.php'">
            <i class="starter-icon fa-solid fa-code"></i>
            <div class="starter-title"><?= htmlspecialchars($t('code_title')) ?></div>
            <div class="starter-desc"><?= htmlspecialchars($t('code_desc')) ?></div>
          </div>
          <div class="starter-card" onclick="setPrompt(<?= $jsText('write_prompt') ?>)">
            <i class="starter-icon fa-solid fa-pen-nib"></i>
            <div class="starter-title"><?= htmlspecialchars($t('write_title')) ?></div>
            <div class="starter-desc"><?= htmlspecialchars($t('write_desc')) ?></div>
          </div>
          <div class="starter-card" onclick="setPrompt(<?= $jsText('plan_prompt') ?>)">
            <i class="starter-icon fa-solid fa-list-check"></i>
            <div class="starter-title"><?= htmlspecialchars($t('plan_title')) ?></div>
            <div class="starter-desc"><?= htmlspecialchars($t('plan_desc')) ?></div>
          </div>
        </div>
      </div>

      <!-- Messages list -->
      <div id="messages-list"></div>

    </div>
  </div>

  <!-- Input area -->
  <div class="input-wrap">
    <div class="input-inner">

      <!-- Model selector -->
      <div class="model-bar">
        <span class="model-label"><?= htmlspecialchars($t('model')) ?></span>
        <select class="model-select" id="model-select">
          <?php foreach (MISTRAL_MODELS as $cat => $models): ?>
          <optgroup label="<?= ucfirst($cat) ?>">
            <?php foreach ($models as $m): ?>
            <option value="<?= htmlspecialchars($m['id']) ?>" <?= $m['id'] === $defaultModel ? 'selected' : '' ?>>
              <?= htmlspecialchars($m['name']) ?> — <?= htmlspecialchars($m['desc']) ?>
            </option>
            <?php endforeach; ?>
          </optgroup>
          <?php endforeach; ?>
        </select>
        <button type="button" class="model-picker-btn" id="model-picker-btn" onclick="toggleModelMenu()" aria-haspopup="listbox" aria-expanded="false">
          <span id="model-picker-label"><?= htmlspecialchars($modelNames[$defaultModel] ?? 'Claude Opus 4.5') ?></span>
          <i class="fa-solid fa-chevron-down"></i>
        </button>
        <div class="model-menu" id="model-menu" role="listbox" aria-label="<?= htmlspecialchars($t('choose_model')) ?>">
          <?php foreach (MISTRAL_MODELS as $cat => $models): ?>
            <div class="model-group-label"><?= htmlspecialchars(ucfirst($cat)) ?></div>
            <?php foreach ($models as $m): ?>
            <button
              type="button"
              class="model-option <?= $m['id'] === $defaultModel ? 'active' : '' ?>"
              data-model="<?= htmlspecialchars($m['id']) ?>"
              onclick="selectModel(this)"
              title="<?= htmlspecialchars(ucfirst($cat) . ' - ' . $m['name'] . ' - ' . $m['desc']) ?>"
              role="option"
              aria-selected="<?= $m['id'] === $defaultModel ? 'true' : 'false' ?>"
            >
              <span>
                <span class="model-option-name"><?= htmlspecialchars($m['name']) ?></span>
                <span class="model-option-desc"><?= htmlspecialchars($m['desc']) ?></span>
              </span>
              <i class="model-option-check fa-solid fa-check"></i>
            </button>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Textarea + actions -->
      <div class="input-box">
        <textarea
          id="msg-input"
          placeholder="<?= htmlspecialchars($t('placeholder')) ?>"
          rows="1"
          oninput="autoResize(this)"
          onkeydown="handleKey(event)"
        ></textarea>
        <div class="input-actions">
          <div class="quick-btns">
            <button class="quick-btn" onclick="setPrompt(<?= $jsText('quick_explain_prompt') ?>)"><i class="fa-solid fa-lightbulb"></i><?= htmlspecialchars($t('explain')) ?></button>
            <button class="quick-btn" onclick="window.location.href='workspace.php'"><i class="fa-solid fa-code"></i><?= htmlspecialchars($t('code')) ?></button>
            <button class="quick-btn" onclick="setPrompt(<?= $jsText('quick_analyze_prompt') ?>)"><i class="fa-solid fa-magnifying-glass-chart"></i><?= htmlspecialchars($t('analyze')) ?></button>
            <button class="quick-btn" onclick="setPrompt(<?= $jsText('quick_plan_prompt') ?>)"><i class="fa-solid fa-list-check"></i><?= htmlspecialchars($t('plan')) ?></button>
          </div>
          <div class="input-tools">
            <button class="voice-call-btn" id="voice-call-btn" onclick="toggleVoiceConversation()" type="button" title="<?= htmlspecialchars($t('voice_chat_title')) ?>" aria-label="<?= htmlspecialchars($t('voice_chat_title')) ?>">
              <i class="fa-solid fa-phone"></i>
            </button>
            <button class="voice-btn" id="voice-btn" onclick="toggleDictation()" type="button" title="<?= htmlspecialchars($t('voice_title')) ?>" aria-label="<?= htmlspecialchars($t('voice_title')) ?>">
              <i class="fa-solid fa-microphone"></i>
            </button>
            <button class="send-btn" id="send-btn" onclick="sendMessage()" disabled title="<?= htmlspecialchars($t('send')) ?>">
              <svg viewBox="0 0 20 20" fill="currentColor">
                <path d="M10.894 2.553a1 1 0 00-1.788 0l-7 14a1 1 0 001.169 1.409l5-1.429A1 1 0 009 15.571V11a1 1 0 112 0v4.571a1 1 0 00.725.962l5 1.428a1 1 0 001.17-1.408l-7-14z"/>
              </svg>
            </button>
          </div>
        </div>
      </div>
      <div class="input-hint"><?= htmlspecialchars($t('hint')) ?></div>
    </div>
  </div>
</main>

<div class="preview-panel" id="code-preview-panel">
  <div class="preview-head">
    <div class="preview-title"><?= htmlspecialchars($t('preview_title')) ?></div>
    <div class="preview-head-actions">
      <a class="code-action" href="workspace.php"><?= htmlspecialchars($t('workspace')) ?></a>
      <button class="code-action" type="button" onclick="closeCodePreview()"><?= htmlspecialchars($t('close')) ?></button>
    </div>
  </div>
  <iframe class="preview-frame" id="code-preview-frame" sandbox="allow-scripts allow-forms allow-modals"></iframe>
</div>

<script>
// ============================================================
// STATE
// ============================================================
let currentConvId = null;
let isBusy = false;
let isLoggedIn = <?= $user ? 'true' : 'false' ?>;
let mediaRecorder = null;
let voiceChunks = [];
let isRecording = false;
let isTranscribing = false;
let voiceConversationActive = false;
let voiceConversationRecorder = null;
let voiceConversationChunks = [];
let voiceConversationStream = null;
let voiceConversationAudio = null;
let voiceConversationMonitor = null;
let voiceConversationState = 'idle';
let voiceConversationManualStop = false;
const modelNames = <?= json_encode(array_reduce(MISTRAL_MODELS, function($carry, $models) {
    foreach ($models as $model) {
        $carry[$model['id']] = $model['name'];
    }
    return $carry;
}, []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const uiLanguage = <?= json_encode($lang) ?>;
const uiText = <?= json_encode([
    'hint' => $t('hint'),
    'recent' => $t('recent'),
    'voice_login' => $t('voice_login'),
    'voice_start' => $t('voice_start'),
    'voice_transcribing' => $t('voice_transcribing'),
    'voice_done' => $t('voice_done'),
    'delete_conversation_confirm' => $t('delete_conversation_confirm'),
    'delete' => $t('delete'),
    'voice_title' => $t('voice_title'),
    'voice_stop' => $t('voice_stop'),
    'voice_browser_error' => $t('voice_browser_error'),
    'voice_micro_error' => $t('voice_micro_error'),
    'transcription_failed' => $t('transcription_failed'),
    'voice_chat_title' => $t('voice_chat_title'),
    'voice_chat_start' => $t('voice_chat_start'),
    'voice_chat_listening' => $t('voice_chat_listening'),
    'voice_chat_thinking' => $t('voice_chat_thinking'),
    'voice_chat_speaking' => $t('voice_chat_speaking'),
    'voice_chat_stop' => $t('voice_chat_stop'),
    'voice_chat_send' => $t('voice_chat_send'),
    'voice_chat_error' => $t('voice_chat_error'),
    'voice_chat_empty' => $t('voice_chat_empty'),
    'voice_chat_browser_fallback' => $t('voice_chat_browser_fallback'),
    'unknown_error' => $t('unknown_error'),
    'connection_error' => $t('connection_error'),
    'code_copy' => $t('code_copy'),
    'code_preview' => $t('code_preview'),
    'code_workspace' => $t('code_workspace'),
    'code_copied' => $t('code_copied'),
    'preview_unsupported' => $t('preview_unsupported'),
    'workspace_saved' => $t('workspace_saved'),
    'workspace_save_error' => $t('workspace_save_error'),
    'release_unavailable' => $t('release_unavailable'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const githubReleaseApi = <?= json_encode($githubReleaseApi) ?>;

// ============================================================
// AUTO RESIZE TEXTAREA
// ============================================================
function autoResize(el) {
  el.style.height = 'auto';
  el.style.height = Math.min(el.scrollHeight, 200) + 'px';
  document.getElementById('send-btn').disabled = el.value.trim() === '';
}

// ============================================================
// SIDEBAR
// ============================================================
function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
}

document.addEventListener('click', e => {
  const sb = document.getElementById('sidebar');
  const toggle = document.getElementById('sidebar-toggle');
  if (window.innerWidth <= 768 && sb.classList.contains('open') &&
      !sb.contains(e.target) && !toggle.contains(e.target)) {
    sb.classList.remove('open');
  }
});

// ============================================================
// FILTER CONVERSATIONS
// ============================================================
function filterConvs(q) {
  const items = document.querySelectorAll('.conv-item');
  const lq = q.toLowerCase();
  items.forEach(el => {
    const t = el.querySelector('.conv-title').textContent.toLowerCase();
    el.style.display = (!q || t.includes(lq)) ? '' : 'none';
  });
}

// ============================================================
// SET PROMPT
// ============================================================
function setPrompt(text) {
  const el = document.getElementById('msg-input');
  el.value = text;
  el.focus();
  autoResize(el);
  el.setSelectionRange(text.length, text.length);
}

// ============================================================
// NEW CHAT
// ============================================================
function newChat() {
  currentConvId = null;
  document.getElementById('messages-list').innerHTML = '';
  document.getElementById('welcome').style.display = 'flex';
  document.getElementById('msg-input').value = '';
  document.getElementById('send-btn').disabled = true;
  document.querySelectorAll('.conv-item').forEach(el => el.classList.remove('active'));
  if (window.innerWidth <= 768) document.getElementById('sidebar').classList.remove('open');
}

// ============================================================
// LOAD CONVERSATION
// ============================================================
async function loadConversation(id) {
  if (isBusy) return;

  currentConvId = id;
  document.getElementById('welcome').style.display = 'none';
  document.getElementById('messages-list').innerHTML = '';

  document.querySelectorAll('.conv-item').forEach(el => {
    el.classList.toggle('active', el.dataset.id == id);
  });

  if (window.innerWidth <= 768) document.getElementById('sidebar').classList.remove('open');

  try {
    const resp = await fetch(`conversations.php?action=messages&id=${id}`);
    const data = await resp.json();

    if (data.success && data.messages) {
      data.messages.forEach(m => {
        if (m.role === 'user') appendUserMsg(m.content);
        else if (m.role === 'assistant') appendAiMsg(m.content, m.model_used || '');
      });
      scrollBottom();
    }
  } catch(e) {
    console.error('Load conv error:', e);
  }
}

// ============================================================
// DELETE CONVERSATION
// ============================================================
async function deleteConversation(id) {
  if (!confirm(uiText.delete_conversation_confirm)) return;

  try {
    const resp = await fetch('conversations.php?action=delete', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id }),
    });
    const data = await resp.json();
    if (data.success) {
      const el = document.getElementById(`conv-${id}`);
      if (el) el.remove();
      if (currentConvId === id) newChat();
    }
  } catch(e) {}
}

// ============================================================
// SEND MESSAGE
// ============================================================
function handleKey(e) {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    sendMessage();
  }
}

function toggleModelMenu(force = null) {
  const menu = document.getElementById('model-menu');
  const btn = document.getElementById('model-picker-btn');
  const open = force === null ? !menu.classList.contains('open') : force;
  menu.classList.toggle('open', open);
  btn.classList.toggle('open', open);
  btn.setAttribute('aria-expanded', open ? 'true' : 'false');
}

function selectModel(button) {
  const select = document.getElementById('model-select');
  select.value = button.dataset.model;
  document.querySelectorAll('.model-option').forEach(tile => {
    tile.classList.remove('active');
    tile.setAttribute('aria-selected', 'false');
  });
  button.classList.add('active');
  button.setAttribute('aria-selected', 'true');
  document.getElementById('model-picker-label').textContent = displayModelName(button.dataset.model);
  toggleModelMenu(false);
}

function displayModelName(model) {
  return modelNames[model] || model || 'Libre Claude';
}

document.addEventListener('click', event => {
  const bar = document.querySelector('.model-bar');
  if (bar && !bar.contains(event.target)) toggleModelMenu(false);
});

function setInputHint(text) {
  const hint = document.querySelector('.input-hint');
  if (hint) hint.textContent = text;
}

async function toggleDictation() {
  if (isTranscribing) return;
  if (voiceConversationActive) {
    stopVoiceConversation();
    return;
  }
  if (!isLoggedIn) {
    alert(uiText.voice_login);
    return;
  }

  if (isRecording && mediaRecorder) {
    mediaRecorder.stop();
    return;
  }

  if (!navigator.mediaDevices || !window.MediaRecorder) {
    alert(uiText.voice_browser_error);
    return;
  }

  try {
    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    const mimeType = MediaRecorder.isTypeSupported('audio/webm;codecs=opus')
      ? 'audio/webm;codecs=opus'
      : 'audio/webm';

    voiceChunks = [];
    mediaRecorder = new MediaRecorder(stream, { mimeType });

    mediaRecorder.ondataavailable = event => {
      if (event.data && event.data.size > 0) voiceChunks.push(event.data);
    };

    mediaRecorder.onstop = async () => {
      stream.getTracks().forEach(track => track.stop());
      isRecording = false;
      updateVoiceButton('transcribing');

      const blob = new Blob(voiceChunks, { type: mimeType });
      await transcribeAudio(blob);
    };

    mediaRecorder.start();
    isRecording = true;
    updateVoiceButton('recording');
    setInputHint(uiText.voice_start);
  } catch (e) {
    console.error('Micro error:', e);
    alert(uiText.voice_micro_error);
  }
}

function updateVoiceButton(state = 'idle') {
  const btn = document.getElementById('voice-btn');
  if (!btn) return;

  btn.classList.toggle('recording', state === 'recording');
  btn.classList.toggle('transcribing', state === 'transcribing');

  if (state === 'recording') {
    btn.innerHTML = '<i class="fa-solid fa-stop"></i>';
    btn.title = uiText.voice_stop;
  } else if (state === 'transcribing') {
    btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i>';
    btn.title = uiText.voice_transcribing;
  } else {
    btn.innerHTML = '<i class="fa-solid fa-microphone"></i>';
    btn.title = uiText.voice_title;
  }
}

async function transcribeAudio(blob) {
  isTranscribing = true;
  setInputHint(uiText.voice_transcribing);

  try {
    const text = await requestTranscription(blob);
    insertDictation(text || '');
    setInputHint(uiText.voice_done);
  } catch (e) {
    console.error('Transcription error:', e);
    alert(e.message || uiText.transcription_failed);
    setInputHint(uiText.hint);
  } finally {
    isTranscribing = false;
    updateVoiceButton('idle');
  }
}

async function requestTranscription(blob) {
  const form = new FormData();
  form.append('audio', blob, 'dictee.webm');
  form.append('language', uiLanguage);

  const resp = await fetch('transcribe.php', {
    method: 'POST',
    body: form,
  });
  const data = await resp.json();
  if (!data.success) throw new Error(data.error || 'Transcription impossible');
  return (data.text || '').trim();
}

function insertDictation(text) {
  const input = document.getElementById('msg-input');
  const cleaned = text.trim();
  if (!cleaned) return;

  const prefix = input.value.trim() ? input.value.trimEnd() + ' ' : '';
  input.value = prefix + cleaned;
  autoResize(input);
  input.focus();
  document.getElementById('send-btn').disabled = input.value.trim() === '';
}

async function toggleVoiceConversation() {
  if (voiceConversationActive) {
    if (voiceConversationState === 'listening' && voiceConversationRecorder && voiceConversationRecorder.state === 'recording') {
      voiceConversationManualStop = true;
      updateVoiceConversationButton('thinking');
      setInputHint(uiText.voice_chat_thinking);
      try { voiceConversationRecorder.stop(); } catch (e) {}
      return;
    }
    stopVoiceConversation();
    return;
  }

  if (!isLoggedIn) {
    alert(uiText.voice_login);
    return;
  }
  if (!navigator.mediaDevices || !window.MediaRecorder) {
    alert(uiText.voice_browser_error);
    return;
  }

  voiceConversationActive = true;
  updateVoiceConversationButton('listening');
  setInputHint(uiText.voice_chat_start);
  await startVoiceConversationTurn();
}

function stopVoiceConversation() {
  voiceConversationActive = false;
  if (voiceConversationMonitor) {
    cancelAnimationFrame(voiceConversationMonitor);
    voiceConversationMonitor = null;
  }
  if (voiceConversationRecorder && voiceConversationRecorder.state !== 'inactive') {
    try { voiceConversationRecorder.stop(); } catch (e) {}
  }
  if (voiceConversationStream) {
    voiceConversationStream.getTracks().forEach(track => track.stop());
    voiceConversationStream = null;
  }
  if (voiceConversationAudio) {
    voiceConversationAudio.pause();
    voiceConversationAudio = null;
  }
  updateVoiceConversationButton('idle');
  setInputHint(uiText.hint);
}

function updateVoiceConversationButton(state = 'idle') {
  const btn = document.getElementById('voice-call-btn');
  if (!btn) return;

  voiceConversationState = state;
  btn.classList.toggle('active', state !== 'idle');
  btn.classList.toggle('listening', state === 'listening');
  btn.classList.toggle('thinking', state === 'thinking');
  btn.classList.toggle('speaking', state === 'speaking');

  if (state === 'listening') {
    btn.innerHTML = '<i class="fa-solid fa-ear-listen"></i>';
    btn.title = uiText.voice_chat_send;
  } else if (state === 'thinking') {
    btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i>';
    btn.title = uiText.voice_chat_thinking;
  } else if (state === 'speaking') {
    btn.innerHTML = '<i class="fa-solid fa-volume-high"></i>';
    btn.title = uiText.voice_chat_stop;
  } else {
    voiceConversationState = 'idle';
    btn.innerHTML = '<i class="fa-solid fa-phone"></i>';
    btn.title = uiText.voice_chat_title;
  }
}

async function startVoiceConversationTurn() {
  if (!voiceConversationActive || isBusy) return;

  try {
    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    if (!voiceConversationActive) {
      stream.getTracks().forEach(track => track.stop());
      return;
    }

    const mimeType = MediaRecorder.isTypeSupported('audio/webm;codecs=opus')
      ? 'audio/webm;codecs=opus'
      : 'audio/webm';
    voiceConversationStream = stream;
    voiceConversationChunks = [];
    voiceConversationManualStop = false;
    voiceConversationRecorder = new MediaRecorder(stream, { mimeType });

    let speechSeen = false;
    let silenceSince = null;
    const startedAt = Date.now();
    const audioContext = new (window.AudioContext || window.webkitAudioContext)();
    const source = audioContext.createMediaStreamSource(stream);
    const analyser = audioContext.createAnalyser();
    analyser.fftSize = 1024;
    const buffer = new Uint8Array(analyser.fftSize);
    source.connect(analyser);

    voiceConversationRecorder.ondataavailable = event => {
      if (event.data && event.data.size > 0) voiceConversationChunks.push(event.data);
    };

    voiceConversationRecorder.onstop = async () => {
      if (voiceConversationMonitor) {
        cancelAnimationFrame(voiceConversationMonitor);
        voiceConversationMonitor = null;
      }
      stream.getTracks().forEach(track => track.stop());
      voiceConversationStream = null;
      try { await audioContext.close(); } catch (e) {}

      if (!voiceConversationActive) return;
      const blob = new Blob(voiceConversationChunks, { type: mimeType });
      if ((!speechSeen && !voiceConversationManualStop) || blob.size < 1500) {
        setInputHint(uiText.voice_chat_empty);
        setTimeout(() => startVoiceConversationTurn(), 500);
        return;
      }
      await processVoiceConversationBlob(blob);
    };

    voiceConversationRecorder.start();
    updateVoiceConversationButton('listening');
    setInputHint(uiText.voice_chat_listening);

    const monitorSilence = () => {
      if (!voiceConversationActive || !voiceConversationRecorder || voiceConversationRecorder.state !== 'recording') return;
      analyser.getByteTimeDomainData(buffer);
      let sum = 0;
      for (let i = 0; i < buffer.length; i++) {
        const value = (buffer[i] - 128) / 128;
        sum += value * value;
      }
      const rms = Math.sqrt(sum / buffer.length);
      const now = Date.now();
      const isSpeech = rms > 0.018;

      if (isSpeech) {
        speechSeen = true;
        silenceSince = null;
      } else if (speechSeen) {
        silenceSince = silenceSince || now;
      }

      const enoughSilence = speechSeen && silenceSince && now - silenceSince > 1200 && now - startedAt > 1200;
      const tooLong = now - startedAt > 20000;
      const noSpeechTimeout = !speechSeen && now - startedAt > 9000;
      if (enoughSilence || tooLong || noSpeechTimeout) {
        try { voiceConversationRecorder.stop(); } catch (e) {}
        return;
      }

      voiceConversationMonitor = requestAnimationFrame(monitorSilence);
    };
    voiceConversationMonitor = requestAnimationFrame(monitorSilence);
  } catch (e) {
    console.error('Voice conversation micro error:', e);
    alert(uiText.voice_micro_error);
    stopVoiceConversation();
  }
}

async function processVoiceConversationBlob(blob) {
  try {
    updateVoiceConversationButton('thinking');
    setInputHint(uiText.voice_transcribing);
    const text = await requestTranscription(blob);
    if (!text) {
      setInputHint(uiText.voice_chat_empty);
      return;
    }

    setInputHint(uiText.voice_chat_thinking);
    const data = await sendMessage(text, { fromVoice: true });
    if (voiceConversationActive && data && data.success && data.content) {
      updateVoiceConversationButton('speaking');
      setInputHint(uiText.voice_chat_speaking);
      await speakAssistantText(data.content);
    }
  } catch (e) {
    console.error('Voice conversation error:', e);
    appendAiMsg((e.message || uiText.voice_chat_error), document.getElementById('model-select').value, true);
  } finally {
    if (voiceConversationActive) {
      updateVoiceConversationButton('listening');
      setInputHint(uiText.voice_chat_listening);
      setTimeout(() => startVoiceConversationTurn(), 400);
    }
  }
}

async function speakAssistantText(text) {
  try {
    const resp = await fetch('speak.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ text, format: 'mp3' }),
    });
    const data = await resp.json();
    if (!data.success) throw new Error(data.error || uiText.voice_chat_error);

    return new Promise((resolve, reject) => {
      const audio = new Audio(`data:${data.mime || 'audio/mpeg'};base64,${data.audio_base64}`);
      voiceConversationAudio = audio;
      audio.onended = resolve;
      audio.onerror = () => reject(new Error(uiText.voice_chat_error));
      audio.play().catch(reject);
    });
  } catch (e) {
    console.warn('Mistral TTS fallback:', e);
    setInputHint(uiText.voice_chat_browser_fallback);
    return speakWithBrowserVoice(text);
  }
}

function speakWithBrowserVoice(text) {
  if (!window.speechSynthesis || !window.SpeechSynthesisUtterance) {
    throw new Error(uiText.voice_chat_error);
  }

  return new Promise((resolve, reject) => {
    const cleaned = String(text)
      .replace(/```[\s\S]*?```/g, ' bloc de code omis. ')
      .replace(/`([^`]+)`/g, '$1')
      .replace(/[#*_>\[\]\(\)]/g, ' ')
      .replace(/\s+/g, ' ')
      .trim()
      .slice(0, 1800);
    if (!cleaned) {
      resolve();
      return;
    }

    window.speechSynthesis.cancel();
    const utterance = new SpeechSynthesisUtterance(cleaned);
    utterance.lang = uiLanguage || 'fr';
    utterance.rate = 1;
    utterance.pitch = 1;
    utterance.onend = resolve;
    utterance.onerror = () => reject(new Error(uiText.voice_chat_error));
    window.speechSynthesis.speak(utterance);
    voiceConversationAudio = {
      pause: () => window.speechSynthesis.cancel(),
    };
  });
}

async function sendMessage(messageOverride = null, options = {}) {
  if (isBusy) return;

  const input  = document.getElementById('msg-input');
  const msg    = (messageOverride === null ? input.value : messageOverride).trim();
  const model  = document.getElementById('model-select').value;

  if (!msg) return;

  isBusy = true;
  document.getElementById('send-btn').disabled = true;
  if (!options.fromVoice) input.disabled = true;

  // Hide welcome
  document.getElementById('welcome').style.display = 'none';

  // Append user message
  appendUserMsg(msg);
  if (messageOverride === null) {
    input.value = '';
    input.style.height = 'auto';
  }

  // Thinking indicator
  const thinkId = appendThinking();
  scrollBottom();

  try {
    const resp = await fetch('chat.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        message: msg,
        model: model,
        conversation_id: currentConvId,
      }),
    });

    const data = await resp.json();
    removeThinking(thinkId);

    if (data.success) {
      appendAiMsg(data.content, data.model);
      if (data.conversation_id && currentConvId !== data.conversation_id) {
        currentConvId = data.conversation_id;
        addConvToSidebar(data.conversation_id, msg);
      }
      return data;
    } else {
      appendAiMsg((data.error || uiText.unknown_error), model, true);
      return data;
    }

  } catch(e) {
    removeThinking(thinkId);
    appendAiMsg(uiText.connection_error + e.message, model, true);
    return { success: false, error: e.message };
  } finally {
    isBusy = false;
    input.disabled = false;
    if (!options.fromVoice) input.focus();
    scrollBottom();
  }
}

// ============================================================
// RENDER HELPERS
// ============================================================
function appendUserMsg(text) {
  const list = document.getElementById('messages-list');
  const div  = document.createElement('div');
  div.className = 'msg msg-user';
  div.innerHTML = `<div class="bubble">${escHtml(text)}</div>`;
  list.appendChild(div);
}

function appendAiMsg(text, model, isErr = false) {
  const list = document.getElementById('messages-list');
  const div  = document.createElement('div');
  div.className = 'msg msg-ai';

  const modelShort = displayModelName(model);

  div.innerHTML = `
    <img class="ai-avatar" src="libre-claude-icon.png" alt="">
    <div class="ai-body">
      <div class="ai-meta">
        <span class="ai-name">Libre Claude</span>
        ${model ? `<span class="ai-model">${escHtml(modelShort)}</span>` : ''}
      </div>
      <div class="ai-content ${isErr ? 'err-content' : ''}">${renderMarkdown(text)}</div>
    </div>
  `;
  list.appendChild(div);
}

function appendThinking() {
  const list = document.getElementById('messages-list');
  const id   = 'think-' + Date.now();
  const div  = document.createElement('div');
  div.id        = id;
  div.className = 'msg msg-ai';
  div.innerHTML = `
    <img class="ai-avatar" src="libre-claude-icon.png" alt="">
    <div class="ai-body">
      <div class="thinking">
        <span></span><span></span><span></span>
      </div>
    </div>
  `;
  list.appendChild(div);
  return id;
}

function removeThinking(id) {
  const el = document.getElementById(id);
  if (el) el.remove();
}

function scrollBottom() {
  const wrap = document.getElementById('messages-wrap');
  wrap.scrollTop = wrap.scrollHeight;
}

function addConvToSidebar(id, title) {
  if (!isLoggedIn) return;
  const list = document.getElementById('conv-list');
  if (!list) return;

  // Remove "no convs" message if present
  const empty = list.querySelector('[data-empty]');
  if (empty) empty.remove();

  // Check label
  let label = Array.from(list.children).find(child => child.classList.contains('conv-section-label'));
  if (!label) {
    label = document.createElement('div');
    label.className = 'conv-section-label';
    label.textContent = uiText.recent;
    const workspaceMenu = Array.from(list.children).find(child => child.classList.contains('workspace-menu'));
    const reference = workspaceMenu ? workspaceMenu.nextSibling : list.firstChild;
    list.insertBefore(label, reference);
  }

  // Check not already there
  if (document.getElementById(`conv-${id}`)) return;

  const shortTitle = title.length > 40 ? title.slice(0, 40) + '…' : title;
  const div = document.createElement('div');
  div.id        = `conv-${id}`;
  div.dataset.id = id;
  div.className  = 'conv-item active';
  div.innerHTML  = `
    <span class="conv-title">${escHtml(shortTitle)}</span>
    <button class="conv-del" title="${escHtml(uiText.delete)}" onclick="event.stopPropagation(); deleteConversation(${id})">×</button>
  `;
  div.onclick = () => loadConversation(id);

  // Remove active from others
  document.querySelectorAll('.conv-item').forEach(el => el.classList.remove('active'));

  const reference = label.parentNode === list ? label.nextSibling : null;
  list.insertBefore(div, reference && reference.parentNode === list ? reference : null);
}

// ============================================================
// MARKDOWN RENDERER (simple)
// ============================================================
function renderMarkdown(text) {
  const codeBlocks = [];
  let normalized = String(text).replace(/```([^\n`]*)\n?([\s\S]*?)```/g, (_, lang, code) => {
    const index = codeBlocks.push({
      lang: ((lang || 'text').trim().toLowerCase().split(/\s+/)[0] || 'text'),
      code: code.trim(),
    }) - 1;
    return `\n\n@@CODE_BLOCK_${index}@@\n\n`;
  });

  // Escape HTML first
  let html = escHtml(normalized);

  // Inline code `...`
  html = html.replace(/`([^`\n]+)`/g, '<code>$1</code>');

  // Headers
  html = html.replace(/^### (.+)$/gm, '<h3>$1</h3>');
  html = html.replace(/^## (.+)$/gm, '<h2>$1</h2>');
  html = html.replace(/^# (.+)$/gm, '<h1>$1</h1>');

  // Bold + italic
  html = html.replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>');
  html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
  html = html.replace(/\*(.+?)\*/g, '<em>$1</em>');

  // Strikethrough
  html = html.replace(/~~(.+?)~~/g, '<del>$1</del>');

  // Blockquote
  html = html.replace(/^&gt; (.+)$/gm, '<blockquote>$1</blockquote>');

  // Unordered list
  html = html.replace(/^[\-\*] (.+)$/gm, '<li>$1</li>');
  html = html.replace(/(<li>.*<\/li>\n?)+/g, m => `<ul>${m}</ul>`);

  // Ordered list
  html = html.replace(/^\d+\. (.+)$/gm, '<li>$1</li>');

  // Horizontal rule
  html = html.replace(/^---+$/gm, '<hr>');

  // Line breaks → paragraphs (skip if inside block elements)
  html = html.replace(/\n\n+/g, '</p><p>');
  html = html.replace(/\n/g, '<br>');
  html = '<p>' + html + '</p>';

  html = html.replace(/@@CODE_BLOCK_(\d+)@@/g, (_, index) => renderCodeBlock(codeBlocks[Number(index)]));

  // Clean up empty paragraphs around block elements
  html = html.replace(/<p>\s*(<(?:div|pre|ul|ol|blockquote|h[1-6]|hr)[^>]*>)/g, '$1');
  html = html.replace(/(<\/(?:div|pre|ul|ol|blockquote|h[1-6]|hr)>)\s*<\/p>/g, '$1');
  html = html.replace(/<p><br><\/p>/g, '');
  html = html.replace(/<p>\s*<\/p>/g, '');

  return html;
}

function renderCodeBlock(block) {
  if (!block) return '';
  const lang = block.lang || 'text';
  const code = block.code || '';
  const encoded = encodeCode(code);
  const canPreview = ['html', 'css', 'js', 'javascript', 'svg'].includes(lang);
  return `
    <div class="code-artifact" data-lang="${escHtml(lang)}" data-code="${encoded}">
      <div class="code-toolbar">
        <span class="code-lang">${escHtml(lang || 'text')}</span>
        <div class="code-actions">
          <button class="code-action" type="button" onclick="copyCodeBlock(this)">${escHtml(uiText.code_copy)}</button>
          ${canPreview ? `<button class="code-action" type="button" onclick="previewCodeBlock(this)">${escHtml(uiText.code_preview)}</button>` : ''}
          <button class="code-action" type="button" onclick="saveCodeBlock(this)">${escHtml(uiText.code_workspace)}</button>
        </div>
      </div>
      <pre><code class="lang-${escHtml(lang)}">${escHtml(code)}</code></pre>
    </div>
  `;
}

function encodeCode(code) {
  return btoa(unescape(encodeURIComponent(code)));
}

function decodeCode(encoded) {
  return decodeURIComponent(escape(atob(encoded)));
}

async function copyCodeBlock(button) {
  const artifact = button.closest('.code-artifact');
  if (!artifact) return;
  await navigator.clipboard.writeText(decodeCode(artifact.dataset.code || ''));
  button.textContent = uiText.code_copied;
  setTimeout(() => { button.textContent = uiText.code_copy; }, 1400);
}

function previewCodeBlock(button) {
  const artifact = button.closest('.code-artifact');
  if (!artifact) return;
  const lang = (artifact.dataset.lang || 'text').toLowerCase();
  const code = decodeCode(artifact.dataset.code || '');
  const iframe = document.getElementById('code-preview-frame');
  iframe.srcdoc = buildPreviewDoc(lang, code);
  document.getElementById('code-preview-panel').classList.add('open');
}

function closeCodePreview() {
  document.getElementById('code-preview-frame').srcdoc = '';
  document.getElementById('code-preview-panel').classList.remove('open');
}

function buildPreviewDoc(lang, code) {
  if (lang === 'html' || lang === 'svg') return code;
  if (lang === 'css') {
    return `<!doctype html><html><head><meta charset="utf-8"><style>${code}</style></head><body><main class="preview-root">CSS preview</main></body></html>`;
  }
  if (lang === 'js' || lang === 'javascript') {
    return `<!doctype html><html><head><meta charset="utf-8"></head><body><main id="app"></main><script>${code.replace(/<\/script/gi, '<\\/script')}<\/script></body></html>`;
  }
  return `<!doctype html><html><body><pre>${escHtml(code)}</pre></body></html>`;
}

async function saveCodeBlock(button) {
  const artifact = button.closest('.code-artifact');
  if (!artifact) return;
  const lang = artifact.dataset.lang || 'text';
  const content = decodeCode(artifact.dataset.code || '');
  const original = button.textContent;
  button.disabled = true;
  try {
    const resp = await fetch('workspace_api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'save_block',
        language: lang,
        content,
        conversation_id: currentConvId,
      }),
    });
    const data = await resp.json();
    if (!data.success) throw new Error(data.error || uiText.workspace_save_error);
    button.textContent = uiText.workspace_saved;
  } catch (e) {
    button.textContent = uiText.workspace_save_error;
  } finally {
    setTimeout(() => {
      button.disabled = false;
      button.textContent = original;
    }, 1800);
  }
}

// ============================================================
// UTILS
// ============================================================
function escHtml(s) {
  return String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function updateAppHeight() {
  const height = window.visualViewport ? window.visualViewport.height : window.innerHeight;
  document.documentElement.style.setProperty('--app-height', `${Math.max(320, height)}px`);
}

// Init: enable/disable send button
document.addEventListener('DOMContentLoaded', () => {
  updateAppHeight();
  const inp = document.getElementById('msg-input');
  if (inp) {
    inp.addEventListener('input', () => {
      document.getElementById('send-btn').disabled = inp.value.trim() === '' || isBusy;
    });
    inp.addEventListener('focus', () => {
      setTimeout(() => {
        updateAppHeight();
        scrollBottom();
      }, 120);
    });
  }
  loadLatestRelease();
});

window.addEventListener('resize', updateAppHeight);
window.addEventListener('orientationchange', () => setTimeout(updateAppHeight, 180));
if (window.visualViewport) {
  window.visualViewport.addEventListener('resize', () => {
    updateAppHeight();
    scrollBottom();
  });
}

async function loadLatestRelease() {
  const target = document.getElementById('release-version');
  if (!target) return;
  try {
    const resp = await fetch(githubReleaseApi, {
      headers: { 'Accept': 'application/vnd.github+json' },
    });
    if (!resp.ok) throw new Error('release unavailable');
    const data = await resp.json();
    target.textContent = data.tag_name || data.name || uiText.release_unavailable;
  } catch (e) {
    target.textContent = uiText.release_unavailable;
  }
}
</script>
</body>
</html>
