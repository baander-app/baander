# Swoole Bundle

A Symfony bundle that runs your application on the [Swoole](https://www.swoole.co.uk/) async HTTP/WebSocket server. Enables coroutines, hot module reloading, service pooling, and Swoole task workers within the Symfony ecosystem.

## Requirements

- PHP 8.3+
- Swoole 6.2.0 (`ext-swoole`)
- Symfony 7.4 | 8.0

## Installation

```bash
composer require swoole-bundle/swoole-bundle
```

Enable the bundle in `config/bundles.php`:

```php
return [
    // ...
    SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\SwooleBundle::class => ['all' => true],
];
```

### Suggested packages

| Package | Purpose |
|---------|---------|
| `ext-inotify` | Hot Module Reloading (HMR) via filesystem watchers |
| `doctrine/orm` | Entity Manager stability checking and automatic resetting |
| `symfony/messenger` | Swoole task transport for async message handling |
| `swoole-bundle/z-engine` | Coroutine support (Fiber context) |

## Quick Start

```bash
# Run in foreground (development)
php bin/console swoole:server:run

# Start as daemon (production)
php bin/console swoole:server:start

# Reload workers (zero-downtime code deployment)
php bin/console swoole:server:reload

# Stop the daemon
php bin/console swoole:server:stop

# Check server status
php bin/console swoole:server:status
```

## Configuration

All configuration lives under the `swoole` key in your Symfony config.

### Minimal configuration

```yaml
# config/packages/swoole.yaml
swoole:
    http_server:
        host: '0.0.0.0'
        port: 9501
```

### Full reference

```yaml
swoole:
    http_server:
        host: '0.0.0.0'                    # Bind address
        port: 9501                           # Bind port
        trusted_hosts: []                    # Trusted host patterns
        trusted_proxies: []                  # Trusted proxy CIDRs (use ['*'] behind a reverse proxy)
        running_mode: process                # "process" | "reactor" | "thread"
        socket_type: tcp                     # "tcp" | "tcp_ipv6" | "udp" | "udp_ipv6" | "unix_dgram" | "unix_stream"
        ssl_enabled: false                   # Enable TLS on the main port

        hmr:
            enabled: 'auto'                  # "off" | "auto" | "inotify" | "external"
            file_path: '%swoole_bundle.cache_dir%'

        api:
            enabled: true                    # Management API server
            host: '127.0.0.1'
            port: 9200

        static:
            strategy: 'auto'                 # "off" | "default" | "advanced" | "auto"
            public_dir: '%kernel.project_dir%/public'
            mime_types: {}                   # File extension to MIME type overrides

        exception_handler:
            type: auto                       # "auto" | "json" | "production" | "symfony" | "custom"
            verbosity: auto                  # "auto" | "trace" | "verbose" | "default"
            handler_id: null                 # FQCN when type is "custom"

        services:
            trust_all_proxies_handler: false # Trust all proxies (sets Request::setTrustedProxies)
            cloudfront_proto_header_handler: false  # Extract X-Forwarded-Proto from CloudFront
            access_log:
                enabled: false
                format: null                 # null | "json" | strftime format string
                register_monolog_formatter_service: null
                monolog_formatter_service_name: null
                monolog_formatter_format: null

        settings:
            log_file: '%kernel.logs_dir%/swoole_%kernel.environment%.log'
            log_level: auto                  # "auto" | "debug" | "trace" | "info" | "notice" | "warning" | "error"
            pid_file: null
            buffer_output_size: 2097152      # 2 MB
            package_max_length: 8388608      # 8 MB
            worker_count: 1
            reactor_count: 1
            worker_max_request: 0            # Restart worker after N requests (0 = unlimited)
            worker_max_request_grace: null   # Extra requests allowed during drain
            heartbeat_check_interval: null   # Seconds between heartbeat checks
            upload_tmp_dir: /tmp
            user: null                       # Run workers as this system user
            group: null                      # Run workers as this system group
            http_compression: false
            http_compression_level: 4
            http_compression_types: []       # MIME types to compress

    task_worker:
        services:
            reset_handler: true              # Reset Symfony container state between tasks
        settings:
            worker_count: null               # Number of task workers

    platform:
        fiber_context:
            enabled: auto                    # "auto" | "off" | "on"
        coroutines:
            enabled: false
            max_coroutines: 100000
            max_concurrency: null
            max_service_instances: null
            stateful_services: []
            compile_processors: []
            doctrine_processor_config:
                global_limit: null
                limits: {}
```

## Running Modes

| Mode | Constant | Description |
|------|----------|-------------|
| `process` | `SWOOLE_PROCESS` | Multi-process mode. Each worker runs in its own process with full isolation. Default for production. |
| `reactor` | `SWOOLE_BASE` | Reactor mode. All connections handled in the main process. Lower latency, no process isolation. |
| `thread` | `SWOOLE_THREAD` | Thread mode. Workers run in threads instead of processes. Requires Swoole thread support. |

## Key Features

### Hot Module Reloading (HMR)

Automatically reloads workers when PHP files change. Three modes:

- **`inotify`** — Uses `ext-inotify` to watch the filesystem. Detects changes in real time.
- **`external`** — Tracks non-reloadable files (vendor, cache) so an external tool (e.g. `inotifywait`) can trigger reloads via the management API.
- **`auto`** — Enables `inotify` if the extension is loaded, otherwise `off`.

HMR is only active in debug mode.

### Static File Serving

The bundle can serve static files directly from Swoole, bypassing PHP entirely:

- **`off`** — All requests go through Symfony (use Nginx or another reverse proxy for static files).
- **`default`** — Basic static file serving via Swoole's built-in handler.
- **`advanced`** — Uses `sendfile` + `mmap` for zero-copy static file serving with MIME type detection. Recommended for production.
- **`auto`** — `advanced` in debug/non-prod, `off` in prod.

### Access Logging

Integrated access logging via Monolog. Supports two formatters:

- **Simple** — Plaintext log lines (configurable strftime format).
- **JSON** — Structured JSON log entries.

Logs are written to the `swoole.access_log` Monolog channel on `kernel.terminate`.

### Management API

An optional HTTP API for runtime server management, exposed on a separate port:

```php
use SwooleBundle\SwooleBundle\Server\Api\ApiServerClient;

$client = $container->get(ApiServerClient::class);

$status   = $client->status();    // GET  /api/server
$metrics  = $client->metrics();   // GET  /api/server/metrics
$client->reload();                // PATCH /api/server
$client->shutdown();              // DELETE /api/server
```

### Exception Handling

Configurable exception handlers that render error responses when uncaught exceptions occur during request handling:

- **`json`** — Returns structured JSON error responses (default in dev).
- **`production`** — Returns minimal error details (default in prod).
- **`symfony`** — Delegates to Symfony's `ErrorHandler` (requires `symfony/error-handler`).
- **`custom`** — Use your own handler by FQCN.

## Coroutines

When `platform.coroutines.enabled` is `true`, Swoole's coroutine hooks are enabled and the bundle provides service pooling for stateful services (like Doctrine's EntityManager) that cannot be shared across concurrent coroutines.

### How it works

Swoole hooks standard PHP I/O functions (`file_get_contents`, `PDO`, `curl_*`, etc.) so they yield to the event loop instead of blocking. This means multiple requests can be handled concurrently within a single worker process.

The bundle automatically generates contextual proxies for tagged services. Each coroutine gets its own isolated service instance, preventing state leaks between concurrent requests.

### Service pooling

Tag a service as stateful to opt into automatic pooling:

```yaml
# config/services.yaml
services:
    Doctrine\ORM\EntityManagerInterface:
        tags:
            - { name: swoole_bundle.stateful_service }
```

When coroutines are enabled, the bundle replaces these services with proxies that:

1. Check out an instance from a pool on first access in a coroutine.
2. Return it to the pool when the coroutine finishes.
3. Reset the instance between uses (via stability checkers and initializers).

**Container parameters:**

| Parameter | Description |
|-----------|-------------|
| `swoole_bundle.coroutines_support.enabled` | Whether coroutines are active |
| `swoole_bundle.coroutines_support.max_service_instances` | Max pooled instances per service |
| `swoole_bundle.coroutines_support.stateful_services` | List of stateful service IDs |
| `swoole_bundle.coroutines_support.compile_processors` | Compiler processors for pool configuration |
| `swoole_bundle.coroutines_support.doctrine_compile_processor.config` | Doctrine EM pool limits |

### Doctrine integration

When Doctrine is present and coroutines are enabled, the bundle provides:

- **EntityManager stability checking** — Detects closed or broken EntityManagers and replaces them.
- **EntityManager resetting** — Clears EM state (identity map, etc.) between requests.
- **Per-coroutine connection pooling** — Each coroutine gets its own EM with an isolated DB connection.

Configure limits in `doctrine_processor_config`:

```yaml
swoole:
    platform:
        coroutines:
            doctrine_processor_config:
                global_limit: 10        # Max pooled EMs across all connections
                limits:                 # Per-connection overrides (connection name => limit)
                    default: 10
```

## Task Workers

Swoole task workers run tasks asynchronously in separate processes, offloading heavy work from the request-handling workers.

### Configuration

```yaml
swoole:
    task_worker:
        services:
            reset_handler: true     # Reset container state between task executions
        settings:
            worker_count: 2         # Number of task worker processes
```

When coroutines are enabled, task workers also run in coroutine context.

### Implementing a task handler

```php
use SwooleBundle\SwooleBundle\Server\TaskHandler\TaskHandler;
use Swoole\Server;

class MyTaskHandler implements TaskHandler
{
    public function handle(Server $server, Server\Task $task): void
    {
        $data = $task->data;
        // Process task...
        $task->finish(['status' => 'ok']);
    }
}
```

Register as a service tagged with `swoole_bundle.server_configurator`, or wire it directly via the `TaskHandler` interface.

### Dispatching tasks

```php
use SwooleBundle\SwooleBundle\Server\HttpServer;

$server->dispatchTask($payload); // Returns bool
```

## Server Lifecycle Interfaces

### Bootable

Services implementing `Bootable` run initialization logic just before the server starts:

```php
use SwooleBundle\SwooleBundle\Server\Runtime\Bootable;

class MyBootableService implements Bootable
{
    public function boot(array $runtimeConfiguration = []): void
    {
        // Initialize resources before server start
    }
}
```

Auto-tagged via `registerForAutoconfiguration(Bootable::class)`.

### Configurator

Services implementing `Configurator` can modify the raw Swoole `Server` instance before it starts:

```php
use SwooleBundle\SwooleBundle\Server\Configurator\Configurator;
use Swoole\Server;

class MyConfigurator implements Configurator
{
    public function configure(Server $server): void
    {
        $server->on('HandShakeComplete', function (Server $server, $request) {
            // WebSocket handshake complete
        });
    }
}
```

Auto-tagged via `registerForAutoconfiguration(Configurator::class)`.

### Request Handler

```php
use SwooleBundle\SwooleBundle\Server\RequestHandler\RequestHandler;
use Swoole\Http\Request;
use Swoole\Http\Response;

class MyRequestHandler implements RequestHandler
{
    public function handle(Request $request, Response $response): void
    {
        $response->header('Content-Type', 'text/plain');
        $response->end('Hello from Swoole');
    }
}
```

### Middleware

```php
use SwooleBundle\SwooleBundle\Server\Middleware\Middleware;
use Swoole\Http\Request;
use Swoole\Http\Response;

class CorsMiddleware implements Middleware
{
    public function __invoke(Request $request, Response $response): void
    {
        $response->header('Access-Control-Allow-Origin', '*');
        // Continue to next handler
    }
}
```

### Worker Lifecycle Handlers

| Interface | Event |
|-----------|-------|
| `WorkerStartHandler` | `onWorkerStart` — runs in each worker process |
| `WorkerStopHandler` | `onWorkerStop` |
| `WorkerErrorHandler` | `onWorkerError` |
| `WorkerExitHandler` | `onWorkerExit` |

### Server Lifecycle Handlers

| Interface | Event |
|-----------|-------|
| `ServerStartHandler` | `onStart` — runs in the master process |
| `ServerShutdownHandler` | `onShutdown` |
| `ServerManagerStartHandler` | `onManagerStart` |
| `ServerManagerStopHandler` | `onManagerStop` |

## Events

The bundle dispatches Symfony events at key lifecycle points:

| Event | Constant |
|-------|----------|
| `ServerStartedEvent` | `swoole_bundle.server.started` |
| `WorkerStartedEvent` | `swoole_bundle.worker.started` |
| `WorkerStoppedEvent` | `swoole_bundle.worker.stopped` |
| `WorkerExitedEvent` | `swoole_bundle.worker.exited` |
| `WorkerErrorEvent` | `swoole_bundle.worker.error` |
| `RequestWithSessionFinishedEvent` | `swoole_bundle.request_with_session.finished` |

Subscribe via an event subscriber:

```php
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\ServerStartedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class MySubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [ServerStartedEvent::NAME => 'onServerStarted'];
    }

    public function onServerStarted(ServerStartedEvent $event): void
    {
        $server = $event->getServer();
    }
}
```

## HttpServer API

The `HttpServer` class wraps the raw Swoole `Server` and provides a typed API:

```php
use SwooleBundle\SwooleBundle\Server\HttpServer;

$server->start(): bool;                    // Start the server
$server->shutdown(bool $noDelay = false): void;  // Graceful or immediate shutdown
$server->reload(): void;                   // Reload workers (zero-downtime)
$server->metrics(): array;                 // Server stats
$server->isRunning(): bool;                // Check if server is active
$server->dispatchTask(mixed $data): bool;  // Send task to task workers
$server->push(int $fd, string|Frame $data, int $opcode, int $flags): bool;  // WebSocket push
$server->getServer(): Server;              // Raw Swoole Server instance
$server->getListeners(): array;            // Additional listener ports
```

## Reverse Proxy Setup

When running behind Nginx or another reverse proxy, configure trusted proxies:

```yaml
swoole:
    http_server:
        trusted_proxies: ['*']
```

This ensures `Request::getScheme()`, `Request::getHost()`, and `Request::getClientIp()` return correct values from forwarded headers.

For CloudFront, additionally enable:

```yaml
swoole:
    http_server:
        services:
            cloudfront_proto_header_handler: true
```

## Session Handling

The bundle provides `SwooleTableStorage` — a Swoole Table-backed session handler that works across worker processes without requiring an external session store. It's automatically configured when sessions are used.

## WebSocket Support

The underlying server is `Swoole\WebSocket\Server`, so WebSocket support is built in. Use a `Configurator` to register WebSocket event handlers:

```php
class WebSocketConfigurator implements Configurator
{
    public function configure(Server $server): void
    {
        $server->on('message', function (Server $server, Frame $frame) {
            $server->push($frame->fd, "Echo: {$frame->data}");
        });

        $server->on('close', function (Server $server, int $fd) {
            // Connection closed
        });
    }
}
```

## Production Deployment

1. **Worker count**: Set `worker_count` to `2 * cpuCores` for I/O-bound workloads.
2. **Memory management**: Enable `worker_max_request` (e.g. `5000`) to periodically restart workers and reclaim memory.
3. **Graceful shutdown**: The bundle handles `SIGTERM` with a 10-second drain window. Workers finish in-flight requests before exiting.
4. **Zero-downtime reload**: Use `swoole:server:reload` (or `SIGUSR1`) to reload code without dropping connections.
5. **Static files**: Use `strategy: advanced` for zero-copy static file serving, or offload to Nginx.
6. **Logging**: Set `log_level: warning` in production to reduce noise.
7. **PID file**: Set `pid_file` for daemon mode management.

## Architecture

```
packages/swoole-bundle/src/
├── Bridge/
│   ├── Doctrine/          # EM stability checking, connection keepalive, service pooled repositories
│   ├── Log/               # Access log formatters (simple, JSON)
│   ├── Monolog/           # Monolog processor integration
│   ├── Swoole/            # Swoole abstractions (metrics, running modes)
│   └── Symfony/
│       ├── Bundle/        # DI extension, compiler passes, console commands
│       ├── Container/     # Service pooling, proxy generation, resetters, stability checkers
│       ├── ErrorHandler/  # Exception handling strategies
│       ├── Event/         # Symfony events for server lifecycle
│       ├── HttpFoundation/ # Request/response bridging, session storage, access logging
│       ├── HttpKernel/    # Kernel request handling (standard + coroutine-aware)
│       └── Messenger/     # Swoole task transport for Messenger
├── Client/                # HTTP client (coroutine-based, used by API server client)
├── Component/             # Mutex locking for coroutine safety
└── Server/
    ├── Api/               # Management API server and client
    ├── Config/            # Socket configuration
    ├── Configurator/      # Server configurator chain
    ├── Middleware/         # Request middleware pipeline
    ├── RequestHandler/    # Request handling chain (static files, exceptions, rate limiting)
    ├── Runtime/           # Boot manager, HMR (inotify)
    ├── Session/           # Swoole Table session storage
    ├── TaskHandler/       # Task worker interfaces
    └── WorkerHandler/     # Worker lifecycle handler interfaces
```

## Troubleshooting

### Swoole constants are global

Constants like `SWOOLE_PROCESS`, `SWOOLE_BASE`, and `SWOOLE_THREAD` are global PHP constants defined by the Swoole extension, not class constants. Inside a namespace, you must use the `\` prefix:

```php
// In namespaced code:
$mode = \SWOOLE_PROCESS;   // Correct — resolves the global constant
$mode = SWOOLE_PROCESS;    // Wrong — PHP looks for SwooleBundle\SwooleBundle\Bridge\Swoole\SWOOLE_PROCESS
```

The bundle's `Swoole::getRunningModes()` method maps string names to these constants:

| Name | Constant | Value |
|------|----------|-------|
| `process` | `\SWOOLE_PROCESS` | 2 |
| `reactor` | `\SWOOLE_BASE` | 1 |
| `thread` | `\SWOOLE_THREAD` | 3 |

### Unsupported server settings

Swoole's `Server::set()` silently ignores unknown options in some versions, but in Swoole 6.2.0 the server throws on unrecognized settings. Notably, `heartbeat_check_idle_time` is **not** a valid Swoole server setting and will cause a startup error. Use `heartbeat_check_interval` instead — it detects and closes stuck connections at the configured interval.

### IPC and Docker containers

Swoole provides two IPC mechanisms for inter-process communication:

| Constant | Mechanism | `push()`/`pop()` | `write()`/`read()` |
|----------|-----------|-------------------|-------------------|
| `SWOOLE_IPC_UNIXSOCK` | Unix socket pipes | No | Yes |
| `SWOOLE_IPC_MSGQUEUE` | System V message queues | Yes | Yes |

`Swoole\Process::push()` and `pop()` **require** `SWOOLE_IPC_MSGQUEUE`. In Docker containers, System V message queues may be unavailable even with `privileged: true` and `ipc: host`. If you get `"no msgqueue, cannot use pop()"`, either:

- Use `write()`/`read()` instead of `push()`/`pop()` (works with `SWOOLE_IPC_UNIXSOCK` alone).
- Use a shared `Swoole\Table` for result passing — tables are allocated in shared memory before fork and accessible from all child processes without any IPC.

### proc_open() blocks the event loop

Swoole hooks standard I/O functions (`file_get_contents`, `PDO`, `curl_*`, `socket_*`, etc.) to yield to the coroutine scheduler. However, `proc_open()` is **not** hooked — it blocks the entire worker process. For CPU-heavy or long-running subprocess work (e.g. FFmpeg transcoding), offload to dedicated worker processes via `Swoole\Process` or the Swoole task worker system. Never call `proc_open()` from a request-handling coroutine.

## License

MIT
