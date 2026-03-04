# stdio-to-ws

将命令行程序的 stdio（标准输入输出）包装成 WebSocket 服务，供其他程序调用。

## 特性

- **每个客户端独立子进程** - WebSocket 客户端连接时，独立启动一个子进程
- **双向实时通信** - WebSocket 消息转发到子进程 stdin，子进程输出实时推送到 WebSocket
- **异步高性能** - 基于 AMPHP 和 PHP 8.1+ Fibers

## 安装

```bash
composer install
```

## 开发

### 运行测试

```bash
# 运行所有测试
composer run test

# 仅运行单元测试
composer run test-unit

# 仅运行集成测试
composer run test-integration

# 生成覆盖率报告
vendor/bin/phpunit --coverage-html coverage-html
```

### 运行静态分析 (PHPStan max level)

```bash
composer run analyse
# 或
vendor/bin/phpstan analyse --memory-limit=512M
```

### 一键检查（测试 + 静态分析）

```bash
composer run check
```

## 使用方法

### 基本用法

```bash
php bin/stdio-to-ws "php examples/echo-server.php"
```

### 完整参数

```bash
php bin/stdio-to-ws "python3 script.py" \
    --host=0.0.0.0 \
    --port=8080 \
    --cwd=/path/to/workdir \
    --env=KEY1=value1 \
    --env=KEY2=value2 \
    --debug
```

### 参数说明

| 参数 | 说明 | 默认值 |
|------|------|--------|
| `command` | 要执行的命令（必需） | - |
| `--host` | 监听地址 | `0.0.0.0` |
| `--port` | 监听端口 | `8080` |
| `--cwd` | 工作目录 | 当前目录 |
| `--env` | 环境变量（可多次使用） | - |
| `--debug` | 启用调试日志，显示通信内容 | 关闭 |
| `--raw` | 直接传输原始文本，不 Base64 编码 | 关闭 |

## WebSocket 协议

连接建立后，使用 JSON 格式消息通信：

### 客户端 -> 服务器（写入子进程 stdin）

```json
{"type": "stdin", "data": "base64_encoded_data"}
```

### 服务器 -> 客户端（子进程输出）

```json
{"type": "stdout", "data": "base64_encoded_data"}
{"type": "stderr", "data": "base64_encoded_data"}
{"type": "process_exit", "code": 0}
```

### 心跳

```json
{"type": "ping"}  // 客户端发送
{"type": "pong"}  // 服务器响应
```

## 示例

### 1. 启动 echo 服务器

```bash
php bin/stdio-to-ws "php examples/echo-server.php" --port=3000
```

### 2. 使用浏览器客户端测试

打开 `examples/client.html`，在浏览器中连接 `ws://localhost:3000` 进行测试。

### 3. 简单的 Python 交互程序

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

## 架构

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
│ (独立子进程) │                │ (独立子进程) │
└─────────────┘                └─────────────┘
```

每个 WebSocket 连接对应一个独立的子进程，完全隔离。

## 依赖

- PHP >= 8.1
- AMPHP 生态库
  - `amphp/amp`
  - `amphp/websocket-server`
  - `amphp/process`
  - `amphp/byte-stream`

## 许可证

MIT
