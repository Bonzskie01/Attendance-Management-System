<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/admin_layout.php';
require_once __DIR__ . '/config.php';
if (!is_admin_logged_in()) redirect('../../index.php');
if (($_SESSION['admin_role'] ?? 'admin') !== 'admin') { http_response_code(403); exit('Administrator access required.'); }

$error = '';
$message = $_GET['message'] ?? '';
$entities = attendance_delete_entities();
$focusEntity = isset($entities[$_GET['focus'] ?? '']) ? $_GET['focus'] : '';
$focusId = (int) ($_GET['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) $error = 'Security token expired.';
    else {
        $action = $_POST['action'] ?? '';
        if ($action === 'delete_record') {
            $entity = $_POST['entity'] ?? '';
            $id = (int) ($_POST['id'] ?? 0);
            $confirmation = trim($_POST['confirmation'] ?? '');
            $accountError = '';
            if ($entity === 'account' && $id === (int) $_SESSION['admin_id']) $accountError = 'You cannot delete the account currently signed in.';
            elseif ($entity === 'account' && $id > 0) {
                $accountResult = pg_query_params($conn, 'SELECT role FROM admins WHERE id=$1', [$id]);
                if (!$accountResult || !pg_num_rows($accountResult)) $accountError = 'Account was not found.';
                elseif (pg_fetch_result($accountResult, 0, 'role') === 'admin') {
                    $adminCount = pg_query($conn, "SELECT COUNT(*) FROM admins WHERE role='admin'");
                    if (!$adminCount || (int) pg_fetch_result($adminCount, 0, 0) <= 1) $accountError = 'The last administrator account cannot be deleted.';
                }
            }
            if (!isset($entities[$entity]) || $id < 1) $error = 'Invalid deletion target.';
            elseif ($accountError !== '') $error = $accountError;
            elseif ($confirmation !== 'DELETE') $error = 'Type DELETE exactly to confirm the record deletion.';
            elseif (!has_recent_deletion_export($entity, $id)) $error = 'Export this record’s safety backup first. Export authorization expires after 15 minutes.';
            else {
                $definition = $entities[$entity];
                $filesToRemove = [];
                if ($entity === 'payment') {
                    $paths = pg_query_params($conn, 'SELECT receipt_path path FROM payments WHERE id=$1 AND receipt_path IS NOT NULL', [$id]);
                } elseif ($entity === 'student') {
                    $paths = pg_query_params($conn, "SELECT receipt_path path FROM payments WHERE attendee_id=$1 AND receipt_path IS NOT NULL UNION ALL SELECT sa.time_in_evidence FROM session_attendance sa JOIN event_registrations r ON r.id=sa.registration_id WHERE r.attendee_id=$1 AND sa.time_in_evidence IS NOT NULL UNION ALL SELECT sa.time_out_evidence FROM session_attendance sa JOIN event_registrations r ON r.id=sa.registration_id WHERE r.attendee_id=$1 AND sa.time_out_evidence IS NOT NULL", [$id]);
                } elseif ($entity === 'event') {
                    $paths = pg_query_params($conn, "SELECT sa.time_in_evidence path FROM session_attendance sa JOIN event_sessions s ON s.id=sa.session_id WHERE s.event_id=$1 AND sa.time_in_evidence IS NOT NULL UNION ALL SELECT sa.time_out_evidence FROM session_attendance sa JOIN event_sessions s ON s.id=sa.session_id WHERE s.event_id=$1 AND sa.time_out_evidence IS NOT NULL", [$id]);
                } else $paths = false;
                if ($paths) while ($path = pg_fetch_assoc($paths)) $filesToRemove[] = $path['path'];
                pg_query($conn, 'BEGIN');
                if ($entity === 'department') pg_query_params($conn, 'DELETE FROM courses WHERE department_id=$1', [$id]);
                $deleted = pg_query_params($conn, 'DELETE FROM ' . $definition['table'] . ' WHERE id=$1', [$id]);
                if ($deleted && pg_affected_rows($deleted) === 1) {
                    pg_query($conn, 'COMMIT');
                    remove_exported_storage_files($filesToRemove);
                    consume_deletion_export($entity, $id);
                    audit_log($conn, 'record_deleted_after_export', $entity, $id, ['label' => $definition['label']]);
                    redirect('index.php?message=record-deleted');
                }
                pg_query($conn, 'ROLLBACK');
                $error = $definition['label'] . ' was not found or could not be deleted.';
            }
        } elseif ($action === 'reset_system') {
            $confirmation = trim($_POST['confirmation'] ?? '');
            if ($confirmation !== 'RESET ATTENDANCE DATA') $error = 'Type RESET ATTENDANCE DATA exactly to confirm.';
            elseif (!has_recent_deletion_export('reset', 'all')) $error = 'Download a complete system backup first. Reset authorization expires after 15 minutes.';
            else {
                pg_query($conn, 'BEGIN');
                $reset = pg_query($conn, 'TRUNCATE TABLE departments, courses, events, attendees RESTART IDENTITY CASCADE');
                if ($reset) {
                    pg_query($conn, 'COMMIT');
                    foreach (['evidence', 'receipts'] as $folder) {
                        $directory = dirname(__DIR__, 2) . '/storage/' . $folder;
                        if (!is_dir($directory)) continue;
                        foreach (new DirectoryIterator($directory) as $file) {
                            if ($file->isFile() && $file->getFilename() !== '.htaccess') @unlink($file->getPathname());
                        }
                    }
                    consume_deletion_export('reset', 'all');
                    audit_log($conn, 'operational_data_reset_after_export', 'system', 'attendance', ['preserved' => ['admins', 'settings', 'audit_logs', 'login_attempts']]);
                    redirect('index.php?message=system-reset');
                }
                pg_query($conn, 'ROLLBACK');
                $error = 'System reset failed. No operational data was deleted.';
            }
        }
    }
}

$sections = [
    'account' => ['Administrator and marshal accounts', "SELECT id, full_name || ' — ' || username title, CASE role WHEN 'admin' THEN 'Administrator account' ELSE 'Marshal account' END subtitle FROM admins ORDER BY CASE role WHEN 'admin' THEN 0 ELSE 1 END, full_name LIMIT 200"],
    'student' => ['Students', "SELECT id, full_name title, attendee_code subtitle FROM attendees ORDER BY created_at DESC LIMIT 200"],
    'event' => ['Events', "SELECT id, title, COALESCE(venue,'') subtitle FROM events ORDER BY start_at DESC LIMIT 200"],
    'department' => ['Department folders', "SELECT id, code || ' — ' || name title, 'Department folder' subtitle FROM departments ORDER BY name LIMIT 200"],
    'course' => ['Courses', "SELECT c.id, c.code || ' — ' || c.name title, d.code subtitle FROM courses c JOIN departments d ON d.id=c.department_id ORDER BY d.name,c.name LIMIT 200"],
    'fine' => ['Fines', "SELECT f.id, a.full_name || ' — ₱' || to_char(f.amount,'FM999999990.00') title, f.description subtitle FROM fines f JOIN attendees a ON a.id=f.attendee_id ORDER BY f.created_at DESC LIMIT 200"],
    'payment' => ['Payments', "SELECT p.id, a.full_name || ' — ₱' || to_char(p.amount,'FM999999990.00') title, p.status subtitle FROM payments p JOIN attendees a ON a.id=p.attendee_id ORDER BY p.submitted_at DESC LIMIT 200"],
    'officer' => ['Officer assignments', "SELECT o.id, a.full_name || ' — ' || o.position title, o.school_year subtitle FROM officer_assignments o JOIN attendees a ON a.id=o.attendee_id ORDER BY o.id DESC LIMIT 200"],
    'player' => ['Player assignments', "SELECT p.id, a.full_name || ' — ' || p.sport title, COALESCE(p.team,'') subtitle FROM player_assignments p JOIN attendees a ON a.id=p.attendee_id ORDER BY p.id DESC LIMIT 200"],
    'request' => ['Information requests', "SELECT r.id, a.full_name || ' — Information correction' title, r.status subtitle FROM student_information_requests r JOIN attendees a ON a.id=r.attendee_id ORDER BY r.created_at DESC LIMIT 200"],
];
$records = [];
foreach ($sections as $key => [$label, $sql]) { $result = pg_query($conn, $sql); $records[$key] = $result ? (pg_fetch_all($result) ?: []) : []; }
$token = csrf_token();
admin_page_start('Data Management', 'data');
?>
<div class="hero"><div><h2><i class="fas fa-database"></i> Data Management</h2><p>Export a recoverable backup before deleting individual records or resetting attendance data.</p></div></div>
<?php if($message):?><div class="ams-flash" data-swal-title="<?=h($message==='system-reset'?'Attendance data reset completed':'Record deleted after backup')?>"></div><?php endif;?>
<?php if($error):?><div class="alert error"><?=h($error)?></div><?php endif;?>
<div class="data-safety-banner"><i class="fas fa-shield-halved"></i><div><strong>Export-first protection is enabled</strong><p>Every deletion requires a fresh ZIP backup. A backup authorization is valid for 15 minutes and can be used only once.</p></div></div>
<div class="card danger-zone"><div class="panel-header"><div><h3><i class="fas fa-triangle-exclamation"></i> Reset all operational attendance data</h3><p class="help">Deletes students, events, sessions, attendance, fines, payments, departments, courses, officers, players, requests, receipts, and evidence. Administrator accounts, settings, login history, and audit logs are preserved.</p></div></div><div class="data-delete-actions"><button type="button" class="btn secondary" data-export-entity="reset" data-export-id="all" data-unlock="reset-all"><i class="fas fa-file-zipper"></i> 1. Download Complete Backup</button><form method="post" id="delete-reset-all" data-confirm data-confirm-title="Reset all attendance data?" data-confirm="This permanently deletes all operational attendance records after the backup." data-confirm-button="Reset Data"><?=csrf_field()?><input type="hidden" name="action" value="reset_system"><input name="confirmation" placeholder="Type RESET ATTENDANCE DATA" autocomplete="off" required><button class="btn danger" disabled><i class="fas fa-trash-can"></i> 2. Reset Attendance Data</button></form></div></div>
<div class="card"><div class="panel-header"><div><h3><i class="fas fa-list-check"></i> Individual record deletion</h3><p class="help">Open a category, export the safety backup, then delete the selected record.</p></div></div><div class="data-sections">
<?php foreach($sections as $key=>[$label,$sql]):?><details class="data-section" id="section-<?=h($key)?>" <?=$focusEntity===$key?'open':''?>><summary><span><i class="fas fa-folder"></i> <?=h($label)?></span><b><?=count($records[$key])?></b></summary><div class="data-record-list"><?php if(!$records[$key]):?><p class="empty">No records.</p><?php endif;?><?php foreach($records[$key] as $record):$unlock=$key.'-'.(int)$record['id'];$protected=$key==='account'&&(int)$record['id']===(int)$_SESSION['admin_id'];$focused=$focusEntity===$key&&$focusId===(int)$record['id'];?><article id="record-<?=h($unlock)?>" class="<?=$focused?'focused-record':''?>"><div><strong><?=h($record['title'])?></strong><small><?=h($record['subtitle']?:'')?><?=$protected?' · Current signed-in account — protected':''?></small></div><div class="data-delete-actions"><?php if($protected):?><span class="badge active"><i class="fas fa-shield-halved"></i> Cannot delete current account</span><?php else:?><button type="button" class="btn small secondary" data-export-entity="<?=h($key)?>" data-export-id="<?=(int)$record['id']?>" data-unlock="<?=h($unlock)?>"><i class="fas fa-download"></i> Export First</button><form method="post" id="delete-<?=h($unlock)?>" data-confirm data-confirm-title="Delete <?=h(strtolower($entities[$key]['label']))?>?" data-confirm="The exported ZIP is your recovery copy. Related records may also be removed." data-confirm-button="Delete Record"><?=csrf_field()?><input type="hidden" name="action" value="delete_record"><input type="hidden" name="entity" value="<?=h($key)?>"><input type="hidden" name="id" value="<?=(int)$record['id']?>"><input name="confirmation" value="DELETE" readonly aria-label="Deletion confirmation"><button class="btn small danger" disabled><i class="fas fa-trash"></i> Delete</button></form><?php endif;?></div></article><?php endforeach;?></div></details><?php endforeach;?>
</div></div>
<script>
document.querySelectorAll('[data-export-entity]').forEach(button=>button.addEventListener('click',async()=>{if(button.disabled)return;const original=button.innerHTML;button.disabled=true;button.innerHTML='<i class="fas fa-spinner fa-spin"></i> Preparing backup…';try{const body=new FormData();body.append('csrf_token',<?=json_encode($token)?>);body.append('entity',button.dataset.exportEntity);body.append('id',button.dataset.exportId);const response=await fetch('export.php',{method:'POST',body,credentials:'same-origin'});if(!response.ok)throw new Error(await response.text());const blob=await response.blob();const disposition=response.headers.get('Content-Disposition')||'';const match=disposition.match(/filename="([^"]+)"/);const objectUrl=URL.createObjectURL(blob);const link=document.createElement('a');link.href=objectUrl;link.download=match?match[1]:'attendance-backup.zip';document.body.appendChild(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(objectUrl),30000);const form=document.getElementById('delete-'+button.dataset.unlock);if(form){const deleteButton=form.querySelector('button[type="submit"],button:not([type])');if(deleteButton)deleteButton.disabled=false}button.innerHTML='<i class="fas fa-check"></i> Backup downloaded';button.classList.add('copied')}catch(error){button.disabled=false;button.innerHTML=original;Swal.fire({...amsSwalOptions(),icon:'error',title:'Backup failed',text:error.message||'Nothing was deleted.'})}}));
</script>
<?php admin_page_end(); ?>
