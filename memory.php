<?php
/**
 * Libre Claude - mémoire utilisateur et contexte workspace
 */

function memory_user_settings($user) {
    $settings = json_decode($user['settings'] ?? '{}', true);
    if (!is_array($settings)) $settings = [];
    return [
        'auto_memory' => array_key_exists('auto_memory', $settings) ? (bool)$settings['auto_memory'] : true,
        'workspace_context' => array_key_exists('workspace_context', $settings) ? (bool)$settings['workspace_context'] : true,
    ];
}

function memory_clean_text($text) {
    $text = trim(preg_replace('/\s+/u', ' ', (string)$text));
    return mb_substr($text, 0, 420);
}

function memory_is_durable($text) {
    $lower = mb_strtolower($text);
    $patterns = [
        'souviens', 'rappelle', 'n’oublie', "n'oublie", 'mémoire', 'memoire',
        'mon nom', 'je suis', 'j’utilise', "j'utilise", 'je travaille', 'je préfère',
        'je prefere', 'toujours', 'par défaut', 'par defaut', 'mon projet',
        'workspace', 'github', 'dépôt', 'depot', 'repo', 'branche', 'stack',
        'technologie', 'langage', 'framework', 'serveur', 'docker',
    ];
    foreach ($patterns as $pattern) {
        if (mb_strpos($lower, $pattern) !== false) return true;
    }
    return false;
}

function memory_scope_for_text($text) {
    $lower = mb_strtolower($text);
    foreach (['workspace', 'github', 'dépôt', 'depot', 'repo', 'branche', 'commit', 'docker', 'fichier', 'projet'] as $pattern) {
        if (mb_strpos($lower, $pattern) !== false) return 'workspace';
    }
    return 'general';
}

function memory_store($db, $userId, $scope, $content, $conversationId = null) {
    $content = memory_clean_text($content);
    if ($content === '' || mb_strlen($content) < 12) return false;

    $scope = in_array($scope, ['general', 'workspace'], true) ? $scope : 'general';
    $hash = hash('sha256', mb_strtolower($content));

    $db->query(
        "INSERT INTO user_memories (user_id, scope, content, key_hash, source_conversation_id, updated_at)
         VALUES (?, ?, ?, ?, ?, datetime('now'))
         ON CONFLICT(user_id, scope, key_hash) DO UPDATE SET updated_at = datetime('now')",
        [$userId, $scope, $content, $hash, $conversationId]
    );

    $db->query(
        "DELETE FROM user_memories
         WHERE user_id = ?
           AND id NOT IN (
             SELECT id FROM user_memories
             WHERE user_id = ?
             ORDER BY updated_at DESC
             LIMIT 80
           )",
        [$userId, $userId]
    );

    return true;
}

function memory_capture_from_message($db, $user, $message, $conversationId = null) {
    if (!$user) return;
    $settings = memory_user_settings($user);
    if (!$settings['auto_memory']) return;

    $message = memory_clean_text($message);
    if (!memory_is_durable($message)) return;

    $scope = memory_scope_for_text($message);
    memory_store($db, (int)$user['id'], $scope, $message, $conversationId);
}

function memory_build_context($db, $user) {
    if (!$user) return '';
    $settings = memory_user_settings($user);
    $parts = [];

    if ($settings['auto_memory']) {
        $memories = $db->fetchAll(
            "SELECT id, scope, content FROM user_memories
             WHERE user_id = ?
             ORDER BY updated_at DESC
             LIMIT 16",
            [(int)$user['id']]
        );
        if ($memories) {
            $lines = [];
            foreach ($memories as $memory) {
                $scope = ($memory['scope'] ?? 'general') === 'workspace' ? 'workspace' : 'général';
                $lines[] = '- [' . $scope . '] ' . $memory['content'];
            }
            $parts[] = "Mémoire utilisateur à utiliser quand c'est pertinent :\n" . implode("\n", $lines);
            $ids = array_map(fn($memory) => (int)$memory['id'], $memories);
            if ($ids) {
                $db->query(
                    "UPDATE user_memories SET last_used_at = datetime('now') WHERE user_id = ? AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")",
                    array_merge([(int)$user['id']], $ids)
                );
            }
        }
    }

    if ($settings['workspace_context']) {
        $github = $db->fetch("SELECT owner, repo, branch, updated_at FROM workspace_github WHERE user_id = ?", [(int)$user['id']]);
        $workspaceFiles = $db->fetchAll(
            "SELECT name, language, github_path, updated_at FROM workspace_files
             WHERE user_id = ?
             ORDER BY updated_at DESC
             LIMIT 8",
            [(int)$user['id']]
        );

        $workspaceLines = [];
        if ($github && !empty($github['owner']) && !empty($github['repo'])) {
            $workspaceLines[] = '- Dépôt connecté : ' . $github['owner'] . '/' . $github['repo'] . ' sur la branche ' . ($github['branch'] ?: 'main');
        }
        foreach ($workspaceFiles as $file) {
            $label = $file['github_path'] ?: $file['name'];
            $workspaceLines[] = '- Fichier/bloc récent : ' . $label . ' (' . ($file['language'] ?: 'text') . ')';
        }
        if ($workspaceLines) {
            $parts[] = "Contexte workspace actif :\n" . implode("\n", $workspaceLines);
        }
    }

    if (!$parts) return '';
    return implode("\n\n", $parts) . "\n\nN'annonce pas cette mémoire sauf si l'utilisateur le demande. Utilise-la seulement pour garder le contexte, les préférences et les projets cohérents.";
}
