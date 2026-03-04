<?php

declare(strict_types=1);

namespace Yankewei\StdioToWs\Process;

/**
 * 管理多个子进程（每个 WebSocket 连接对应一个）
 */
final class ProcessManager
{
    /** @var array<string, ProcessWrapper> 连接ID => 进程 */
    private array $processes = [];

    /**
     * 为指定连接创建并启动子进程
     *
     * @param string $connectionId 连接唯一标识
     * @param string $command 要执行的命令
     * @param string|null $cwd 工作目录
     * @param array<string, string> $env 环境变量
     * @return ProcessWrapper
     */
    public function createProcess(
        string $connectionId,
        string $command,
        ?string $cwd = null,
        array $env = [],
    ): ProcessWrapper {
        $process = new ProcessWrapper($command, $cwd, $env);
        $this->processes[$connectionId] = $process;

        return $process;
    }

    /**
     * 获取指定连接的进程
     */
    public function getProcess(string $connectionId): ?ProcessWrapper
    {
        return $this->processes[$connectionId] ?? null;
    }

    /**
     * 关闭并清理指定连接的进程
     */
    public function removeProcess(string $connectionId): void
    {
        if (isset($this->processes[$connectionId])) {
            $process = $this->processes[$connectionId];
            if ($process->isRunning()) {
                $process->kill();
            }
            unset($this->processes[$connectionId]);
        }
    }

    /**
     * 关闭所有进程（用于服务器关闭时）
     */
    public function closeAll(): void
    {
        foreach ($this->processes as $connectionId => $process) {
            if ($process->isRunning()) {
                $process->kill();
            }
        }
        $this->processes = [];
    }

    /**
     * 获取当前管理的进程数量
     */
    public function count(): int
    {
        return count($this->processes);
    }
}
