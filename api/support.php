<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require_once __DIR__ . '/plans.php';
session_start();
if (empty($_SESSION['user_id'])) jsonResponse(false, 'Потрібна авторизація.', [], 401);

$pdo = db();
ensurePlanSchema($pdo);
$userId = (string)$_SESSION['user_id'];
$userQuery = $pdo->prepare('SELECT id, name, role, is_support_agent FROM users WHERE id = ? LIMIT 1');
$userQuery->execute([$userId]);
$user = $userQuery->fetch();
if (!$user) jsonResponse(false, 'Користувача не знайдено.', [], 401);
$isAdmin = in_array((string)$user['role'], ['admin', 'owner'], true);
$isSupportStaff = $isAdmin || !empty($user['is_support_agent']);
$planContext = workspacePlan($pdo, $userId, (string)$user['role']);
$workspaceId = (string)$planContext['owner_id'];

ensureSupportSchema($pdo);
purgeExpiredSupportSessions($pdo);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!$isSupportStaff && empty($planContext['plan']['support'])) {
    jsonResponse(false, 'Онлайн-підтримка доступна у планах «Команда» та «Бізнес».', ['code' => 'plan_feature_locked'], 403);
}

if ($method === 'GET') {
    $action = (string)($_GET['action'] ?? '');
    if ($action === 'unread') {
        $query = $isSupportStaff
            ? $pdo->prepare("SELECT COUNT(*) FROM support_messages sm JOIN support_sessions ss ON ss.id = sm.session_id JOIN users sender ON sender.id = sm.sender_id LEFT JOIN support_reads sr ON sr.workspace_id = sm.workspace_id AND sr.user_id = ? WHERE ss.status = 'open' AND sender.role NOT IN ('admin','owner') AND COALESCE(sender.is_support_agent, 0) = 0 AND sm.created_at > COALESCE(sr.last_read_at, '1970-01-01 00:00:00')")
            : $pdo->prepare("SELECT COUNT(*) FROM support_messages sm JOIN support_sessions ss ON ss.id = sm.session_id JOIN users sender ON sender.id = sm.sender_id LEFT JOIN support_reads sr ON sr.workspace_id = sm.workspace_id AND sr.user_id = ? WHERE ss.workspace_id = ? AND ss.status = 'open' AND (sender.role IN ('admin','owner') OR COALESCE(sender.is_support_agent, 0) = 1) AND sm.created_at > COALESCE(sr.last_read_at, '1970-01-01 00:00:00')");
        $query->execute($isSupportStaff ? [$userId] : [$userId, $workspaceId]);
        jsonResponse(true, 'Лічильник непрочитаних повідомлень завантажено.', ['unread' => (int)$query->fetchColumn()]);
    }

    if ($isSupportStaff) {
        $status = ($_GET['status'] ?? 'open') === 'closed' ? 'closed' : 'open';
        $sessionId = trim((string)($_GET['session_id'] ?? ''));
        if ($status === 'open' && $sessionId !== '') {
            $claim = $pdo->prepare("UPDATE support_sessions SET assigned_to = ?, assigned_at = CURRENT_TIMESTAMP WHERE id = ? AND status = 'open' AND assigned_to IS NULL");
            $claim->execute([$userId, $sessionId]);
        }
        $sessionsQuery = $pdo->prepare("SELECT ss.id, ss.workspace_id, ss.status, ss.assigned_to, ss.assigned_at, assignee.name AS assigned_name, ss.created_at, ss.updated_at, ss.closed_at, u.name AS workspace_name, u.email AS workspace_email, u.plan_key, COUNT(sm.id) AS message_count, SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(NULLIF(sm.message, ''), '📷 Зображення') ORDER BY sm.created_at DESC, sm.id DESC SEPARATOR '\n'), '\n', 1) AS last_message, MAX(sm.created_at) AS last_message_at FROM support_sessions ss LEFT JOIN users u ON u.id = ss.workspace_id LEFT JOIN users assignee ON assignee.id = ss.assigned_to LEFT JOIN support_messages sm ON sm.session_id = ss.id WHERE ss.status = ? GROUP BY ss.id, ss.workspace_id, ss.status, ss.assigned_to, ss.assigned_at, assignee.name, ss.created_at, ss.updated_at, ss.closed_at, u.id, u.name, u.email, u.plan_key ORDER BY CASE u.plan_key WHEN 'business' THEN 0 WHEN 'team' THEN 1 ELSE 2 END, COALESCE(MAX(sm.created_at), ss.created_at) DESC");
        $sessionsQuery->execute([$status]);
        $sessions = $sessionsQuery->fetchAll();
        $session = null;
        $messages = [];
        if ($sessionId !== '') {
            $sessionQuery = $pdo->prepare('SELECT ss.id, ss.workspace_id, ss.status, ss.assigned_to, ss.assigned_at, assignee.name AS assigned_name, ss.created_at, ss.updated_at, ss.closed_at, u.name AS workspace_name, u.email AS workspace_email, u.plan_key FROM support_sessions ss JOIN users u ON u.id = ss.workspace_id LEFT JOIN users assignee ON assignee.id = ss.assigned_to WHERE ss.id = ? LIMIT 1');
            $sessionQuery->execute([$sessionId]);
            $session = $sessionQuery->fetch() ?: null;
            if ($session) {
                $messageQuery = $pdo->prepare('SELECT sm.id, sm.session_id, sm.workspace_id, sm.sender_id, u.name AS sender_name, u.role AS sender_role, sm.message, sm.created_at FROM support_messages sm JOIN users u ON u.id = sm.sender_id WHERE sm.session_id = ? ORDER BY sm.created_at ASC, sm.id ASC LIMIT 500');
                $messageQuery->execute([$sessionId]);
                $messages = $messageQuery->fetchAll();
                $pdo->prepare('INSERT INTO support_reads (workspace_id, user_id, last_read_at) VALUES (?, ?, CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE last_read_at = CURRENT_TIMESTAMP')->execute([$session['workspace_id'], $userId]);
            }
        }
        attachSupportFiles($pdo, $messages);
        jsonResponse(true, 'Звернення підтримки завантажено.', ['staff' => true, 'sessions' => $sessions, 'session' => $session, 'messages' => $messages]);
    }

    $requestedSessionId = trim((string)($_GET['session_id'] ?? ''));
    if ($requestedSessionId !== '') {
        $sessionQuery = $pdo->prepare("SELECT id, workspace_id, status, created_at, updated_at, closed_at FROM support_sessions ss WHERE workspace_id = ? AND id = ? AND (status <> 'closed' OR created_by = ? OR (created_by IS NULL AND EXISTS (SELECT 1 FROM support_messages own_message WHERE own_message.session_id = ss.id AND own_message.sender_id = ?))) LIMIT 1");
        $sessionQuery->execute([$workspaceId, $requestedSessionId, $userId, $userId]);
    } else {
        $sessionQuery = $pdo->prepare("SELECT id, workspace_id, status, created_at, updated_at, closed_at FROM support_sessions WHERE workspace_id = ? AND status = 'open' ORDER BY updated_at DESC LIMIT 1");
        $sessionQuery->execute([$workspaceId]);
    }
    $session = $sessionQuery->fetch() ?: null;
    $messages = [];
    if ($session) {
        $messageQuery = $pdo->prepare('SELECT sm.id, sm.session_id, sm.workspace_id, sm.sender_id, u.name AS sender_name, u.role AS sender_role, sm.message, sm.created_at FROM support_messages sm JOIN users u ON u.id = sm.sender_id WHERE sm.session_id = ? ORDER BY sm.created_at ASC, sm.id ASC LIMIT 500');
        $messageQuery->execute([$session['id']]);
        $messages = $messageQuery->fetchAll();
        $pdo->prepare('INSERT INTO support_reads (workspace_id, user_id, last_read_at) VALUES (?, ?, CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE last_read_at = CURRENT_TIMESTAMP')->execute([$workspaceId, $userId]);
    }
    attachSupportFiles($pdo, $messages);
    $closedQuery = $pdo->prepare("SELECT id, closed_at, DATE_ADD(closed_at, INTERVAL 60 DAY) AS expires_at FROM support_sessions ss WHERE workspace_id = ? AND status = 'closed' AND (created_by = ? OR (created_by IS NULL AND EXISTS (SELECT 1 FROM support_messages own_message WHERE own_message.session_id = ss.id AND own_message.sender_id = ?))) ORDER BY closed_at DESC LIMIT 20");
    $closedQuery->execute([$workspaceId, $userId, $userId]);
    jsonResponse(true, 'Чат підтримки завантажено.', ['staff' => false, 'workspace_id' => $workspaceId, 'session' => $session, 'messages' => $messages, 'closed_sessions' => $closedQuery->fetchAll()]);
}

if ($method !== 'POST') jsonResponse(false, 'Метод не підтримується.', [], 405);
$multipart = str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'multipart/form-data');
$data = $multipart ? $_POST : body();
$action = (string)($data['action'] ?? 'message');

if ($action === 'create_session') {
    if ($isSupportStaff) jsonResponse(false, 'Команда підтримки не може відкривати звернення від імені простору.', [], 403);
    $existing = $pdo->prepare("SELECT id FROM support_sessions WHERE workspace_id = ? AND status = 'open' ORDER BY updated_at DESC LIMIT 1");
    $existing->execute([$workspaceId]);
    $sessionId = $existing->fetchColumn();
    if (!$sessionId) {
        $sessionId = bin2hex(random_bytes(16));
        $pdo->prepare("INSERT INTO support_sessions (id, workspace_id, created_by, status) VALUES (?, ?, ?, 'open')")->execute([$sessionId, $workspaceId, $userId]);
    }
    jsonResponse(true, 'Звернення відкрито.', ['session_id' => $sessionId]);
}

$sessionId = trim((string)($data['session_id'] ?? ''));
if ($action === 'close_session') {
    if ($sessionId === '') jsonResponse(false, 'Звернення не вказано.', [], 422);
    $sessionQuery = $pdo->prepare('SELECT id, workspace_id, status FROM support_sessions WHERE id = ? LIMIT 1');
    $sessionQuery->execute([$sessionId]);
    $session = $sessionQuery->fetch();
    if (!$session || (!$isSupportStaff && (string)$session['workspace_id'] !== $workspaceId)) jsonResponse(false, 'Звернення не знайдено.', [], 404);
    if ($session['status'] === 'closed') jsonResponse(true, 'Звернення вже в архіві.', ['status' => 'closed']);
    if ($isSupportStaff && (string)($data['workspace_id'] ?? '') !== (string)$session['workspace_id']) jsonResponse(false, 'Звернення належить іншому робочому простору.', [], 403);
    if ($isSupportStaff) {
        $claim = $pdo->prepare("UPDATE support_sessions SET assigned_to = ?, assigned_at = CURRENT_TIMESTAMP WHERE id = ? AND status = 'open' AND assigned_to IS NULL");
        $claim->execute([$userId, $sessionId]);
        $owner = $pdo->prepare('SELECT ss.assigned_to, u.name AS assigned_name FROM support_sessions ss LEFT JOIN users u ON u.id = ss.assigned_to WHERE ss.id = ?');
        $owner->execute([$sessionId]);
        $assignment = $owner->fetch();
        if ((string)$assignment['assigned_to'] !== $userId) jsonResponse(false, 'Це звернення вже опрацьовує ' . ($assignment['assigned_name'] ?: 'інший фахівець підтримки') . '.', ['code' => 'support_session_assigned', 'assigned_to' => $assignment['assigned_to'], 'assigned_name' => $assignment['assigned_name']], 409);
    }
    $pdo->prepare("UPDATE support_sessions SET status = 'closed', closed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND status = 'open'")->execute([$sessionId]);
    jsonResponse(true, 'Звернення закрито та перенесено в архів на 60 днів.', ['status' => 'closed']);
}

if ($action !== 'message') jsonResponse(false, 'Невідома дія.', [], 422);
$message = trim((string)($data['message'] ?? ''));
$file = $multipart ? ($_FILES['file'] ?? null) : null;
$hasFile = is_array($file) && (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
if ($message === '' && !$hasFile) jsonResponse(false, 'Напишіть повідомлення або додайте зображення.', [], 422);
if (mb_strlen($message) > 4000) jsonResponse(false, 'Повідомлення не може бути довшим за 4000 символів.', [], 422);
if ($sessionId === '') jsonResponse(false, 'Спочатку відкрийте звернення.', [], 422);
$sessionQuery = $pdo->prepare('SELECT id, workspace_id, status, assigned_to FROM support_sessions WHERE id = ? LIMIT 1');
$sessionQuery->execute([$sessionId]);
$session = $sessionQuery->fetch();
if (!$session || (!$isSupportStaff && (string)$session['workspace_id'] !== $workspaceId)) jsonResponse(false, 'Звернення не знайдено.', [], 404);
if ($session['status'] !== 'open') jsonResponse(false, 'Це звернення закрите. Створіть нове, щоб продовжити спілкування.', ['code' => 'support_session_closed'], 409);
if ($isSupportStaff && (string)($data['workspace_id'] ?? '') !== (string)$session['workspace_id']) jsonResponse(false, 'Звернення належить іншому робочому простору.', [], 403);
if ($isSupportStaff) {
    $claim = $pdo->prepare("UPDATE support_sessions SET assigned_to = ?, assigned_at = CURRENT_TIMESTAMP WHERE id = ? AND status = 'open' AND assigned_to IS NULL");
    $claim->execute([$userId, $sessionId]);
    $owner = $pdo->prepare('SELECT ss.assigned_to, u.name AS assigned_name FROM support_sessions ss LEFT JOIN users u ON u.id = ss.assigned_to WHERE ss.id = ?');
    $owner->execute([$sessionId]);
    $assignment = $owner->fetch();
    if ((string)$assignment['assigned_to'] !== $userId) jsonResponse(false, 'Це звернення вже опрацьовує ' . ($assignment['assigned_name'] ?: 'інший фахівець підтримки') . '.', ['code' => 'support_session_assigned', 'assigned_to' => $assignment['assigned_to'], 'assigned_name' => $assignment['assigned_name']], 409);
}

$attachment = null;
if ($hasFile) {
    if ((int)$file['error'] !== UPLOAD_ERR_OK) jsonResponse(false, 'Не вдалося завантажити зображення.', [], 422);
    if ((int)$file['size'] < 1 || (int)$file['size'] > 8 * 1024 * 1024) jsonResponse(false, 'Максимальний розмір зображення - 8 МБ.', [], 422);
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string)$finfo->file((string)$file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/avif' => 'avif', 'image/bmp' => 'bmp', 'image/heic' => 'heic', 'image/heif' => 'heif', 'image/tiff' => 'tiff'];
    if (!isset($extensions[$mimeType])) jsonResponse(false, 'У чаті можна прикріплювати лише зображення.', [], 422);
    $storage = __DIR__ . '/support-private';
    if (!is_dir($storage) && !mkdir($storage, 0700, true) && !is_dir($storage)) jsonResponse(false, 'Не вдалося підготувати приватне сховище зображень.', [], 500);
    $storageHtaccess = $storage . '/.htaccess';
    if (!is_file($storageHtaccess)) file_put_contents($storageHtaccess, "Require all denied\nOptions -Indexes\n");
    $fileId = bin2hex(random_bytes(16));
    $storedName = $fileId . '.' . $extensions[$mimeType];
    if (!move_uploaded_file((string)$file['tmp_name'], $storage . '/' . $storedName)) jsonResponse(false, 'Не вдалося зберегти зображення.', [], 500);
    $originalName = basename((string)$file['name']);
    $originalName = preg_replace('/[\x00-\x1F\x7F]/u', '', $originalName) ?: 'image.' . $extensions[$mimeType];
    $attachment = ['id' => $fileId, 'name' => mb_substr($originalName, 0, 255), 'mime' => $mimeType, 'size' => (int)$file['size'], 'path' => $storedName];
}

$messageId = bin2hex(random_bytes(16));
$pdo->beginTransaction();
try {
    $pdo->prepare('INSERT INTO support_messages (id, session_id, workspace_id, sender_id, message) VALUES (?, ?, ?, ?, ?)')->execute([$messageId, $sessionId, $session['workspace_id'], $userId, $message]);
    if ($attachment) $pdo->prepare('INSERT INTO support_attachments (id, message_id, session_id, workspace_id, uploader_id, original_name, mime_type, file_size, file_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$attachment['id'], $messageId, $sessionId, $session['workspace_id'], $userId, $attachment['name'], $attachment['mime'], $attachment['size'], $attachment['path']]);
    $pdo->prepare('UPDATE support_sessions SET updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$sessionId]);
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($attachment) @unlink(__DIR__ . '/support-private/' . $attachment['path']);
    jsonResponse(false, 'Не вдалося зберегти повідомлення. Спробуйте ще раз.', [], 500);
}
$created = $pdo->prepare('SELECT sm.id, sm.session_id, sm.workspace_id, sm.sender_id, u.name AS sender_name, u.role AS sender_role, sm.message, sm.created_at FROM support_messages sm JOIN users u ON u.id = sm.sender_id WHERE sm.id = ? LIMIT 1');
$created->execute([$messageId]);
$messageItem = $created->fetch();
$messageItem['attachments'] = $attachment ? [['id' => $attachment['id'], 'original_name' => $attachment['name'], 'mime_type' => $attachment['mime'], 'file_size' => $attachment['size']]] : [];
jsonResponse(true, 'Повідомлення надіслано.', ['message_item' => $messageItem]);

function ensureSupportSchema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS support_sessions (
        id CHAR(32) NOT NULL PRIMARY KEY,
        workspace_id CHAR(32) NOT NULL,
        created_by CHAR(32) NULL,
        status ENUM('open','closed') NOT NULL DEFAULT 'open',
        assigned_to CHAR(32) NULL,
        assigned_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        closed_at DATETIME NULL,
        INDEX idx_support_sessions_workspace_status (workspace_id, status, updated_at),
        INDEX idx_support_sessions_creator (workspace_id, created_by, status, closed_at),
        INDEX idx_support_sessions_retention (status, closed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS support_messages (
        id CHAR(32) NOT NULL PRIMARY KEY,
        session_id CHAR(32) NULL,
        workspace_id CHAR(32) NOT NULL,
        sender_id CHAR(32) NOT NULL,
        message TEXT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_support_session_created (session_id, created_at, id),
        INDEX idx_support_workspace_created (workspace_id, created_at, id),
        INDEX idx_support_sender (sender_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS support_attachments (
        id CHAR(32) NOT NULL PRIMARY KEY,
        message_id CHAR(32) NOT NULL,
        session_id CHAR(32) NULL,
        workspace_id CHAR(32) NOT NULL,
        uploader_id CHAR(32) NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        mime_type VARCHAR(120) NOT NULL,
        file_size INT UNSIGNED NOT NULL,
        file_path VARCHAR(100) NULL,
        file_data LONGBLOB NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_support_attachment_message (message_id),
        INDEX idx_support_attachment_session (session_id),
        INDEX idx_support_attachment_workspace (workspace_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS support_reads (
        workspace_id CHAR(32) NOT NULL,
        user_id CHAR(32) NOT NULL,
        last_read_at DATETIME NOT NULL,
        PRIMARY KEY (workspace_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $columns = $pdo->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND ((TABLE_NAME = 'support_sessions' AND COLUMN_NAME IN ('created_by','assigned_to','assigned_at')) OR (TABLE_NAME = 'support_messages' AND COLUMN_NAME = 'session_id') OR (TABLE_NAME = 'support_attachments' AND COLUMN_NAME IN ('session_id','file_path'))) ")->fetchAll();
    $existing = [];
    foreach ($columns as $column) $existing[$column['TABLE_NAME'] . '.' . $column['COLUMN_NAME']] = true;
    if (!isset($existing['support_sessions.created_by'])) $pdo->exec('ALTER TABLE support_sessions ADD created_by CHAR(32) NULL, ADD INDEX idx_support_sessions_creator (workspace_id, created_by, status, closed_at)');
    if (!isset($existing['support_sessions.assigned_to'])) $pdo->exec('ALTER TABLE support_sessions ADD assigned_to CHAR(32) NULL');
    if (!isset($existing['support_sessions.assigned_at'])) $pdo->exec('ALTER TABLE support_sessions ADD assigned_at DATETIME NULL');
    if (!isset($existing['support_messages.session_id'])) $pdo->exec('ALTER TABLE support_messages ADD session_id CHAR(32) NULL, ADD INDEX idx_support_session_created (session_id, created_at, id)');
    if (!isset($existing['support_attachments.session_id'])) $pdo->exec('ALTER TABLE support_attachments ADD session_id CHAR(32) NULL, ADD INDEX idx_support_attachment_session (session_id)');
    if (!isset($existing['support_attachments.file_path'])) {
        $pdo->exec('ALTER TABLE support_attachments MODIFY file_data LONGBLOB NULL, ADD file_path VARCHAR(100) NULL');
    }

    $legacy = $pdo->query('SELECT DISTINCT workspace_id FROM support_messages WHERE session_id IS NULL')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($legacy as $legacyWorkspaceId) {
        $sessionId = bin2hex(random_bytes(16));
        $pdo->prepare("INSERT INTO support_sessions (id, workspace_id, status, created_at, updated_at) SELECT ?, ?, 'open', COALESCE(MIN(created_at), CURRENT_TIMESTAMP), COALESCE(MAX(created_at), CURRENT_TIMESTAMP) FROM support_messages WHERE workspace_id = ? AND session_id IS NULL")->execute([$sessionId, $legacyWorkspaceId, $legacyWorkspaceId]);
        $pdo->prepare('UPDATE support_messages SET session_id = ? WHERE workspace_id = ? AND session_id IS NULL')->execute([$sessionId, $legacyWorkspaceId]);
        $pdo->prepare('UPDATE support_attachments sa JOIN support_messages sm ON sm.id = sa.message_id SET sa.session_id = ? WHERE sm.session_id = ? AND sa.session_id IS NULL')->execute([$sessionId, $sessionId]);
    }
    $pdo->exec("UPDATE support_sessions ss SET created_by=(SELECT sm.sender_id FROM support_messages sm JOIN users u ON u.id=sm.sender_id WHERE sm.session_id=ss.id AND u.role NOT IN ('admin','owner') AND COALESCE(u.is_support_agent,0)=0 ORDER BY sm.created_at,sm.id LIMIT 1) WHERE ss.created_by IS NULL");
}

function purgeExpiredSupportSessions(PDO $pdo): void
{
    $expired = $pdo->query("SELECT id FROM support_sessions WHERE status = 'closed' AND closed_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 60 DAY) ORDER BY closed_at ASC LIMIT 100")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($expired as $sessionId) {
        $files = $pdo->prepare('SELECT file_path FROM support_attachments WHERE session_id = ? AND file_path IS NOT NULL');
        $files->execute([$sessionId]);
        foreach ($files->fetchAll(PDO::FETCH_COLUMN) as $path) {
            if (preg_match('/^[a-f0-9]{32}\.[a-z0-9]{2,8}$/', (string)$path)) @unlink(__DIR__ . '/support-private/' . $path);
        }
        $pdo->prepare('DELETE FROM support_attachments WHERE session_id = ?')->execute([$sessionId]);
        $pdo->prepare('DELETE FROM support_messages WHERE session_id = ?')->execute([$sessionId]);
        $pdo->prepare('DELETE FROM support_sessions WHERE id = ?')->execute([$sessionId]);
    }
}

function attachSupportFiles(PDO $pdo, array &$messages): void
{
    if (!$messages) return;
    $ids = array_column($messages, 'id');
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $query = $pdo->prepare("SELECT id, message_id, original_name, mime_type, file_size FROM support_attachments WHERE message_id IN ({$marks}) ORDER BY created_at, id");
    $query->execute($ids);
    $byMessage = [];
    foreach ($query->fetchAll() as $file) {
        $byMessage[$file['message_id']][] = ['id' => $file['id'], 'original_name' => $file['original_name'], 'mime_type' => $file['mime_type'], 'file_size' => (int)$file['file_size']];
    }
    foreach ($messages as &$message) $message['attachments'] = $byMessage[$message['id']] ?? [];
    unset($message);
}
