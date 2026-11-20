<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../../web/inc/update_apply.php';

$base = sys_get_temp_dir() . '/lw-upd-' . bin2hex(random_bytes(4));
$src = $base . '/src';
@mkdir($src . '/inc', 0777, true);
@mkdir($src . '/data', 0777, true);
@mkdir($src . '/apk', 0777, true);
file_put_contents($src . '/version.php', '<?php // version');
file_put_contents($src . '/inc/version.php', '<?php // inc version');
file_put_contents($src . '/admin.php', 'old');
file_put_contents($src . '/new.php', 'new');
file_put_contents($src . '/data/keep.json', '{}');
file_put_contents($src . '/apk/a.apk', 'apk');
file_put_contents($src . '/inc/config.php', 'secret');

$zip = $base . '/web.zip';
check(lwZipWebDir($src, $zip), 'zip created');
$extract = $base . '/extract';
check(lwSafeExtract($zip, $extract), 'extract ok');
$root = lwFindWebRoot($extract);
check($root !== null, 'web root found');
check(is_file($root . '/new.php'), 'new file extracted');
check(!is_file($root . '/data/keep.json'), 'data excluded from code zip');
check(!is_file($root . '/apk/a.apk'), 'apk excluded from code zip');
check(!is_file($root . '/inc/config.php'), 'config excluded from code zip');

$dst = $base . '/dst';
@mkdir($dst . '/inc', 0777, true);
file_put_contents($dst . '/admin.php', 'dst-old');
check(lwCopyTree($root, $dst, ['data', 'apk', 'backups', '.update', 'inc/config.php']), 'copy tree');
checkSame('old', file_get_contents($dst . '/admin.php'), 'admin overwritten by update');
check(is_file($dst . '/new.php'), 'new file copied');
check(is_dir($dst . '/data') === false, 'data not copied');
check(!is_file($dst . '/inc/config.php'), 'config not copied');

$backup = lwBackupWeb($src, $base . '/backups');
check(is_file($backup['code_zip']), 'backup code zip');
file_put_contents($src . '/admin.php', 'changed');
check(lwRestoreWeb($src, $backup, $src . '/data'), 'restore');
checkSame('old', file_get_contents($src . '/admin.php'), 'restored admin');

$badZip = $base . '/bad.zip';
$z = new ZipArchive();
$z->open($badZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$z->addFromString('../evil.txt', 'x');
$z->close();
checkSame(false, lwSafeExtract($badZip, $base . '/bad-extract'), 'zip traversal rejected');

lwRemoveTree($base);
done();
