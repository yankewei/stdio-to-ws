<?php

declare(strict_types=1);

namespace Yankewei\StdioToWs\Server;

use Amp\Websocket\WebsocketClient;
use Amp\Websocket\WebsocketClosedException;
use Revolt\EventLoop;
use Yankewei\StdioToWs\Config\ServerConfig;
use Yankewei\StdioToWs\Process\ProcessManager;
use Yankewei\StdioToWs\Process\ProcessWrapper;
use Yankewei\StdioToWs\Router\MessageRouter;

/**
 * 处理单个 WebSocket 连接
 * 每个连接对应一个独立的子进程
 */
final class ConnectionHandler
{
    private string $connectionId;
    private ?ProcessWrapper $process = null;
    private MessageRouter $messageRouter;

    public function __construct(
        private readonly WebsocketClient $client,
        private readonly ServerConfig $config,
        private readonly ProcessManager $processManager,
    ) {
        $this->connectionId = spl_object_hash($this->client);
        $this->messageRouter = new MessageRouter($config);
    }

    /**
     * 处理连接（启动进程，处理消息循环）
     */
    public function handle(): void
    {
        try {
            // 1. 启动子进程
            $process = $this->processManager->createProcess(
                $this->connectionId,
                $this->config->command,
                $this->config->cwd,
                $this->config->env,
            );
            $this->process = $process;

            // 2. 设置进程回调
            $this->setupProcessCallbacks();

            // 3. 启动进程
            $process->start();

            // 4. 处理 WebSocket 消息
            $this->handleWebSocketMessages();
        } finally {
            $this->cleanup();
        }
    }

    /**
     * 设置进程回调
     */
    private function setupProcessCallbacks(): void
    {
        if ($this->process === null) {
            return;
        }

        $process = $this->process;

        // stdout -> WebSocket 客户端
        $process->onStdout(function (string $data): void {
            $this->sendToClient($this->messageRouter->buildStdoutMessage($data));
            // debug 模式下打印通信记录
            if ($this->config->debug) {
                echo '[→ Client] stdout: ' . str_replace(["\n", "\r"], ['\n', '\r'], $data) . "\n";
            }
        });

        // stderr -> WebSocket 客户端
        $process->onStderr(function (string $data): void {
            $this->sendToClient($this->messageRouter->buildStderrMessage($data));
            if ($this->config->debug) {
                echo '[→ Client] stderr: ' . str_replace(["\n", "\r"], ['\n', '\r'], $data) . "\n";
            }
        });

        // 进程退出通知客户端
        $process->onExit(function (int $code): void {
            $this->sendToClient($this->messageRouter->buildExitMessage($code));
            if ($this->config->debug) {
                echo "[→ Client] process_exit: code=$code\n";
            }
            // 关闭 WebSocket 连接
            $this->client->close();
        });
    }

    /**
     * 处理 WebSocket 消息循环
     */
    private function handleWebSocketMessages(): void
    {
        while (true) {
            try {
                $message = $this->client->receive();

                if ($message === null) {
                    // 连接已关闭
                    break;
                }

                $text = $message->buffer();
                $this->handleClientMessage($text);
            } catch (WebsocketClosedException) {
                break;
            } catch (\Throwable $e) {
                // 发送错误消息给客户端
                $this->sendErrorToClient($e->getMessage());
            }
        }
    }

    /**
     * 处理客户端发来的消息
     */
    private function handleClientMessage(string $text): void
    {
        $message = $this->messageRouter->parseMessage($text);

        if ($message === null) {
            return;
        }

        match ($message['type']) {
            'stdin' => $this->handleStdinMessage($message),
            'ping' => $this->sendToClient($this->messageRouter->buildPongMessage()),
            'pong' => null, // 忽略客户端的 pong
            default => null,
        };
    }

    /**
     * 处理 stdin 消息
     * @param array<string, mixed> $message
     */
    private function handleStdinMessage(array $message): void
    {
        $data = $this->messageRouter->extractStdinData($message);
        if ($data !== null && $this->process !== null) {
            $this->process->write($data);
            if ($this->config->debug) {
                echo '[← Client] stdin: ' . str_replace(["\n", "\r"], ['\n', '\r'], $data) . "\n";
            }
        }
    }

    /**
     * 发送错误消息到客户端
     */
    private function sendErrorToClient(string $errorMessage): void
    {
        try {
            $payload = json_encode(['type' => 'error', 'message' => $errorMessage]);
            if ($payload !== false) {
                $this->sendToClient($payload);
            }
        } catch (\Throwable) {
            // 忽略发送错误
        }
    }

    /**
     * 发送消息到客户端
     */
    private function sendToClient(string $message): void
    {
        try {
            if ($this->client->isClosed()) {
                return;
            }
            $this->client->sendText($message);
        } catch (WebsocketClosedException) {
            // 连接已关闭，忽略
        }
    }

    /**
     * 清理资源
     */
    private function cleanup(): void
    {
        if ($this->process !== null) {
            $this->process->closeStdin();
            if ($this->process->isRunning()) {
                // 给进程一点优雅退出的时间
                EventLoop::delay(1, function (): void {
                    if ($this->process !== null && $this->process->isRunning()) {
                        $this->process->kill();
                    }
                    $this->processManager->removeProcess($this->connectionId);
                });
            } else {
                $this->processManager->removeProcess($this->connectionId);
            }
        }
    }
}
