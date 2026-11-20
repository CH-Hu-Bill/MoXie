<?php
/**
 * 生成一个最小 Android Motion Photo fixture：
 * JPEG + XMP(GCamera:MicroVideoOffset) + 追加 MP4。
 *
 * 依赖 tests/php/fixtures/plain.jpg 与 live.mp4（由 ffmpeg 生成）。
 */
$dir = __DIR__ . '/fixtures';
$jpeg = file_get_contents($dir . '/plain.jpg');
$mp4 = file_get_contents($dir . '/live.mp4');
if ($jpeg === false || $mp4 === false) {
    fwrite(STDERR, "missing plain.jpg or live.mp4\n");
    exit(1);
}
if (substr($jpeg, 0, 2) !== "\xFF\xD8") {
    fwrite(STDERR, "plain.jpg is not JPEG\n");
    exit(1);
}
$offset = strlen($mp4);
$xmp = '<?xpacket begin="" id="W5M0MpCehiHzreSzNTczkc9d"?>'
    . '<x:xmpmeta xmlns:x="adobe:ns:meta/">'
    . '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
    . '<rdf:Description rdf:about="" xmlns:GCamera="http://ns.google.com/photos/1.0/camera/"'
    . ' GCamera:MicroVideo="1" GCamera:MicroVideoVersion="1"'
    . ' GCamera:MicroVideoOffset="' . $offset . '"'
    . ' GCamera:MicroVideoPresentationTimestampUs="0"/>'
    . '</rdf:RDF></x:xmpmeta><?xpacket end="w"?>';
$header = "http://ns.adobe.com/xap/1.0/\0";
$payload = $header . $xmp;
$app1 = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;
$motion = substr($jpeg, 0, 2) . $app1 . substr($jpeg, 2) . $mp4;
file_put_contents($dir . '/motion_sample.jpg', $motion);
echo "motion_sample.jpg=" . strlen($motion) . " offset=$offset\n";
