<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../../web/inc/migrations.php';

$dir = sys_get_temp_dir() . '/lw-mig-' . bin2hex(random_bytes(4));
checkSame(0, lwSchemaVersion($dir), 'fresh schema version');
$r1 = lwRunMigrations($dir);
checkSame(0, $r1['from'], 'first from');
checkSame(1, $r1['to'], 'first to');
checkSame([1], $r1['applied'], 'first applied');
$r2 = lwRunMigrations($dir);
checkSame([], $r2['applied'], 'second run no-op');
check(is_file($dir . '/schema.json'), 'schema file written');

@unlink($dir . '/schema.json');
@rmdir($dir);
done();
