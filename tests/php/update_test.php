<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../../web/inc/update.php';

$hash = str_repeat('a', 64);
$release = [
    'tag_name' => 'web-v2.1.0',
    'draft' => false,
    'prerelease' => false,
    'body' => '更新说明',
    'published_at' => '2026-11-20T00:00:00Z',
    'assets' => [
        [
            'name' => 'ListenWrite-web-2.1.0.zip',
            'browser_download_url' => 'https://github.com/CH-Hu-Bill/MoXie/releases/download/web-v2.1.0/ListenWrite-web-2.1.0.zip',
        ],
        [
            'name' => 'ListenWrite-web-2.1.0.sha256',
            'browser_download_url' => 'https://github.com/CH-Hu-Bill/MoXie/releases/download/web-v2.1.0/ListenWrite-web-2.1.0.sha256',
        ],
    ],
];
$parsed = lwParseWebRelease($release);
check($parsed !== null, 'valid release parsed');
checkSame('2.1.0', $parsed['version'], 'release version');
checkSame('web-v2.1.0', $parsed['tag'], 'release tag');

checkSame(null, lwParseWebRelease(['tag_name' => 'app-v2.1.0'] + $release), 'app tag rejected');
checkSame(null, lwParseWebRelease(['tag_name' => 'web-v2.1.0', 'assets' => []] + $release), 'missing assets rejected');

checkSame($hash, lwParseSha256("$hash  ListenWrite-web-2.1.0.zip\n"), 'sha parsed');
checkSame(null, lwParseSha256('not a hash'), 'bad sha rejected');

checkSame(true, lwIsAllowedAssetUrl('https://github.com/CH-Hu-Bill/MoXie/releases/download/web-v2.1.0/x.zip'), 'allowed asset');
checkSame(false, lwIsAllowedAssetUrl('http://github.com/CH-Hu-Bill/MoXie/releases/download/web-v2.1.0/x.zip'), 'http rejected');
checkSame(false, lwIsAllowedAssetUrl('https://evil.test/CH-Hu-Bill/MoXie/releases/download/web-v2.1.0/x.zip'), 'other host rejected');

done();
