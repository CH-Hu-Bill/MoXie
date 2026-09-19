<?php
/**
 * ============================================================
 * AI 调用封装（按班级配置）
 * ============================================================
 *
 * 支持两种端点协议：
 *   - openai    : OpenAI 兼容 /chat/completions（Bearer 鉴权）
 *   - anthropic : Anthropic /v1/messages（x-api-key 鉴权）
 *
 * 配置存储：data/settings.json 的 ai_{classId} = [
 *   'provider' => 'openai' | 'anthropic',
 *   'endpoint' => 'https://...',   // 允许只填域名，自动补全路径
 *   'api_key'  => '...',
 *   'model'    => '...',
 * ]
 *
 * 使用方式:
 *   require_once 'inc/api.php';
 *   $r = AIClient::call($classId, $messages, $maxTokens, $timeout);
 *   // 未配置时返回 ['success'=>false, 'error'=>'AI 服务不可用...']
 *
 * 返回值:
 *   成功 → ['success' => true, 'content' => '...']
 *   失败 → ['success' => false, 'error' => '...']
 * ============================================================
 */

require_once __DIR__ . '/db.php';

class AIClient
{
    /** 读取并校验某班级的 AI 配置，缺失/不完整返回 null */
    public static function getConfig($classId)
    {
        if (!$classId) return null;
        $settings = Database::getSettings();
        $cfg = $settings['ai_' . $classId] ?? null;
        if (!is_array($cfg)) return null;

        $provider = (string)($cfg['provider'] ?? '');
        if (!in_array($provider, ['openai', 'anthropic'], true)) return null;

        $endpoint = self::normalizeEndpoint($provider, trim((string)($cfg['endpoint'] ?? '')));
        $apiKey   = trim((string)($cfg['api_key'] ?? ''));
        $model    = trim((string)($cfg['model'] ?? ''));
        if ($endpoint === '' || $apiKey === '' || $model === '') return null;

        return ['provider' => $provider, 'endpoint' => $endpoint, 'api_key' => $apiKey, 'model' => $model];
    }

    /** 该班级是否已配置可用 AI */
    public static function isConfigured($classId)
    {
        return self::getConfig($classId) !== null;
    }

    /** 统一返回"未配置"错误 */
    public static function unavailableError()
    {
        return ['success' => false, 'error' => 'AI 服务不可用：请先在「设置 → AI 设置」中配置接口地址、密钥与模型'];
    }

    /** 按班级调用 */
    public static function call($classId, $messages, $maxTokens = 4096, $timeout = 60)
    {
        $cfg = self::getConfig($classId);
        if ($cfg === null) return self::unavailableError();
        return self::callConfig($cfg, $messages, $maxTokens, $timeout);
    }

    /** 用给定配置调用（供测试未保存的配置） */
    public static function callConfig($cfg, $messages, $maxTokens = 4096, $timeout = 60)
    {
        if ($cfg['provider'] === 'anthropic') {
            return self::callAnthropic($cfg, $messages, $maxTokens, $timeout);
        }
        return self::callOpenAI($cfg, $messages, $maxTokens, $timeout);
    }

    /** 测试连接：发送一个极小请求 */
    public static function test($classId)
    {
        $cfg = self::getConfig($classId);
        if ($cfg === null) return ['success' => false, 'error' => 'AI 配置不完整'];
        return self::testConfig($cfg);
    }

    /** 用给定配置测试连接 */
    public static function testConfig($cfg)
    {
        $r = self::callConfig($cfg, [
            ['role' => 'system', 'content' => 'You are a connection tester.'],
            ['role' => 'user', 'content' => 'Reply with exactly: OK'],
        ], 16, 20);
        if (!$r['success']) return $r;
        return ['success' => true, 'message' => '连接成功'];
    }

    /** 端点容错：允许只填域名或省略 /v1 等路径 */
    public static function normalizeEndpoint($provider, $endpoint)
    {
        $endpoint = rtrim($endpoint, "/ \t\n\r");
        if ($endpoint === '') return '';
        if ($provider === 'anthropic') {
            if (preg_match('#/messages$#i', $endpoint)) return $endpoint;
            if (preg_match('#/v1$#i', $endpoint)) return $endpoint . '/messages';
            return $endpoint . '/v1/messages';
        }
        // openai
        if (preg_match('#/chat/completions$#i', $endpoint)) return $endpoint;
        if (preg_match('#/v1$#i', $endpoint)) return $endpoint . '/chat/completions';
        return $endpoint . '/v1/chat/completions';
    }

    private static function callOpenAI($cfg, $messages, $maxTokens, $timeout)
    {
        $body = [
            'model'       => $cfg['model'],
            'messages'    => $messages,
            'temperature' => 0.3,
            'max_tokens'  => $maxTokens,
        ];
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $cfg['api_key'],
        ];
        return self::request($cfg['endpoint'], $headers, $body, $timeout, function ($data) {
            return $data['choices'][0]['message']['content'] ?? '';
        });
    }

    private static function callAnthropic($cfg, $messages, $maxTokens, $timeout)
    {
        $system = '';
        $convo = [];
        foreach ($messages as $m) {
            $role = $m['role'] ?? 'user';
            if ($role === 'system') {
                $system .= ($system === '' ? '' : "\n") . ($m['content'] ?? '');
            } else {
                $convo[] = ['role' => $role, 'content' => $m['content'] ?? ''];
            }
        }
        $body = [
            'model'      => $cfg['model'],
            'max_tokens' => $maxTokens,
            'messages'   => $convo,
        ];
        if ($system !== '') $body['system'] = $system;

        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . $cfg['api_key'],
            'anthropic-version: 2023-06-01',
        ];
        return self::request($cfg['endpoint'], $headers, $body, $timeout, function ($data) {
            if (isset($data['content'][0]['text'])) return $data['content'][0]['text'];
            return '';
        });
    }

    private static function request($endpoint, $headers, $body, $timeout, $extract)
    {
        if (!function_exists('curl_init')) {
            return ['success' => false, 'error' => '服务器未启用 cURL 扩展，无法调用 AI'];
        }
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['success' => false, 'error' => 'AI 服务连接失败，请检查接口地址或网络'];
        }
        if ($httpCode !== 200) {
            $msg = 'AI 服务暂时不可用 (HTTP ' . $httpCode . ')';
            $err = json_decode($response, true);
            if (is_array($err)) {
                if (isset($err['error']['message'])) {
                    $msg .= '：' . $err['error']['message'];
                } elseif (isset($err['error']) && is_string($err['error'])) {
                    $msg .= '：' . $err['error'];
                } elseif (isset($err['message'])) {
                    $msg .= '：' . $err['message'];
                }
            }
            return ['success' => false, 'error' => mb_substr($msg, 0, 300)];
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            return ['success' => false, 'error' => 'AI 返回格式解析失败'];
        }
        $content = $extract($data);
        if ($content === '' || $content === null) {
            return ['success' => false, 'error' => 'AI 返回内容为空'];
        }
        // 剥离 markdown 代码块
        if (preg_match('/```(?:json)?\s*\n?(.*?)\n?```/s', $content, $m)) {
            $content = $m[1];
        }
        return ['success' => true, 'content' => $content];
    }
}
