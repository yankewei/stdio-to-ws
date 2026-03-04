<?php

declare(strict_types=1);

namespace Yankewei\StdioToWs\Router;

use Yankewei\StdioToWs\Config\ServerConfig;

/**
 * 消息路由器 - 处理消息编码/解码
 */
final class MessageRouter
{
    public function __construct(
        private readonly ServerConfig $config,
    ) {
    }

    /**
     * 构建 stdout 消息
     */
    public function buildStdoutMessage(string $data): string
    {
        return $this->buildMessage('stdout', $data);
    }

    /**
     * 构建 stderr 消息
     */
    public function buildStderrMessage(string $data): string
    {
        return $this->buildMessage('stderr', $data);
    }

    /**
     * 构建进程退出消息
     */
    public function buildExitMessage(int $code): string
    {
        return json_encode([
            'type' => 'process_exit',
            'code' => $code,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * 构建 ping/pong 消息
     */
    public function buildPingMessage(): string
    {
        return json_encode(['type' => 'ping'], JSON_THROW_ON_ERROR);
    }

    public function buildPongMessage(): string
    {
        return json_encode(['type' => 'pong'], JSON_THROW_ON_ERROR);
    }

    /**
     * 解析客户端消息
     *
     * @return array{type: string, data?: string}|null
     */
    public function parseMessage(string $message): ?array
    {
        try {
            /** @var mixed $data */
            $data = json_decode($message, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($data) || !isset($data['type']) || !is_string($data['type'])) {
                return null;
            }

            /** @var array{type: string, data?: string} $result */
            $result = $data;
            return $result;
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * 提取 stdin 数据
     * @param array<string, mixed> $message
     */
    public function extractStdinData(array $message): ?string
    {
        if (!isset($message['type']) || $message['type'] !== 'stdin') {
            return null;
        }

        $data = isset($message['data']) && is_string($message['data']) ? $message['data'] : '';
        
        // raw 模式：直接使用原始数据
        if ($this->config->raw) {
            return $data;
        }
        
        // Base64 模式：解码
        $decoded = base64_decode($data, true);
        return $decoded !== false ? $decoded : $data;
    }

    /**
     * 构建消息（内部方法）
     */
    private function buildMessage(string $type, string $data): string
    {
        // raw 模式：直接传输原始文本
        if ($this->config->raw) {
            return json_encode([
                'type' => $type,
                'data' => $data,
            ], JSON_THROW_ON_ERROR);
        }
        
        // 默认：Base64 编码
        return json_encode([
            'type' => $type,
            'data' => base64_encode($data),
        ], JSON_THROW_ON_ERROR);
    }
}
