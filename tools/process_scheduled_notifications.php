<?php
/** Existing cron entry point; campaign coordination and recipient delivery leases. */
if (PHP_SAPI!=='cli') { http_response_code(403); exit("CLI only.\n"); }
require dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/notification_delivery.php';
$pdo=db(); $processed=0; $sent=0; $failed=0; $logged=0;
$leaseCutoff=date('Y-m-d H:i:s',time()-900);
$rows=notification_rows($pdo,"SELECT id FROM notifications WHERE deleted_at IS NULL AND archived_at IS NULL AND
    ((status='Scheduled' AND scheduled_at IS NOT NULL AND scheduled_at<=NOW())
    OR (status='Sending' AND sending_started_at IS NOT NULL AND sending_started_at<?)) ORDER BY scheduled_at,id LIMIT 20",[$leaseCutoff]);
foreach ($rows as $row) {
    $result=notification_dispatch($pdo,(int)$row['id'],$leaseCutoff);
    if ($result['status']==='Skipped') continue;
    $processed++;
    if ($result['status']==='Sent') $sent++;
    elseif ($result['status']==='Logged') $logged++;
    else $failed++;
}
echo "Processed: $processed; sent: $sent; logged: $logged; failed: $failed\n";
