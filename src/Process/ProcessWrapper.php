<?php

declare(strict_types=1);

namespace Yankewei\StdioToWs\Process;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\WritableStream;
use Amp\Process\Process;
use Revolt\EventLoop;

/**
 * 包装一个子进程，提供 stdin/stdout/stderr 的异步访问
 */
final class ProcessWrapper
{
    private ?Process $process = null;
    private ?WritableStream $stdin = null;
    /** @phpstan-ignore-next-line */
    private ?ReadableStream $stdout = null;
    /** @phpstan-ignore-next-line */
    private ?ReadableStream $stderr = null;

    /** @var callable|null 进程退出回调 */
    private $onExit = null;

    /** @var callable|null stdout 数据回调 */
    private $onStdout = null;

    /** @var callable|null stderr 数据回调 */
    private $onStderr = null;

    /**
     * @param array<string, string> $env
     */
    public function __construct(
        private readonly string $command,
        private readonly ?string $cwd = null,
        private readonly array $env = [],
    ) {
    }

    /**
     * 启动子进程
     */
    public function start(): void
    {
        $this->process = Process::start($this->command, $this->cwd, $this->env);

        $this->stdin = $this->process->getStdin();
        $stdout = $this->process->getStdout();
        $stderr = $this->process->getStderr();
        
        $this->stdout = $stdout;
        $this->stderr = $stderr;

        // 启动 stdout 读取协程
        EventLoop::queue(function () use ($stdout): void {
            try {
                while (null !== ($chunk = $stdout->read())) {
                    if ($this->onStdout !== null) {
                        ($this->onStdout)($chunk);
                    }
                }
            } catch (\Throwable $e) {
                // stdout 读取结束或出错
            }
        });

        // 启动 stderr 读取协程
        EventLoop::queue(function () use ($stderr): void {
            try {
                while (null !== ($chunk = $stderr->read())) {
                    if ($this->onStderr !== null) {
                        ($this->onStderr)($chunk);
                    }
                }
            } catch (\Throwable $e) {
                // stderr 读取结束或出错
            }
        });

        // 监控进程退出
        $process = $this->process;
        EventLoop::queue(function () use ($process): void {
            $exitCode = $process->join();
            if ($this->onExit !== null) {
                ($this->onExit)($exitCode);
            }
        });
    }

    /**
     * 向子进程写入数据
     */
    public function write(string $data): void
    {
        if ($this->stdin !== null && !$this->stdin->isClosed()) {
            $this->stdin->write($data);
        }
    }

    /**
     * 关闭 stdin（发送 EOF）
     */
    public function closeStdin(): void
    {
        if ($this->stdin !== null && !$this->stdin->isClosed()) {
            $this->stdin->close();
        }
    }

    /**
     * 强制终止子进程
     */
    public function kill(): void
    {
        if ($this->process !== null) {
            $this->process->kill();
        }
    }

    /**
     * 检查进程是否仍在运行
     */
    public function isRunning(): bool
    {
        return $this->process !== null && $this->process->isRunning();
    }

    /**
     * 获取进程 PID
     */
    public function getPid(): int
    {
        return $this->process?->getPid() ?? 0;
    }

    /**
     * 设置 stdout 数据回调
     */
    public function onStdout(callable $callback): void
    {
        $this->onStdout = $callback;
    }

    /**
     * 设置 stderr 数据回调
     */
    public function onStderr(callable $callback): void
    {
        $this->onStderr = $callback;
    }

    /**
     * 设置进程退出回调
     */
    public function onExit(callable $callback): void
    {
        $this->onExit = $callback;
    }
}
