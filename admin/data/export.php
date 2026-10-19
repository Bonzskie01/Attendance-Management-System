<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/config.php';

if (!is_admin_logged_in() || ($_SESSION['admin_role'] ?? 'admin') !== 'admin') {
    http_response_code(403);
    exit('Administrator access required.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Security token expired.');
}

$entity = $_POST['entity'] ?? '';
$id = $_POST['id'] ?? '';
$entities = attendance_delete_entities();
if ($entity !== 'reset' && (!isset($entities[$entity]) || !ctype_digit((string) $id) || (int) $id < 1)) {
    http_response_code(400);
    exit('Invalid export target.');
}
if ($entity === 'reset') $id = 'all';

$backup = [
    'format' => 'attendance-monitor-backup-v1',
    'generated_at' => date(DATE_ATOM),
    'generated_by_admin_id' => (int) $_SESSION['admin_id'],
    'deletion_target' => ['entity' => $entity, 'id' => $id],
    'database' => [],
];

foreach (attendance_backup_tables() as $table) {
    $result = pg_query($conn, 'SELECT row_to_json(record) payload FROM (SELECT * FROM ' . $table . ') record');
    if (!$result) {
        http_response_code(500);
        exit('Backup failed while reading ' . h($table) . '. Nothing was deleted.');
    }
    $rows = [];
    while ($row = pg_fetch_assoc($result)) $rows[] = json_decode($row['payload'], true);
    $backup['database'][$table] = $rows;
}

$temporary = tempnam(sys_get_temp_dir(), 'ams-backup-');
if (!$temporary) {
    http_response_code(500);
    exit('Could not create the backup file. Nothing was deleted.');
}
$zipPath = $temporary . '.zip';
@unlink($temporary);
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    exit('Could not create the backup archive. Nothing was deleted.');
}
$json = json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$zip->addFromString('database.json', $json ?: '{}');
$root = dirname(__DIR__, 2);
foreach (['storage/evidence', 'storage/receipts'] as $relativeDirectory) {
    $directory = $root . '/' . $relativeDirectory;
    if (!is_dir($directory)) continue;
    foreach (new DirectoryIterator($directory) as $file) {
        if (!$file->isFile() || $file->getFilename() === '.htaccess') continue;
        $zip->addFile($file->getPathname(), $relativeDirectory . '/' . $file->getFilename());
    }
}
$zip->addFromString('RESTORE-NOTICE.txt', "This archive contains sensitive attendance and account data. Store it securely.\r\nThe database.json file is a complete logical snapshot; uploaded evidence and receipts are included in their storage folders.\r\n");
$zip->close();

remember_deletion_export($entity, $id);
audit_log($conn, 'deletion_backup_exported', $entity, $id, ['archive' => basename($zipPath)]);
session_write_close();
$filename = 'attendance-backup-' . safe_backup_filename($entity . '-' . $id) . '-' . date('Ymd-His') . '.zip';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($zipPath));
header('Cache-Control: private, no-store');
readfile($zipPath);
@unlink($zipPath);
exit;
