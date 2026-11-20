<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../../web/inc/security.php';

checkSame(null, lwAllowedOrigin('', []), 'no origin');
checkSame(null, lwAllowedOrigin('https://a.test', []), 'empty allowlist denies');
checkSame('*', lwAllowedOrigin('https://a.test', ['*']), 'wildcard');
checkSame('https://a.test', lwAllowedOrigin('https://a.test', ['https://a.test']), 'exact');
checkSame(null, lwAllowedOrigin('https://b.test', ['https://a.test']), 'other origin denied');

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
checkSame(true, isHttpsRequest(), 'trusted loopback proxy https');
$_SERVER['HTTP_HOST'] = 'example.test';
$_SERVER['HTTP_X_FORWARDED_HOST'] = '';
checkSame('https://example.test', lwBaseUrl(), 'base url uses forwarded https');
$_SERVER['REMOTE_ADDR'] = '8.8.8.8';
checkSame(false, isHttpsRequest(), 'untrusted forwarded proto ignored');

$example = require __DIR__ . '/../../web/inc/config.example.php';
checkSame([], $example['allowed_origins'], 'example allowlist empty');
checkSame(['127.0.0.1', '::1'], $example['trusted_proxies'], 'example trusted proxies');
check(($example['admin_password'] ?? '') === '', 'example has no usable password');
check(($example['admin_password_hash'] ?? '') === '', 'example has no usable hash');
done();
