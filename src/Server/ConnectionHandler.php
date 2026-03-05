<?php

declare(strict_types=1);

namespace Yankewei\StdioToWs\Server;

use Amp\Websocket\WebsocketClient;
use Amp\Websocket\WebsocketClosedException;
use Revolt\EventLoop;
use Yankewei\StdioToWs\Config\ServerConfig;
use Yankewei\StdioToWs\Process\ProcessManager;
use Yankewei\StdioToWs\Process\ProcessWrapper;

/**
 * 处理单个 WebSocket 连接
 * 每个连接对应一个独立的子进程
 */
final class ConnectionHandler
{
    private string $connectionId;
    private ?ProcessWrapper $process = null;
    private bool $isClosing = false;

    public function __construct(
        private readonly WebsocketClient $client,
        private readonly ServerConfig $config,
        private readonly ProcessManager $processManager,
    ) {
        $this->connectionId = spl_object_hash($this->client);
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
        } catch (\Throwable $e) {
            if ($this->config->debug) {
                fwrite(STDERR, "[conn:{$this->connectionId}] error: {$e->getMessage()}\n");
            }
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

        // stdout -> WebSocket 客户端（透传）
        $process->onStdout(function (string $data): void {
            // 使用 EventLoop::queue 避免阻塞进程读取协程
            EventLoop::queue(function () use ($data): void {
                $this->sendToClient($data);
            });
            if ($this->config->debug) {
                fwrite(STDOUT, '[→ Client] stdout: ' . str_replace(["\n", "\r"], ['\n', '\r'], $data) . "\n");
            }
        });

        // stderr -> 父进程 stderr（带 connection ID 前缀）
        $process->onStderr(function (string $data): void {
            $lines = explode("\n", rtrim($data, "\n"));
            foreach ($lines as $line) {
                if ($line !== '') {
                    fwrite(STDERR, "[conn:{$this->connectionId}] {$line}\n");
                }
            }
        });

        // 进程退出时关闭 WebSocket 连接
        $process->onExit(function (int $code): void {
            if ($this->config->debug) {
                fwrite(STDOUT, "[conn:{$this->connectionId}] process_exit: code=$code\n");
            }
            EventLoop::queue(function (): void {
                $this->isClosing = true;
                $this->client->close();
            });
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

                // 忽略二进制消息，只处理文本
                if ($message->isBinary()) {
                    continue;
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
     * 处理客户端发来的消息（透传模式）
     */
    private function handleClientMessage(string $text): void
    {
        // 直接透传到子进程 stdin（确保以换行符结尾）
        if ($this->process !== null && ! $this->isClosing) {
            $data = $text;
            if (! str_ends_with($data, "\n")) {
                $data .= "\n";
            }
            $this->process->write($data);
            if ($this->config->debug) {
                fwrite(STDOUT, '[← Client] stdin: ' . str_replace(["\n", "\r"], ['\n', '\r'], $text) . "\n");
            }
        }
    }

    /**
     * 发送错误消息到客户端
     */
    private function sendErrorToClient(string $errorMessage): void
    {
        if ($this->isClosing || $this->client->isClosed()) {
            return;
        }

        $payload = json_encode(['type' => 'error', 'message' => $errorMessage]);
        if ($payload !== false) {
            $this->sendToClient($payload);
        }
    }

    /**
     * 发送消息到客户端
     */
    private function sendToClient(string $message): void
    {
        if ($this->isClosing || $this->client->isClosed()) {
            return;
        }

        // 使用 EventLoop::queue 异步发送，避免阻塞
        EventLoop::queue(function () use ($message): void {
            try {
                if ($this->isClosing || $this->client->isClosed()) {
                    return;
                }
                $this->client->sendText($message);
            } catch (WebsocketClosedException) {
                // 连接已关闭，忽略
            } catch (\Throwable $e) {
                if ($this->config->debug) {
                    fwrite(STDERR, "[conn:{$this->connectionId}] send error: {$e->getMessage()}\n");
                }
            }
        });
    }

    /**
     * 清理资源
     */
    private function cleanup(): void
    {
        $this->isClosing = true;

        if ($this->process === null) {
            return;
        }

        // 关闭进程 stdin，让进程知道客户端已断开
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
