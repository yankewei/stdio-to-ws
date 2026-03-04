<?php

declare(strict_types=1);

namespace Yankewei\StdioToWs\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Yankewei\StdioToWs\Config\ServerConfig;

final class ServerConfigTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $config = new ServerConfig('php test.php');

        self::assertSame('php test.php', $config->command);
        self::assertSame('0.0.0.0', $config->host);
        self::assertSame(8080, $config->port);
        self::assertNull($config->cwd);
        self::assertSame([], $config->env);
        self::assertFalse($config->debug);
        self::assertFalse($config->raw);
    }

    public function testCustomValues(): void
    {
        $config = new ServerConfig(
            command: 'python3 app.py',
            host: '127.0.0.1',
            port: 3000,
            cwd: '/app',
            env: ['KEY' => 'value'],
            debug: true,
            raw: true,
        );

        self::assertSame('python3 app.py', $config->command);
        self::assertSame('127.0.0.1', $config->host);
        self::assertSame(3000, $config->port);
        self::assertSame('/app', $config->cwd);
        self::assertSame(['KEY' => 'value'], $config->env);
        self::assertTrue($config->debug);
        self::assertTrue($config->raw);
    }

    public function testFromArgvWithDefaults(): void
    {
        $config = ServerConfig::fromArgv(['script.php', 'php test.php']);

        self::assertSame('php test.php', $config->command);
        self::assertSame('0.0.0.0', $config->host);
        self::assertSame(8080, $config->port);
    }

    public function testFromArgvWithOptions(): void
    {
        $config = ServerConfig::fromArgv([
            'script.php',
            'python3 app.py',
            '--host=127.0.0.1',
            '--port=3000',
            '--cwd=/app',
            '--env=KEY1=value1',
            '--env=KEY2=value2',
            '--debug',
            '--raw',
        ]);

        self::assertSame('python3 app.py', $config->command);
        self::assertSame('127.0.0.1', $config->host);
        self::assertSame(3000, $config->port);
        self::assertSame('/app', $config->cwd);
        self::assertSame(['KEY1' => 'value1', 'KEY2' => 'value2'], $config->env);
        self::assertTrue($config->debug);
        self::assertTrue($config->raw);
    }
}
