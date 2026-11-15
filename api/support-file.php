<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require_once __DIR__ . '/plans.php';
session_start();
if (empty($_SESSION['user_id'])) jsonResponse(false, 'Потрібна авторизація.', [], 401);
$pdo = db();
ensurePlanSchema($pdo);
$userId = (string)$_SESSION['user_id'];
$userQuery = $pdo->prepare('SELECT role, is_support_agent FROM users WHERE id = ? LIMIT 1');
$userQuery->execute([$userId]);
$user = $userQuery->fetch();
if (!$user) jsonResponse(false, 'Користувача не знайдено.', [], 401);
$fileQuery = $pdo->prepare('SELECT sa.id, sa.workspace_id, sa.original_name, sa.mime_type, sa.file_path, sa.file_data, ss.status, ss.closed_at FROM support_attachments sa LEFT JOIN support_sessions ss ON ss.id = sa.session_id WHERE sa.id = ? LIMIT 1');
$fileQuery->execute([trim((string)($_GET['id'] ?? ''))]);
$file = $fileQuery->fetch();
if (!$file || ($file['status'] === 'closed' && $file['closed_at'] < date('Y-m-d H:i:s', strtotime('-60 days')))) jsonResponse(false, 'Зображення не знайдено або його строк зберігання завершився.', [], 404);
$isSupportStaff = in_array((string)$user['role'], ['admin', 'owner'], true) || !empty($user['is_support_agent']);
if (!$isSupportStaff) {
    $context = workspacePlan($pdo, $userId, (string)$user['role']);
    if ((string)$context['owner_id'] !== (string)$file['workspace_id'] || empty($context['plan']['support'])) jsonResponse(false, 'Немає доступу до цього зображення.', [], 403);
}
$content = null;
if (!empty($file['file_path'])) {
    $name = (string)$file['file_path'];
    if (!preg_match('/^[a-f0-9]{32}\.(jpg|png|gif|webp|avif|bmp|heic|heif|tiff)$/', $name)) jsonResponse(false, 'Некоректний файл.', [], 404);
    $path = __DIR__ . '/support-private/' . $name;
    if (!is_file($path)) jsonResponse(false, 'Зображення недоступне.', [], 404);
    $content = file_get_contents($path);
} elseif ($file['file_data'] !== null) {
    // Legacy attachment stored as a database BLOB before private file storage was introduced.
    $content = (string)$file['file_data'];
}
if ($content === null) jsonResponse(false, 'Зображення недоступне.', [], 404);
$mime = (string)$file['mime_type'];
if (!preg_match('/^image\/(jpeg|png|gif|webp|avif|bmp|heic|heif|tiff)$/', $mime)) jsonResponse(false, 'Некоректний тип зображення.', [], 404);
header('Content-Type: ' . $mime);
header('Content-Length: ' . strlen($content));
header('Content-Disposition: inline; filename*=UTF-8\'\'' . rawurlencode((string)$file['original_name']));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Cache-Control: private, no-store, max-age=0');
echo $content;
