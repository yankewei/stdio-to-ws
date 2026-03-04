<?php

declare(strict_types=1);

namespace Yankewei\StdioToWs\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Yankewei\StdioToWs\Config\ServerConfig;
use Yankewei\StdioToWs\Router\MessageRouter;

/**
 * End-to-end integration tests
 * Tests complete message flow without actual WebSocket/Process
 */
final class EndToEndTest extends TestCase
{
    public function testEchoServerMessageFlowBase64(): void
    {
        $config = new ServerConfig('php -r "echo fgets(STDIN);"', raw: false);
        $router = new MessageRouter($config);

        // Simulate client sending message
        $input = "hello\n";
        $encoded = base64_encode($input);
        $clientMessage = json_encode(['type' => 'stdin', 'data' => $encoded]);
        
        // Server parses it
        $parsed = $router->parseMessage($clientMessage);
        self::assertNotNull($parsed);
        
        // Server extracts data
        $extracted = $router->extractStdinData($parsed);
        self::assertSame($input, $extracted);
        
        // Server sends response (simulating process output)
        $output = "hello\n";
        $response = $router->buildStdoutMessage($output);
        
        // Client parses response
        $responseParsed = json_decode($response, true);
        self::assertSame('stdout', $responseParsed['type']);
        self::assertSame($output, base64_decode($responseParsed['data']));
    }

    public function testEchoServerMessageFlowRaw(): void
    {
        $config = new ServerConfig('php -r "echo fgets(STDIN);"', raw: true);
        $router = new MessageRouter($config);

        // Simulate client sending message (no encoding)
        $input = "hello\n";
        $clientMessage = json_encode(['type' => 'stdin', 'data' => $input]);
        
        // Server parses it
        $parsed = $router->parseMessage($clientMessage);
        self::assertNotNull($parsed);
        
        // Server extracts data (direct, no decoding)
        $extracted = $router->extractStdinData($parsed);
        self::assertSame($input, $extracted);
        
        // Server sends response
        $output = "hello\n";
        $response = $router->buildStdoutMessage($output);
        
        // Client parses response
        $responseParsed = json_decode($response, true);
        self::assertSame('stdout', $responseParsed['type']);
        self::assertSame($output, $responseParsed['data']); // Direct, no decoding needed
    }

    public function testPingPongFlow(): void
    {
        $config = new ServerConfig('cmd');
        $router = new MessageRouter($config);

        // Client sends ping
        $ping = json_encode(['type' => 'ping']);
        $parsed = $router->parseMessage($ping);
        self::assertSame('ping', $parsed['type']);

        // Server responds with pong
        $pong = $router->buildPongMessage();
        $pongParsed = json_decode($pong, true);
        self::assertSame('pong', $pongParsed['type']);
    }

    public function testProcessExitMessage(): void
    {
        $config = new ServerConfig('cmd');
        $router = new MessageRouter($config);

        // Server sends process exit
        $exit = $router->buildExitMessage(0);
        $exitParsed = json_decode($exit, true);
        
        self::assertSame('process_exit', $exitParsed['type']);
        self::assertSame(0, $exitParsed['code']);

        // Test non-zero exit code
        $exit = $router->buildExitMessage(1);
        $exitParsed = json_decode($exit, true);
        self::assertSame(1, $exitParsed['code']);
    }

    public function testStderrMessage(): void
    {
        $config = new ServerConfig('cmd');
        $router = new MessageRouter($config);

        $error = "Error: something went wrong\n";
        $stderr = $router->buildStderrMessage($error);
        $stderrParsed = json_decode($stderr, true);
        
        self::assertSame('stderr', $stderrParsed['type']);
        self::assertSame(base64_encode($error), $stderrParsed['data']);
    }

    public function testComplexWorkflow(): void
    {
        $config = new ServerConfig('php examples/echo-server.php', raw: true, debug: true);
        $router = new MessageRouter($config);

        $messages = [];

        // 1. Client connects and sends first message
        $msg1 = ['type' => 'stdin', 'data' => "hello\n"];
        $messages[] = ['dir' => 'in', 'data' => $msg1];

        // 2. Server responds with echoed output
        $response1 = $router->buildStdoutMessage("Received: hello\n");
        $messages[] = ['dir' => 'out', 'data' => json_decode($response1, true)];

        // 3. Client sends second message
        $msg2 = ['type' => 'stdin', 'data' => "world\n"];
        $messages[] = ['dir' => 'in', 'data' => $msg2];

        // 4. Server responds
        $response2 = $router->buildStdoutMessage("Received: world\n");
        $messages[] = ['dir' => 'out', 'data' => json_decode($response2, true)];

        // 5. Client disconnects, process exits
        $exit = $router->buildExitMessage(0);
        $messages[] = ['dir' => 'out', 'data' => json_decode($exit, true)];

        // Verify workflow
        self::assertSame('stdin', $messages[0]['data']['type']);
        self::assertSame('stdout', $messages[1]['data']['type']);
        self::assertSame('stdin', $messages[2]['data']['type']);
        self::assertSame('stdout', $messages[3]['data']['type']);
        self::assertSame('process_exit', $messages[4]['data']['type']);
    }

    public function testBinaryDataWithBase64(): void
    {
        $config = new ServerConfig('cmd', raw: false);
        $router = new MessageRouter($config);

        // Binary data with null bytes
        $binary = "hello\x00world\xff";
        $encoded = base64_encode($binary);
        
        // Send
        $msg = json_encode(['type' => 'stdin', 'data' => $encoded]);
        $parsed = $router->parseMessage($msg);
        $decoded = $router->extractStdinData($parsed);
        
        self::assertSame($binary, $decoded);
    }

    public function testUnicodeWithBase64(): void
    {
        $config = new ServerConfig('cmd', raw: false);
        $router = new MessageRouter($config);

        // Unicode characters
        $unicode = "Hello 世界 🌍\n";
        $encoded = base64_encode($unicode);
        
        // Send
        $msg = json_encode(['type' => 'stdin', 'data' => $encoded]);
        $parsed = $router->parseMessage($msg);
        $decoded = $router->extractStdinData($parsed);
        
        self::assertSame($unicode, $decoded);
    }
}
