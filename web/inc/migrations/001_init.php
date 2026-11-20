<?php
/**
 * 001：初始化 schema 版本。
 * 只确保数据目录存在，不做破坏性变更。
 */
return function (string $dataDir): void {
    if (!is_dir($dataDir)) {
        @mkdir($dataDir, 0750, true);
    }
};
