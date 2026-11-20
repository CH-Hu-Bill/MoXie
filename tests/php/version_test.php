<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../../web/inc/version.php';

checkSame('2.0.1', LW_WEB_VERSION, 'web version');
checkSame(2, LW_API_VERSION, 'api version');
checkSame('2.0.0', LW_MIN_APP_VERSION, 'min app');
checkSame('2.0.0', LW_MIN_WEB_VERSION, 'min web');
$payload = lwVersionPayload();
checkSame('2.0.1', $payload['web_version'], 'payload web version');
checkSame(2, $payload['api_version'], 'payload api version');
done();
