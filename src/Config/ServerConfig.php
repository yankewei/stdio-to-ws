<?php

declare(strict_types=1);

namespace Yankewei\StdioToWs\Config;

/**
 * 服务器配置类
 */
final readonly class ServerConfig
{
    /**
     * @param string $command 要执行的命令
     * @param string $host 监听地址
     * @param int $port 监听端口
     * @param string|null $cwd 工作目录
     * @param array<string, string> $env 环境变量
     */
    public function __construct(
        public string $command,
        public string $host = '0.0.0.0',
        public int $port = 8080,
        public ?string $cwd = null,
        public array $env = [],
        public bool $debug = false,
        public bool $raw = false,  // 直接传输原始文本，不使用 Base64
    ) {
    }

    /**
     * 从命令行参数创建配置
     *
     * @param array<int, string> $argv 命令行参数
     * @return self
     */
    public static function fromArgv(array $argv): self
    {
        // 首先检查帮助选项
        foreach ($argv as $arg) {
            if ($arg === '--help' || $arg === '-h') {
                self::printUsage();
                exit(0);
            }
        }

        if (count($argv) < 2) {
            self::printUsage();
            exit(1);
        }

        $command = $argv[1];
        $host = '0.0.0.0';
        $port = 8080;
        $cwd = null;
        $env = [];
        $debug = false;
        $raw = false;

        for ($i = 2; $i < count($argv); $i++) {
            $arg = $argv[$i];

            if (str_starts_with($arg, '--host=')) {
                $host = substr($arg, 7);
            } elseif (str_starts_with($arg, '--port=')) {
                $port = (int) substr($arg, 7);
            } elseif (str_starts_with($arg, '--cwd=')) {
                $cwd = substr($arg, 6);
            } elseif (str_starts_with($arg, '--env=')) {
                $envPart = substr($arg, 6);
                $parts = explode('=', $envPart, 2);
                if (count($parts) === 2) {
                    $env[$parts[0]] = $parts[1];
                }
            } elseif ($arg === '--debug') {
                $debug = true;
            } elseif ($arg === '--raw') {
                $raw = true;
            }
        }

        return new self($command, $host, $port, $cwd, $env, $debug, $raw);
    }

    /**
     * 打印使用说明
     */
    private static function printUsage(): void
    {
        echo <<<USAGE
Usage: stdio-to-ws <command> [options]

Arguments:
  command              要执行的命令（例如："python3 script.py"）

Options:
  --host=<address>     监听地址（默认：0.0.0.0）
  --port=<number>      监听端口（默认：8080）
  --cwd=<path>         工作目录
  --env=<key=value>    环境变量（可多次使用）
  --debug              启用调试日志（默认只显示错误）
  --raw                直接传输原始文本，不编码（调试用）
  --help, -h           显示帮助信息

Examples:
  stdio-to-ws "php interactive.php"
  stdio-to-ws "python3 ai-bot.py" --port=3000
  stdio-to-ws "node server.js" --cwd=/app --env=NODE_ENV=production

USAGE;
    }
}
