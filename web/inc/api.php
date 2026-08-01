<?php
/**
 * ============================================================
 * DeepSeek API 调用封装
 * ============================================================
 *
 * 统一管理 DeepSeek API 调用逻辑，避免在多个 endpoint 中重复
 * curl 初始化、错误处理、markdown 代码块剥离等代码。
 *
 * 使用方式:
 *   require_once 'inc/api.php';
 *   $result = DeepSeekAPI::call($messages, $maxTokens, $timeout);
 *
 * 返回值:
 *   成功 → ['success' => true, 'content' => '...']
 *   失败 → ['success' => false, 'error' => '...']
 * ============================================================
 */
class DeepSeekAPI {
    /**
     * 调用 DeepSeek Chat API
     * @param array  $messages  消息数组 [['role'=>'system','content'=>'...'], ...]
     * @param int    $maxTokens 最大返回 token 数 (默认 4096)
     * @param int    $timeout   cURL 超时秒数 (默认 60)
     * @return array ['success'=>bool, 'content'=>'...'|'error'=>'...']
     */
    public static function call($messages, $maxTokens = 4096, $timeout = 60) {
        $config = require __DIR__ . '/config.php';
        $apiKey  = $config['deepseek']['api_key'] ?? '';
        $endpoint = $config['deepseek']['endpoint'] ?? 'https://api.deepseek.com/v1/chat/completions';
        $model   = $config['deepseek']['model'] ?? 'deepseek-chat';

        if (empty($apiKey) || strpos($apiKey, 'your-') === 0) {
            return ['success' => false, 'error' => '请先在 inc/config.php 中配置 DeepSeek API 密钥'];
        }

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model'       => $model,
                'messages'    => $messages,
                'temperature' => 0.3,
                'max_tokens'  => $maxTokens,
            ]),
            CURLOPT_TIMEOUT => $timeout,
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['success' => false, 'error' => 'AI 服务连接失败，请稍后重试'];
        }
        if ($httpCode !== 200) {
            return ['success' => false, 'error' => 'AI 服务暂时不可用 (HTTP ' . $httpCode . ')'];
        }

        $aiData  = json_decode($response, true);
        $content = $aiData['choices'][0]['message']['content'] ?? '';

        // 剥离 markdown 代码块
        if (preg_match('/```(?:json)?\s*\n?(.*?)\n?```/s', $content, $m)) {
            $content = $m[1];
        }

        return ['success' => true, 'content' => $content];
    }
}
