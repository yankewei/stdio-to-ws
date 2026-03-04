<?php

declare(strict_types=1);

namespace Yankewei\StdioToWs\Tests\Unit\Router;

use PHPUnit\Framework\TestCase;
use Yankewei\StdioToWs\Config\ServerConfig;
use Yankewei\StdioToWs\Router\MessageRouter;

final class MessageRouterTest extends TestCase
{
    private function createRouter(bool $raw = false): MessageRouter
    {
        $config = new ServerConfig('cmd', raw: $raw);

        return new MessageRouter($config);
    }

    // ========== Base64 Mode Tests ==========

    public function testBuildStdoutMessageBase64(): void
    {
        $router = $this->createRouter(raw: false);
        $message = $router->buildStdoutMessage('hello');
        $decoded = json_decode($message, true);

        self::assertSame('stdout', $decoded['type']);
        self::assertSame(base64_encode('hello'), $decoded['data']);
    }

    public function testBuildStderrMessageBase64(): void
    {
        $router = $this->createRouter(raw: false);
        $message = $router->buildStderrMessage('error');
        $decoded = json_decode($message, true);

        self::assertSame('stderr', $decoded['type']);
        self::assertSame(base64_encode('error'), $decoded['data']);
    }

    public function testExtractStdinDataBase64(): void
    {
        $router = $this->createRouter(raw: false);
        $encoded = base64_encode("hello\n");
        $data = $router->extractStdinData(['type' => 'stdin', 'data' => $encoded]);

        self::assertSame("hello\n", $data);
    }

    public function testExtractStdinDataBase64Invalid(): void
    {
        $router = $this->createRouter(raw: false);
        // Invalid base64 should return original data
        $data = $router->extractStdinData(['type' => 'stdin', 'data' => 'not-valid-base64!!!']);

        self::assertSame('not-valid-base64!!!', $data);
    }

    // ========== Raw Mode Tests ==========

    public function testBuildStdoutMessageRaw(): void
    {
        $router = $this->createRouter(raw: true);
        $message = $router->buildStdoutMessage('hello');
        $decoded = json_decode($message, true);

        self::assertSame('stdout', $decoded['type']);
        self::assertSame('hello', $decoded['data']); // No base64 encoding
    }

    public function testExtractStdinDataRaw(): void
    {
        $router = $this->createRouter(raw: true);
        $data = $router->extractStdinData(['type' => 'stdin', 'data' => "hello\n"]);

        self::assertSame("hello\n", $data); // Direct, no decoding
    }

    // ========== Common Tests ==========

    public function testBuildExitMessage(): void
    {
        $router = $this->createRouter();
        $message = $router->buildExitMessage(0);
        $decoded = json_decode($message, true);

        self::assertSame('process_exit', $decoded['type']);
        self::assertSame(0, $decoded['code']);
    }

    public function testBuildPingMessage(): void
    {
        $router = $this->createRouter();
        $message = $router->buildPingMessage();
        $decoded = json_decode($message, true);

        self::assertSame('ping', $decoded['type']);
    }

    public function testBuildPongMessage(): void
    {
        $router = $this->createRouter();
        $message = $router->buildPongMessage();
        $decoded = json_decode($message, true);

        self::assertSame('pong', $decoded['type']);
    }

    public function testParseMessageValid(): void
    {
        $router = $this->createRouter();
        $result = $router->parseMessage('{"type":"stdin","data":"test"}');

        self::assertNotNull($result);
        self::assertSame('stdin', $result['type']);
        self::assertSame('test', $result['data']);
    }

    public function testParseMessageInvalidJson(): void
    {
        $router = $this->createRouter();
        $result = $router->parseMessage('not valid json');

        self::assertNull($result);
    }

    public function testParseMessageMissingType(): void
    {
        $router = $this->createRouter();
        $result = $router->parseMessage('{"data":"test"}');

        self::assertNull($result);
    }

    public function testExtractStdinDataWrongType(): void
    {
        $router = $this->createRouter();
        $data = $router->extractStdinData(['type' => 'stdout', 'data' => 'test']);

        self::assertNull($data);
    }

    public function testExtractStdinDataMissingData(): void
    {
        $router = $this->createRouter(raw: true);
        $data = $router->extractStdinData(['type' => 'stdin']);

        self::assertSame('', $data); // Empty string fallback
    }

    public function testParseMessageChineseCharacters(): void
    {
        $router = $this->createRouter();
        $chinese = '你好世界';
        $encoded = base64_encode($chinese);
        $result = $router->parseMessage("{\"type\":\"stdin\",\"data\":\"$encoded\"}");

        self::assertNotNull($result);
        self::assertSame($encoded, $result['data']);

        // Verify decoding works
        $decoded = $router->extractStdinData($result);
        self::assertSame($chinese, $decoded);
    }
}
