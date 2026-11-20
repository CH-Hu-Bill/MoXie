<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../../web/inc/maintenance.php';

$dir = sys_get_temp_dir() . '/lw-maint-' . bin2hex(random_bytes(4));
checkSame(false, lwInMaintenance($dir), 'starts off');
lwSetMaintenance(true, 'updating', $dir);
checkSame(true, lwInMaintenance($dir), 'maintenance on');
check(is_file($dir . '/.maintenance'), 'flag file written');
lwWriteUpdateStatus(['stage' => 'running', 'message' => 'x'], $dir);
$status = lwReadUpdateStatus($dir);
checkSame('running', $status['stage'], 'status stage');
lwSetMaintenance(false, '', $dir);
checkSame(false, lwInMaintenance($dir), 'maintenance off');
@unlink($dir . '/.update/status.json');
@rmdir($dir . '/.update');
@rmdir($dir);
done();
