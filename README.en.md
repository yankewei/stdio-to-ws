# stdio-to-ws

English | [中文](README.md)

Wrap a command-line program's stdio (standard input/output) as a WebSocket service for other programs to consume.

Ideal for running the [OpenAI Codex](https://developers.openai.com/codex/) app-server or any other JSON-RPC over stdio programs.

## Features

- **Pure passthrough mode** - WebSocket messages are directly forwarded to the subprocess stdin, and subprocess stdout is directly returned to the WebSocket client
- **Independent subprocess per client** - Each WebSocket connection spawns an isolated subprocess
- **Async high performance** - Built on AMPHP and PHP 8.4+ Fibers

## Installation

```bash
composer install
```

## Development

### Running Tests

```bash
# Run all tests
composer run test

# Run unit tests only
composer run test-unit

# Run integration tests only
composer run test-integration

# Generate coverage report
vendor/bin/phpunit --coverage-html coverage-html
```

### Static Analysis (PHPStan max level)

```bash
composer run analyse
# or
vendor/bin/phpstan analyse --memory-limit=512M
```

### One-command Check (tests + static analysis)

```bash
composer run check
```

## Usage

### Basic Usage

```bash
php bin/stdio-to-ws "php examples/echo-server.php"
```

### Running Codex App Server

```bash
php bin/stdio-to-ws "codex app-server" --port=8080
```

### Full Parameters

```bash
php bin/stdio-to-ws "python3 script.py" \
    --host=0.0.0.0 \
    --port=8080 \
    --cwd=/path/to/workdir \
    --env=KEY1=value1 \
    --env=KEY2=value2 \
    --debug
```

### Parameter Reference

| Parameter | Description | Default |
|-----------|-------------|---------|
| `command` | Command to execute (required) | - |
| `--host` | Listen address | `0.0.0.0` |
| `--port` | Listen port | `8080` |
| `--cwd` | Working directory | Current directory |
| `--env` | Environment variables (can be used multiple times) | - |
| `--debug` | Enable debug logging | Disabled |

## WebSocket Protocol

**Pure passthrough mode**: WebSocket messages are forwarded directly without any wrapping.

### Data Flow

```
WebSocket Client ──as-is──▶ Subprocess stdin
Subprocess stdout ──as-is──▶ WebSocket Client
Subprocess stderr ──▶ Server stderr (with conn ID prefix for log collection)
```

### Example (Codex JSON-RPC)

Client sends:
```json
{"method": "initialize", "id": 0}
```

Server responds:
```json
{"id": 0, "result": {"protocolVersion": "2025-03-25"}}
```

**Note**: Messages are automatically terminated with a newline (`\n`), conforming to JSON Lines format.

### JavaScript Client Example

```javascript
const ws = new WebSocket('ws://localhost:8080');

ws.onopen = () => {
    // Initialize
    ws.send(JSON.stringify({
        method: 'initialize',
        id: 0,
        params: { clientInfo: { name: 'my_client', version: '1.0' } }
    }));
};

ws.onmessage = (event) => {
    const msg = JSON.parse(event.data);
    console.log('Received:', msg);
    
    // Handle response
    if (msg.id === 0 && msg.result) {
        // Initialization complete, start thread
        ws.send(JSON.stringify({
            method: 'thread/start',
            id: 1,
            params: { model: 'gpt-5.1-codex' }
        }));
    }
};
```

## Examples

### 1. Start Echo Server

```bash
php bin/stdio-to-ws "php examples/echo-server.php" --port=3000
```

### 2. Test with Browser Client

Open `examples/client.html` in your browser and connect to `ws://localhost:3000` to test.

### 3. Simple Python Interactive Program

```python
# script.py
while True:
    try:
        line = input()
        if line == "quit":
            break
        print(f"Echo: {line}")
    except EOFError:
        break
```

```bash
php bin/stdio-to-ws "python3 script.py"
```

## Architecture

```
WebSocket Client A               WebSocket Client B
       │                                │
       ▼                                ▼
┌─────────────┐                ┌─────────────┐
│ Connection  │                │ Connection  │
│ Handler A   │                │ Handler B   │
└──────┬──────┘                └──────┬──────┘
       │                              │
       ▼                              ▼
┌─────────────┐                ┌─────────────┐
│   Process   │                │   Process   │
│      A      │                │      B      │
│ (Isolated)  │                │ (Isolated)  │
└─────────────┘                └─────────────┘
```

Each WebSocket connection corresponds to an isolated subprocess.

## Dependencies

- PHP >= 8.4
- AMPHP ecosystem
  - `amphp/amp`
  - `amphp/websocket-server`
  - `amphp/process`
  - `amphp/byte-stream`

## License

MIT
