<?php

function attendance_backup_tables(): array {
    return [
        'admins', 'settings', 'audit_logs', 'login_attempts',
        'departments', 'courses', 'attendees', 'events', 'event_sessions',
        'event_registrations', 'attendance_records', 'session_attendance',
        'student_information_requests', 'fines', 'payments',
        'officer_assignments', 'player_assignments',
    ];
}

function attendance_delete_entities(): array {
    return [
        'account' => ['table' => 'admins', 'label' => 'Administrator or marshal account'],
        'student' => ['table' => 'attendees', 'label' => 'Student'],
        'event' => ['table' => 'events', 'label' => 'Event'],
        'department' => ['table' => 'departments', 'label' => 'Department folder'],
        'course' => ['table' => 'courses', 'label' => 'Course'],
        'fine' => ['table' => 'fines', 'label' => 'Fine'],
        'payment' => ['table' => 'payments', 'label' => 'Payment'],
        'officer' => ['table' => 'officer_assignments', 'label' => 'Officer assignment'],
        'player' => ['table' => 'player_assignments', 'label' => 'Player assignment'],
        'request' => ['table' => 'student_information_requests', 'label' => 'Information request'],
    ];
}

function deletion_export_key(string $entity, $id): string {
    return $entity . ':' . (string) $id;
}

function remember_deletion_export(string $entity, $id): void {
    $_SESSION['deletion_exports'] = $_SESSION['deletion_exports'] ?? [];
    $_SESSION['deletion_exports'][deletion_export_key($entity, $id)] = time() + 900;
}

function has_recent_deletion_export(string $entity, $id): bool {
    $key = deletion_export_key($entity, $id);
    $expires = (int) ($_SESSION['deletion_exports'][$key] ?? 0);
    return $expires >= time();
}

function consume_deletion_export(string $entity, $id): void {
    unset($_SESSION['deletion_exports'][deletion_export_key($entity, $id)]);
}

function safe_backup_filename(string $value): string {
    $value = preg_replace('/[^A-Za-z0-9_-]+/', '-', $value);
    return trim((string) $value, '-') ?: 'attendance-data';
}

function remove_exported_storage_files(array $relativePaths): void {
    $projectRoot = realpath(dirname(__DIR__, 2));
    if (!$projectRoot) return;
    foreach (array_unique(array_filter($relativePaths)) as $relativePath) {
        $absolute = realpath($projectRoot . '/' . ltrim((string) $relativePath, '/\\'));
        if (!$absolute || !is_file($absolute)) continue;
        $evidenceRoot = realpath($projectRoot . '/storage/evidence');
        $receiptRoot = realpath($projectRoot . '/storage/receipts');
        $allowed = ($evidenceRoot && str_starts_with($absolute, $evidenceRoot . DIRECTORY_SEPARATOR))
            || ($receiptRoot && str_starts_with($absolute, $receiptRoot . DIRECTORY_SEPARATOR));
        if ($allowed) @unlink($absolute);
    }
}
