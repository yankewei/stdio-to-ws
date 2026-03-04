<?php

declare(strict_types=1);

namespace Yankewei\StdioToWs\Server;

use Amp\ByteStream\WritableResourceStream;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Driver\SocketClientFactory;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer as HttpServer;
use Amp\Log\StreamHandler;
use Amp\Socket\InternetAddress;
use Amp\Socket\ResourceServerSocketFactory;
use Amp\Websocket\Server\Rfc6455Acceptor;
use Amp\Websocket\Server\Websocket;
use Amp\Websocket\Server\WebsocketClientHandler;
use Amp\Websocket\WebsocketClient;
use Monolog\Level;
use Monolog\Logger;
use Revolt\EventLoop;
use Yankewei\StdioToWs\Config\ServerConfig;
use Yankewei\StdioToWs\Process\ProcessManager;

/**
 * WebSocket 服务器主类
 * 实现 WebsocketClientHandler 接口处理客户端连接
 */
final class WebSocketServer implements WebsocketClientHandler
{
    private HttpServer $httpServer;
    private ProcessManager $processManager;
    private Websocket $websocket;

    public function __construct(
        private readonly ServerConfig $config,
    ) {
        $this->processManager = new ProcessManager();
    }

    /**
     * 启动服务器
     */
    public function run(): void
    {
        // 注册信号处理器
        $this->registerSignalHandlers();

        // 创建日志器（debug 模式显示所有日志，否则只显示错误）
        $logger = new Logger('stdio-to-ws');
        $logLevel = $this->config->debug ? Level::Debug : Level::Error;
        $logger->pushHandler(new StreamHandler(new WritableResourceStream(STDOUT), $logLevel));

        // 创建 HTTP 服务器
        $this->httpServer = new HttpServer(
            $logger,
            new ResourceServerSocketFactory(),
            new SocketClientFactory($logger),
        );

        // 创建 WebSocket 接受器
        $acceptor = new Rfc6455Acceptor();

        // 创建 WebSocket 处理器
        $this->websocket = new Websocket(
            $this->httpServer,
            $logger,
            $acceptor,
            $this, // 当前类实现 WebsocketClientHandler
        );

        // 暴露服务器端口
        $port = max(0, min(65535, $this->config->port));
        $this->httpServer->expose(new InternetAddress($this->config->host, $port));
        $this->httpServer->start($this->websocket, new DefaultErrorHandler());

        echo sprintf(
            "stdio-to-ws server started on ws://%s:%d\n",
            $this->config->host,
            $this->config->port
        );
        echo sprintf("Command: %s\n", $this->config->command);
        echo "Press Ctrl+C to stop.\n\n";

        // 运行事件循环
        EventLoop::run();
    }

    /**
     * 处理新 WebSocket 连接（WebsocketClientHandler 接口方法）
     */
    public function handleClient(WebsocketClient $client, Request $request, Response $response): void
    {
        $handler = new ConnectionHandler(
            $client,
            $this->config,
            $this->processManager,
        );

        try {
            $handler->handle();
        } catch (\Throwable $e) {
            echo 'Connection error: ' . $e->getMessage() . "\n";
        }
    }

    /**
     * 注册信号处理器
     */
    private function registerSignalHandlers(): void
    {
        // Ctrl+C / SIGTERM
        EventLoop::onSignal(\SIGINT, function (): void {
            $this->shutdown();
        });

        EventLoop::onSignal(\SIGTERM, function (): void {
            $this->shutdown();
        });
    }

    /**
     * 优雅关闭服务器
     */
    private function shutdown(): void
    {
        echo "\nShutting down server...\n";

        // 关闭所有子进程
        $this->processManager->closeAll();

        // 停止 HTTP 服务器
        if (isset($this->httpServer)) {
            $this->httpServer->stop();
        }

        echo "Server stopped.\n";
        exit(0);
    }
}
