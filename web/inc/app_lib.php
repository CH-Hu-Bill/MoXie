<?php
/**
 * APP API 的纯函数（可独立测试，不产生副作用）。
 */

/**
 * 给知识库条目补充 is_wrong 标记。
 *
 * @param array $words 已序列化的条目
 * @param array $wrongMap 错题 map (key=wordId)
 * @return array
 */
function appApplyWrongFlags(array $words, array $wrongMap): array {
    foreach ($words as $i => $w) {
        $words[$i]['is_wrong'] = isset($wrongMap[(string)($w['id'] ?? '')]);
    }
    return $words;
}
