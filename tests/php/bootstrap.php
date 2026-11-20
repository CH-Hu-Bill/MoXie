<?php
$GLOBALS['__checks'] = 0;
$GLOBALS['__failures'] = 0;

function check($cond, string $msg): void {
    $GLOBALS['__checks']++;
    if (!$cond) {
        $GLOBALS['__failures']++;
        fwrite(STDERR, "FAIL: $msg\n");
    }
}

function checkSame($expected, $actual, string $msg): void {
    check($expected === $actual, $msg . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
}

function done(): void {
    echo "checks={$GLOBALS['__checks']} failures={$GLOBALS['__failures']}\n";
    exit($GLOBALS['__failures'] === 0 ? 0 : 1);
}
