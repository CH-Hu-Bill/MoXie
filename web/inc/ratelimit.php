<?php
/**
 * ============================================================
 * 持久限流器 (Rate Limiter)
 * ============================================================
 *
 * 基于 JSON 文件实现滑动窗口限流，支持跨请求持久化。
 * 使用 Database::update 原子写入，保证并发安全。
 *
 * 使用方式:
 *   require_once 'inc/ratelimit.php';
 *   list($allow, $retry) = RateLimiter::check('login', $ip, 10, 300);
 *   if (!$allow) {
 *       header('Retry-After: ' . $retry);
 *       appError('操作过于频繁，请' . $retry . '秒后再试', 'RATE_LIMITED', 429);
 *   }
 *
 * 数据存储: data/ratelimit.json
 *   { "bucket:hash16": {"count": int, "window_start": int}, ... }
 * ============================================================
 */

require_once __DIR__ . '/db.php';

class RateLimiter
{
    /**
     * 检查是否允许请求，并在允许时递增计数。
     *
     * @param string $bucket    限流桶名称 (如 'login', 'ai')
     * @param string $identity  限流标识 (如 IP 地址、用户ID)
     * @param int    $max       窗口内最大允许次数
     * @param int    $windowSec 窗口时长 (秒)
     * @return array [bool 是否允许, int 剩余秒数 (不允许时距重置的秒数)]
     */
    public static function check($bucket, $identity, $max, $windowSec)
    {
        $bucket = (string)$bucket;
        $identity = (string)$identity;
        $max = (int)$max;
        $windowSec = (int)$windowSec;

        if ($max <= 0 || $windowSec <= 0) {
            return [true, 0];
        }

        // 构造限流 key: bucket:sha1(identity)前16位
        $key = $bucket . ':' . substr(sha1($identity), 0, 16);
        $now = time();

        $allow = false;
        $retryAfter = 0;

        Database::update('ratelimit.json', function($data) use ($key, $max, $windowSec, $now, &$allow, &$retryAfter) {
            if (!is_array($data)) $data = [];

            // 清理过期 key (超过 2 倍窗口时长未使用)
            $expireThreshold = $now - $windowSec * 2;
            foreach ($data as $k => $record) {
                if (!is_array($record)) {
                    unset($data[$k]);
                    continue;
                }
                $ws = (int)($record['window_start'] ?? 0);
                if ($ws < $expireThreshold) {
                    unset($data[$k]);
                }
            }

            // 获取当前 key 的记录
            $record = $data[$key] ?? null;
            $windowStart = is_array($record) ? (int)($record['window_start'] ?? 0) : 0;
            $count = is_array($record) ? (int)($record['count'] ?? 0) : 0;

            // 窗口已过期则重置
            if ($windowStart === 0 || ($now - $windowStart) >= $windowSec) {
                $windowStart = $now;
                $count = 0;
            }

            // 检查是否允许
            if ($count < $max) {
                $count++;
                $allow = true;
                $data[$key] = ['count' => $count, 'window_start' => $windowStart];
            } else {
                $allow = false;
                // 剩余秒数 = 窗口重置时间 - 当前时间
                $retryAfter = max(1, ($windowStart + $windowSec) - $now);
                // 保持原有记录不变
                $data[$key] = ['count' => $count, 'window_start' => $windowStart];
            }

            return $data;
        });

        return [$allow, $retryAfter];
    }
}
