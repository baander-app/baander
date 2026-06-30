<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Server;

use Assert\Assertion;
use Assert\AssertionFailedException;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;
use SwooleBundle\SwooleBundle\Server\Config\Socket;
use SwooleBundle\SwooleBundle\Server\Config\Sockets;

/**
 * @phpstan-type SwooleSettingsInputShape = array{
 *   daemonize?: bool,
 *   pid_file?: string,
 *   hook_flags?: int,
 *   max_coroutine?: int,
 *   reactor_count?: int,
 *   worker_count?: int,
 *   task_worker_count?: int|string,
 *   serve_static?: string,
 *   public_dir?: string,
 *   http_compression?: bool,
 *   http_compression_level?: int,
 *   http_compression_types?: list<string>,
 *   http_compression_min_length?: int,
 *   upload_tmp_dir?: string,
 *   package_max_length?: string,
 *   worker_max_request?: int,
 *   worker_max_request_grace?: int,
 *   heartbeat_check_interval?: int,
 *   enable_coroutine?: bool,
 *   task_enable_coroutine?: bool,
 *   task_use_object?: bool,
 *   log_file?: string,
 *   log_level?: string,
 *   user?: string,
 *   group?: string,
 *   fiber_context?: 'auto'|'off'|'on',
 * }
 * @phpstan-type SwooleSettingsOutputShape = array{
 *    daemonize?: bool,
 *    pid_file: string,
 *    hook_flags?: int,
 *    max_coroutine?: int,
 *    reactor_count: int,
 *    worker_count: int,
 *    task_worker_count?: int,
 *    serve_static: string,
 *    public_dir: string,
 *    http_compression?: bool,
 *    http_compression_level: int,
 *    http_compression_types?: list<string>,
 *    http_compression_min_length?: int,
 *    upload_tmp_dir: string,
 *    buffer_output_size?: string,
 *    package_max_length?: string,
 *    worker_max_request: int,
 *    worker_max_request_grace?: int,
 *    heartbeat_check_interval?: int,
 *    heartbeat_idle_time?: int,
 *    enable_coroutine?: bool,
 *    task_enable_coroutine?: bool,
 *    task_use_object?: bool,
 *    log_file?: string,
 *    log_level: string,
 *    user?: string,
 *    group?: string,
 *    fiber_context?: 'auto'|'off'|'on',
 *    dispatch_mode?: int,
 *    reload_async?: bool,
 *    task_max_request?: int,
 *    input_buffer_size?: int,
 *    socket_buffer_size?: int,
 *    max_queued_bytes?: int,
 *    enable_reuse_port?: bool,
 *    ssl_protocols?: int,
 *    ssl_compress?: bool,
 *    open_tcp_nodelay?: bool,
 *    tcp_fastopen?: bool,
 *    open_tcp_keepalive?: bool,
 *    open_http_protocol?: bool,
 *    discard_timeout_request?: bool,
 *    enable_delay_receive?: bool,
 *    open_eof_check?: bool,
 *    open_eof_split?: bool,
 *    open_length_check?: bool,
 *    package_length_type?: string,
 *    package_body_offset?: int,
 *    static_handler_locations?: list<string>,
 *    write_buffer_size?: int,
 *  }
 * @phpstan-import-type SwooleSettingsShape from HttpServerConfiguration
 * @todo Create interface and split this class
 * @final
 */
final class DefaultHttpServerConfiguration implements HttpServerConfiguration
{
    private const string SWOOLE_HTTP_SERVER_CONFIG_DAEMONIZE = 'daemonize';
    private const string SWOOLE_HTTP_SERVER_CONFIG_SERVE_STATIC = 'serve_static';
    private const string SWOOLE_HTTP_SERVER_CONFIG_REACTOR_COUNT = 'reactor_count';
    private const string SWOOLE_HTTP_SERVER_CONFIG_WORKER_COUNT = 'worker_count';
    private const string SWOOLE_HTTP_SERVER_CONFIG_TASK_WORKER_COUNT = 'task_worker_count';
    private const string SWOOLE_HTTP_SERVER_CONFIG_PUBLIC_DIR = 'public_dir';
    private const string SWOOLE_HTTP_SERVER_CONFIG_HTTP_COMPRESSION = 'http_compression';
    private const string SWOOLE_HTTP_SERVER_CONFIG_HTTP_COMPRESSION_LEVEL = 'http_compression_level';
    private const string SWOOLE_HTTP_SERVER_CONFIG_HTTP_COMPRESSION_TYPES = 'http_compression_types';
    private const string SWOOLE_HTTP_SERVER_CONFIG_HTTP_COMPRESSION_MIN_LENGTH = 'http_compression_min_length';
    private const string SWOOLE_HTTP_SERVER_CONFIG_UPLOAD_TMP_DIR = 'upload_tmp_dir';
    private const string SWOOLE_HTTP_SERVER_CONFIG_LOG_FILE = 'log_file';
    private const string SWOOLE_HTTP_SERVER_CONFIG_LOG_LEVEL = 'log_level';
    private const string SWOOLE_HTTP_SERVER_CONFIG_PID_FILE = 'pid_file';
    private const string SWOOLE_HTTP_SERVER_CONFIG_BUFFER_OUTPUT_SIZE = 'buffer_output_size';
    private const string SWOOLE_HTTP_SERVER_CONFIG_PACKAGE_MAX_LENGTH = 'package_max_length';
    private const string SWOOLE_HTTP_SERVER_CONFIG_WORKER_MAX_REQUEST = 'worker_max_request';
    private const string SWOOLE_HTTP_SERVER_CONFIG_WORKER_MAX_REQUEST_GRACE = 'worker_max_request_grace';
    private const string SWOOLE_HTTP_SERVER_CONFIG_HEARTBEAT_CHECK_INTERVAL = 'heartbeat_check_interval';
    private const string SWOOLE_HTTP_SERVER_CONFIG_MAX_WAIT_TIME = 'max_wait_time';
    private const string SWOOLE_HTTP_SERVER_CONFIG_ENABLE_COROUTINE = 'enable_coroutine';
    private const string SWOOLE_HTTP_SERVER_CONFIG_MAX_COROUTINE = 'max_coroutine';
    private const string SWOOLE_HTTP_SERVER_CONFIG_TASK_ENABLE_COROUTINE = 'task_enable_coroutine';
    private const string SWOOLE_HTTP_SERVER_CONFIG_TASK_USE_OBJECT = 'task_use_object';
    private const string SWOOLE_HTTP_SERVER_CONFIG_COROUTINE_HOOK_FLAGS = 'hook_flags';
    private const string SWOOLE_HTTP_SERVER_CONFIG_USER = 'user';
    private const string SWOOLE_HTTP_SERVER_CONFIG_GROUP = 'group';
    private const string SWOOLE_HTTP_SERVER_CONFIG_DISPATCH_MODE = 'dispatch_mode';
    private const string SWOOLE_HTTP_SERVER_CONFIG_RELOAD_ASYNC = 'reload_async';
    private const string SWOOLE_HTTP_SERVER_CONFIG_TASK_MAX_REQUEST = 'task_max_request';
    private const string SWOOLE_HTTP_SERVER_CONFIG_INPUT_BUFFER_SIZE = 'input_buffer_size';
    private const string SWOOLE_HTTP_SERVER_CONFIG_SOCKET_BUFFER_SIZE = 'socket_buffer_size';
    private const string SWOOLE_HTTP_SERVER_CONFIG_HEARTBEAT_IDLE_TIME = 'heartbeat_idle_time';
    private const string SWOOLE_HTTP_SERVER_CONFIG_MAX_QUEUED_BYTES = 'max_queued_bytes';
    private const string SWOOLE_HTTP_SERVER_CONFIG_ENABLE_REUSE_PORT = 'enable_reuse_port';
    private const string SWOOLE_HTTP_SERVER_CONFIG_SSL_PROTOCOLS = 'ssl_protocols';
    private const string SWOOLE_HTTP_SERVER_CONFIG_SSL_COMPRESS = 'ssl_compress';
    private const string SWOOLE_HTTP_SERVER_CONFIG_OPEN_TCP_NODELAY = 'open_tcp_nodelay';
    private const string SWOOLE_HTTP_SERVER_CONFIG_TCP_FASTOPEN = 'tcp_fastopen';
    private const string SWOOLE_HTTP_SERVER_CONFIG_OPEN_TCP_KEEPALIVE = 'open_tcp_keepalive';
    private const string SWOOLE_HTTP_SERVER_CONFIG_OPEN_HTTP_PROTOCOL = 'open_http_protocol';
    private const string SWOOLE_HTTP_SERVER_CONFIG_DISCARD_TIMEOUT_REQUEST = 'discard_timeout_request';
    private const string SWOOLE_HTTP_SERVER_CONFIG_ENABLE_DELAY_RECEIVE = 'enable_delay_receive';
    private const string SWOOLE_HTTP_SERVER_CONFIG_OPEN_EOF_CHECK = 'open_eof_check';
    private const string SWOOLE_HTTP_SERVER_CONFIG_OPEN_EOF_SPLIT = 'open_eof_split';
    private const string SWOOLE_HTTP_SERVER_CONFIG_OPEN_LENGTH_CHECK = 'open_length_check';
    private const string SWOOLE_HTTP_SERVER_CONFIG_PACKAGE_LENGTH_TYPE = 'package_length_type';
    private const string SWOOLE_HTTP_SERVER_CONFIG_PACKAGE_BODY_OFFSET = 'package_body_offset';
    private const string SWOOLE_HTTP_SERVER_CONFIG_STATIC_HANDLER_LOCATIONS = 'static_handler_locations';
    private const string SWOOLE_HTTP_SERVER_CONFIG_WRITE_BUFFER_SIZE = 'write_buffer_size';

    /**
     * @todo add more
     * @see https://github.com/swoole/swoole-docs/blob/master/modules/swoole-server/configuration.md
     * @see https://github.com/swoole/swoole-docs/blob/master/modules/swoole-http-server/configuration.md
     */
    private const array SWOOLE_HTTP_SERVER_CONFIGURATION = [
        self::SWOOLE_HTTP_SERVER_CONFIG_BUFFER_OUTPUT_SIZE => 'buffer_output_size',
        self::SWOOLE_HTTP_SERVER_CONFIG_COROUTINE_HOOK_FLAGS => 'hook_flags',
        self::SWOOLE_HTTP_SERVER_CONFIG_DAEMONIZE => 'daemonize',
        self::SWOOLE_HTTP_SERVER_CONFIG_ENABLE_COROUTINE => 'enable_coroutine',
        self::SWOOLE_HTTP_SERVER_CONFIG_LOG_FILE => 'log_file',
        self::SWOOLE_HTTP_SERVER_CONFIG_LOG_LEVEL => 'log_level',
        self::SWOOLE_HTTP_SERVER_CONFIG_MAX_COROUTINE => 'max_coroutine',
        self::SWOOLE_HTTP_SERVER_CONFIG_PACKAGE_MAX_LENGTH => 'package_max_length',
        self::SWOOLE_HTTP_SERVER_CONFIG_PID_FILE => 'pid_file',
        self::SWOOLE_HTTP_SERVER_CONFIG_PUBLIC_DIR => 'document_root',
        self::SWOOLE_HTTP_SERVER_CONFIG_HTTP_COMPRESSION => 'http_compression',
        self::SWOOLE_HTTP_SERVER_CONFIG_HTTP_COMPRESSION_LEVEL => 'http_compression_level',
        self::SWOOLE_HTTP_SERVER_CONFIG_HTTP_COMPRESSION_TYPES => 'http_compression_types',
        self::SWOOLE_HTTP_SERVER_CONFIG_HTTP_COMPRESSION_MIN_LENGTH => 'http_compression_min_length',
        self::SWOOLE_HTTP_SERVER_CONFIG_UPLOAD_TMP_DIR => 'upload_tmp_dir',
        self::SWOOLE_HTTP_SERVER_CONFIG_REACTOR_COUNT => 'reactor_num',
        self::SWOOLE_HTTP_SERVER_CONFIG_SERVE_STATIC => 'enable_static_handler',
        self::SWOOLE_HTTP_SERVER_CONFIG_TASK_ENABLE_COROUTINE => 'task_enable_coroutine',
        self::SWOOLE_HTTP_SERVER_CONFIG_TASK_USE_OBJECT => 'task_use_object',
        self::SWOOLE_HTTP_SERVER_CONFIG_TASK_WORKER_COUNT => 'task_worker_num',
        self::SWOOLE_HTTP_SERVER_CONFIG_WORKER_COUNT => 'worker_num',
        self::SWOOLE_HTTP_SERVER_CONFIG_WORKER_MAX_REQUEST => 'max_request',
        self::SWOOLE_HTTP_SERVER_CONFIG_WORKER_MAX_REQUEST_GRACE => 'max_request_grace',
        self::SWOOLE_HTTP_SERVER_CONFIG_HEARTBEAT_CHECK_INTERVAL => 'heartbeat_check_interval',
        self::SWOOLE_HTTP_SERVER_CONFIG_MAX_WAIT_TIME => 'max_wait_time',
        self::SWOOLE_HTTP_SERVER_CONFIG_USER => 'user',
        self::SWOOLE_HTTP_SERVER_CONFIG_GROUP => 'group',
        self::SWOOLE_HTTP_SERVER_CONFIG_DISPATCH_MODE => 'dispatch_mode',
        self::SWOOLE_HTTP_SERVER_CONFIG_RELOAD_ASYNC => 'reload_async',
        self::SWOOLE_HTTP_SERVER_CONFIG_TASK_MAX_REQUEST => 'task_max_request',
        self::SWOOLE_HTTP_SERVER_CONFIG_INPUT_BUFFER_SIZE => 'input_buffer_size',
        self::SWOOLE_HTTP_SERVER_CONFIG_SOCKET_BUFFER_SIZE => 'socket_buffer_size',
        self::SWOOLE_HTTP_SERVER_CONFIG_HEARTBEAT_IDLE_TIME => 'heartbeat_idle_time',
        self::SWOOLE_HTTP_SERVER_CONFIG_MAX_QUEUED_BYTES => 'max_queued_bytes',
        self::SWOOLE_HTTP_SERVER_CONFIG_ENABLE_REUSE_PORT => 'enable_reuse_port',
        self::SWOOLE_HTTP_SERVER_CONFIG_SSL_PROTOCOLS => 'ssl_protocols',
        self::SWOOLE_HTTP_SERVER_CONFIG_SSL_COMPRESS => 'ssl_compress',
        self::SWOOLE_HTTP_SERVER_CONFIG_OPEN_TCP_NODELAY => 'open_tcp_nodelay',
        self::SWOOLE_HTTP_SERVER_CONFIG_TCP_FASTOPEN => 'tcp_fastopen',
        self::SWOOLE_HTTP_SERVER_CONFIG_OPEN_TCP_KEEPALIVE => 'open_tcp_keepalive',
        self::SWOOLE_HTTP_SERVER_CONFIG_OPEN_HTTP_PROTOCOL => 'open_http_protocol',
        self::SWOOLE_HTTP_SERVER_CONFIG_DISCARD_TIMEOUT_REQUEST => 'discard_timeout_request',
        self::SWOOLE_HTTP_SERVER_CONFIG_ENABLE_DELAY_RECEIVE => 'enable_delay_receive',
        self::SWOOLE_HTTP_SERVER_CONFIG_OPEN_EOF_CHECK => 'open_eof_check',
        self::SWOOLE_HTTP_SERVER_CONFIG_OPEN_EOF_SPLIT => 'open_eof_split',
        self::SWOOLE_HTTP_SERVER_CONFIG_OPEN_LENGTH_CHECK => 'open_length_check',
        self::SWOOLE_HTTP_SERVER_CONFIG_PACKAGE_LENGTH_TYPE => 'package_length_type',
        self::SWOOLE_HTTP_SERVER_CONFIG_PACKAGE_BODY_OFFSET => 'package_body_offset',
        self::SWOOLE_HTTP_SERVER_CONFIG_STATIC_HANDLER_LOCATIONS => 'static_handler_locations',
        self::SWOOLE_HTTP_SERVER_CONFIG_WRITE_BUFFER_SIZE => 'write_buffer_size',
    ];

    private const array SWOOLE_SERVE_STATIC = [
        'advanced' => false,
        'default' => true,
        'off' => false,
    ];

    private const array SWOOLE_LOG_LEVELS = [
        'debug' => SWOOLE_LOG_DEBUG,
        'trace' => SWOOLE_LOG_TRACE,
        'info' => SWOOLE_LOG_INFO,
        'notice' => SWOOLE_LOG_NOTICE,
        'warning' => SWOOLE_LOG_WARNING,
        'error' => SWOOLE_LOG_ERROR,
    ];

    /**
     * @var SwooleSettingsOutputShape
     */
    private array $settings;

    /**
     * @param SwooleSettingsInputShape $settings settings available:
     *                        - reactor_count (default: number of cpu cores)
     *                        - worker_count (default: 2 * number of cpu cores)
     *                        - task_worker_count (default: unset; auto => number of cpu cores; number of task workers)
     *                        - serve_static (default: false)
     *                        - public_dir (default: '%kernel.root_dir%/public')
     *                        - buffer_output_size (default: '2097152' unit in byte (2MB))
     *                        - package_max_length (default: '8388608b' unit in byte (8MB))
     *                        - worker_max_requests: Number of requests after which the worker reloads
     *                        - worker_max_requests_grace: Max random number of requests for worker reloading
     *                        - enable_coroutine: enable coroutines in web processes
     *                        - task_enable_coroutine: enable coroutines in task workers
     *                        - task_use_object: enable OOP style task API
     *                        - hook_flags: coroutine hook flags
     *                        - user: operating system user of the worker and task worker child processes
     *                        - group: group of the worker and task worker child processes
     *                        - fiber_context: enable fiber context
     * @throws AssertionFailedException
     */
    public function __construct(
        private readonly Swoole $swoole,
        private readonly Sockets $sockets,
        private string $runningMode = 'process',
        array $settings = [],
        private readonly ?int $maxConcurrency = null,
        private readonly string $fiberContext = 'auto',
    ) {
        Assertion::inArray($fiberContext, ['auto', 'off', 'on']);
        $this->changeRunningMode($runningMode);
        $this->initializeSettings($settings);
    }

    public function isDaemon(): bool
    {
        return isset($this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_DAEMONIZE]);
    }

    public function hasPidFile(): bool
    {
        return isset($this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_PID_FILE]);
    }

    public function servingStaticContent(): bool
    {
        return isset($this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_SERVE_STATIC])
            && $this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_SERVE_STATIC] !== 'off';
    }

    public function hasPublicDir(): bool
    {
        return !empty($this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_PUBLIC_DIR]);
    }

    public function hasHttpCompression(): bool
    {
        return !empty($this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_HTTP_COMPRESSION]);
    }

    public function hasHttpCompressionLevel(): bool
    {
        return !empty($this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_HTTP_COMPRESSION_LEVEL]);
    }

    public function hasUploadTmpDir(): bool
    {
        return !empty($this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_UPLOAD_TMP_DIR]);
    }

    public function changeServerSocket(Socket $socket): void
    {
        $this->sockets->changeServerSocket($socket);
    }

    public function getSockets(): Sockets
    {
        return $this->sockets;
    }

    public function getMaxConcurrency(): ?int
    {
        return $this->maxConcurrency;
    }

    /**
     * @throws AssertionFailedException
     */
    public function enableServingStaticFiles(string $publicDir): void
    {
        $settings = [
            self::SWOOLE_HTTP_SERVER_CONFIG_PUBLIC_DIR => $publicDir,
        ];

        if ($this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_SERVE_STATIC] === 'off') {
            $settings[self::SWOOLE_HTTP_SERVER_CONFIG_SERVE_STATIC] = 'default';
        }

        $this->setSettings($settings);
    }

    public function isReactorRunningMode(): bool
    {
        return $this->runningMode === 'reactor';
    }

    public function getRunningMode(): string
    {
        return $this->runningMode;
    }

    public function getCoroutinesEnabled(): bool
    {
        return isset($this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_ENABLE_COROUTINE])
            && $this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_ENABLE_COROUTINE];
    }

    public function getUser(): string
    {
        return $this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_USER] ?? (extension_loaded('posix') ? posix_getpwuid(
            posix_geteuid()
        )['name'] ?? (string) posix_geteuid() : '-');
    }

    public function getGroup(): string
    {
        return $this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_GROUP] ?? (extension_loaded('posix') ? posix_getgrgid(
            posix_getgid()
        )['name'] ?? (string) posix_getgid() : '-');
    }

    /**
     * @throws AssertionFailedException
     */
    public function getPid(): int
    {
        Assertion::true(
            $this->existsPidFile(),
            'Could not get pid file. It does not exists or server is not running in background.'
        );

        /** @var string $contents */
        $contents = file_get_contents($this->getPidFile());
        Assertion::numeric($contents, 'Contents in pid file is not an integer or it is empty');

        return (int) $contents;
    }

    public function existsPidFile(): bool
    {
        return $this->hasPidFile() && file_exists($this->getPidFile());
    }

    /**
     * @throws AssertionFailedException
     */
    public function getPidFile(): string
    {
        Assertion::keyIsset($this->settings, self::SWOOLE_HTTP_SERVER_CONFIG_PID_FILE, 'Setting "%s" is not set.');

        return $this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_PID_FILE];
    }

    public function getWorkerCount(): int
    {
        return $this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_WORKER_COUNT];
    }

    public function getReactorCount(): int
    {
        return $this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_REACTOR_COUNT];
    }

    public function getServerSocket(): Socket
    {
        return $this->sockets->getServerSocket();
    }

    public function getMaxRequest(): int
    {
        return $this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_WORKER_MAX_REQUEST];
    }

    public function getMaxRequestGrace(): ?int
    {
        return $this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_WORKER_MAX_REQUEST_GRACE] ?? null;
    }

    /**
     * @throws AssertionFailedException
     */
    public function getPublicDir(): string
    {
        Assertion::true(
            $this->hasPublicDir(),
            sprintf('Setting "%s" is not set or empty.', self::SWOOLE_HTTP_SERVER_CONFIG_PUBLIC_DIR)
        );

        return $this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_PUBLIC_DIR];
    }

    /**
     * @throws AssertionFailedException
     */
    public function getHttpCompressionLevel(): int
    {
        Assertion::true(
            $this->hasHttpCompressionLevel(),
            sprintf('Setting "%s" is not set or empty.', self::SWOOLE_HTTP_SERVER_CONFIG_HTTP_COMPRESSION_LEVEL)
        );

        return $this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_HTTP_COMPRESSION_LEVEL];
    }

    public function getUploadTmpDir(): string
    {
        Assertion::true(
            $this->hasUploadTmpDir(),
            sprintf('Setting "%s" is not set or empty.', self::SWOOLE_HTTP_SERVER_CONFIG_UPLOAD_TMP_DIR)
        );

        return $this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_UPLOAD_TMP_DIR];
    }

    /**
     * @return SwooleSettingsInputShape
     */
    public function getSettings(): array
    {
        return $this->settings;
    }

    /**
     * Get settings formatted for swoole http server.
     *
     * @return SwooleSettingsShape
     * @see \Swoole\Http\Server::set()
     * @todo create swoole settings transformer
     */
    public function getSwooleSettings(): array
    {
        /** @var SwooleSettingsShape $swooleSettings */
        $swooleSettings = [];

        foreach ($this->settings as $key => $setting) {
            /** @phpstan-ignore-next-line */
            $swooleSettingKey = self::SWOOLE_HTTP_SERVER_CONFIGURATION[$key];
            $swooleGetter = sprintf('getSwoole%s', str_replace('_', '', $swooleSettingKey));
            if (method_exists($this, $swooleGetter)) {
                $setting = $this->{$swooleGetter}();
            }

            if ($setting === null) {
                continue;
            }

            $swooleSettings[$swooleSettingKey] = $setting;
        }

        return $swooleSettings; // @phpstan-ignore-line
    }

    /**
     * @see getSwooleSettings()
     */
    public function getSwooleLogLevel(): int
    {
        return self::SWOOLE_LOG_LEVELS[$this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_LOG_LEVEL]];
    }

    /**
     * @see getSwooleSettings()
     */
    public function getSwooleEnableStaticHandler(): bool
    {
        return self::SWOOLE_SERVE_STATIC[$this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_SERVE_STATIC]];
    }

    /**
     * @see getSwooleSettings()
     */
    public function getSwooleDocumentRoot(): ?string
    {
        return $this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_SERVE_STATIC] === 'default'
            ? $this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_PUBLIC_DIR]
            : null;
    }

    /**
     * @see getSwooleSettings()
     */
    public function getSwooleMaxRequest(): int
    {
        return $this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_WORKER_MAX_REQUEST] ?? 0;
    }

    /**
     * @see getSwooleSettings()
     */
    public function getSwooleMaxRequestGrace(): ?int
    {
        return $this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_WORKER_MAX_REQUEST_GRACE] ?? null;
    }

    /**
     * @throws AssertionFailedException
     */
    public function daemonize(?string $pidFile = null): void
    {
        $settings = [self::SWOOLE_HTTP_SERVER_CONFIG_DAEMONIZE => true];

        if ($pidFile !== null) {
            $settings[self::SWOOLE_HTTP_SERVER_CONFIG_PID_FILE] = $pidFile;
        }

        $this->setSettings($settings);
    }

    public function getTaskWorkerCount(): int
    {
        return $this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_TASK_WORKER_COUNT] ?? 0;
    }

    public function getDispatchMode(): ?int
    {
        return $this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_DISPATCH_MODE] ?? null;
    }

    public function isReloadAsync(): bool
    {
        return $this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_RELOAD_ASYNC] ?? false;
    }

    public function getTaskMaxRequest(): ?int
    {
        return $this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_TASK_MAX_REQUEST] ?? null;
    }

    public function getHeartbeatIdleTime(): ?int
    {
        return $this->settings[self::SWOOLE_HTTP_SERVER_CONFIG_HEARTBEAT_IDLE_TIME] ?? null;
    }

    public function isFiberContextEnabled(): bool
    {
        if (
            $this->fiberContext === 'auto' &&
            extension_loaded('swoole') &&
            (
                extension_loaded('xdebug') ||
                extension_loaded('pcov') ||
                extension_loaded('blackfire') ||
                extension_loaded('tideways')
            )
        ) {
            return true;
        }

        return $this->fiberContext === 'on';
    }

    private function changeRunningMode(string $runningMode): void
    {
        Assertion::true($this->swoole->supportsRunningMode($runningMode));

        $this->runningMode = $runningMode;
    }

    /**
     * @param SwooleSettingsInputShape $init
     * @throws AssertionFailedException
     */
    private function initializeSettings(array $init): void
    {
        $cpuCores = $this->swoole->cpuCoresCount();

        if (!isset($init[self::SWOOLE_HTTP_SERVER_CONFIG_REACTOR_COUNT])) {
            $init[self::SWOOLE_HTTP_SERVER_CONFIG_REACTOR_COUNT] = $cpuCores;
        }

        if (!isset($init[self::SWOOLE_HTTP_SERVER_CONFIG_WORKER_COUNT])) {
            $init[self::SWOOLE_HTTP_SERVER_CONFIG_WORKER_COUNT] = 2 * $cpuCores;
        }

        if (
            array_key_exists(self::SWOOLE_HTTP_SERVER_CONFIG_TASK_WORKER_COUNT, $init)
            && $init[self::SWOOLE_HTTP_SERVER_CONFIG_TASK_WORKER_COUNT] === 'auto'
        ) {
            $init[self::SWOOLE_HTTP_SERVER_CONFIG_TASK_WORKER_COUNT] = $cpuCores;
        }

        $this->setSettings($init);
    }

    /**
     * @param array<string, mixed> $settings
     * @throws AssertionFailedException
     */
    private function setSettings(array $settings): void
    {
        foreach ($settings as $name => $value) {
            if ($value === null) {
                continue;
            }

            $this->validateSetting($name, $value);
            $this->settings[$name] = $value; // @phpstan-ignore-line
        }

        Assertion::false($this->isDaemon() && !$this->hasPidFile(), 'Pid file is required when using daemon mode');
        Assertion::false(
            $this->servingStaticContent() && !$this->hasPublicDir(),
            'Enabling static files serving requires providing "public_dir" setting.'
        );
    }

    /**
     * @throws AssertionFailedException
     */
    private function validateSetting(string $key, mixed $value): void
    {
        Assertion::keyExists(
            self::SWOOLE_HTTP_SERVER_CONFIGURATION,
            $key,
            'There is no configuration mapping for setting "%s".'
        );

        switch ($key) {
            case self::SWOOLE_HTTP_SERVER_CONFIG_SERVE_STATIC:
                Assertion::inArray($value, array_keys(self::SWOOLE_SERVE_STATIC));

                break;
            case self::SWOOLE_HTTP_SERVER_CONFIG_DAEMONIZE:
            case self::SWOOLE_HTTP_SERVER_CONFIG_TASK_USE_OBJECT:
            case self::SWOOLE_HTTP_SERVER_CONFIG_HTTP_COMPRESSION:
                Assertion::boolean($value);

                break;
            case self::SWOOLE_HTTP_SERVER_CONFIG_PUBLIC_DIR:
                Assertion::string($value);
                Assertion::directory($value, 'Public directory does not exist. Tried "%s".');

                break;
            case self::SWOOLE_HTTP_SERVER_CONFIG_HTTP_COMPRESSION_LEVEL:
                Assertion::integer($value, sprintf('Setting "%s" must be an integer.', $key));
                Assertion::between(
                    $value,
                    0,
                    9,
                    sprintf('Setting "%s" must be a positive integer between 0 and 9.', $key)
                );

                break;
            case self::SWOOLE_HTTP_SERVER_CONFIG_HTTP_COMPRESSION_TYPES:
                Assertion::isArray($value, sprintf('Setting "%s" must be an array.', $key));
                Assertion::allString($value, sprintf('Setting "%s" must contain only strings.', $key));

                break;
            case self::SWOOLE_HTTP_SERVER_CONFIG_HTTP_COMPRESSION_MIN_LENGTH:
                Assertion::integer($value, sprintf('Setting "%s" must be an integer.', $key));
                Assertion::greaterOrEqualThan($value, 1, sprintf('Setting "%s" must be at least 1.', $key));

                break;
            case self::SWOOLE_HTTP_SERVER_CONFIG_UPLOAD_TMP_DIR:
                Assertion::string($value);
                Assertion::directory($value, 'Temporary upload directory does not exist. Tried "%s".');

                break;
            case self::SWOOLE_HTTP_SERVER_CONFIG_LOG_LEVEL:
                Assertion::inArray($value, array_keys(self::SWOOLE_LOG_LEVELS));

                break;
            case self::SWOOLE_HTTP_SERVER_CONFIG_PACKAGE_MAX_LENGTH:
                Assertion::integer($value, sprintf('Setting "%s" must be an integer.', $key));
                Assertion::greaterThan(
                    $value,
                    0,
                    'Package max length value cannot be negative or zero, "%s" provided.'
                );

                break;
            case self::SWOOLE_HTTP_SERVER_CONFIG_BUFFER_OUTPUT_SIZE:
                Assertion::integer($value, sprintf('Setting "%s" must be an integer.', $key));
                Assertion::greaterThan(
                    $value,
                    0,
                    'Buffer output size value cannot be negative or zero, "%s" provided.'
                );

                break;
            case self::SWOOLE_HTTP_SERVER_CONFIG_TASK_WORKER_COUNT:
            case self::SWOOLE_HTTP_SERVER_CONFIG_REACTOR_COUNT:
            case self::SWOOLE_HTTP_SERVER_CONFIG_WORKER_COUNT:
                Assertion::integer($value, sprintf('Setting "%s" must be an integer.', $key));
                Assertion::greaterThan($value, 0, 'Count value cannot be negative, "%s" provided.');

                break;
            case self::SWOOLE_HTTP_SERVER_CONFIG_WORKER_MAX_REQUEST:
                Assertion::integer($value, sprintf('Setting "%s" must be an integer.', $key));
                Assertion::greaterOrEqualThan($value, 0, 'Value cannot be negative, "%s" provided.');

                break;
            case self::SWOOLE_HTTP_SERVER_CONFIG_WORKER_MAX_REQUEST_GRACE:
                Assertion::nullOrInteger($value, sprintf('Setting "%s" must be an integer or null.', $key));
                Assertion::nullOrGreaterOrEqualThan($value, 0, 'Value cannot be negative, "%s" provided.');

                break;
            case self::SWOOLE_HTTP_SERVER_CONFIG_HEARTBEAT_CHECK_INTERVAL:
                Assertion::integer($value, sprintf('Setting "%s" must be an integer.', $key));
                Assertion::greaterThan($value, 0, 'Heartbeat value must be at least 1, "%s" provided.');

                break;
            case self::SWOOLE_HTTP_SERVER_CONFIG_ENABLE_COROUTINE:
            case self::SWOOLE_HTTP_SERVER_CONFIG_TASK_ENABLE_COROUTINE:
                Assertion::boolean($value, sprintf('Setting "%s" must be a boolean.', $key));

                break;
            case self::SWOOLE_HTTP_SERVER_CONFIG_COROUTINE_HOOK_FLAGS:
                Assertion::integer($value, sprintf('Setting "%s" must be a positive integer.', $key));
                Assertion::greaterOrEqualThan($value, 0, sprintf('Setting "%s" must be a positive integer.', $key));

                break;
            case self::SWOOLE_HTTP_SERVER_CONFIG_MAX_COROUTINE:
                Assertion::integer(
                    $value,
                    sprintf('Setting "%s" must be a positive integer lower or equal than 100000.', $key)
                );
                Assertion::between(
                    $value,
                    0,
                    100000,
                    sprintf('Setting "%s" must be a positive integer lower or equal than 100000.', $key)
                );

                break;
            case self::SWOOLE_HTTP_SERVER_CONFIG_DISPATCH_MODE:
                Assertion::integer($value, sprintf('Setting "%s" must be an integer.', $key));
                Assertion::between($value, 1, 7, sprintf('Setting "%s" must be between 1 and 7.', $key));

                break;
            case self::SWOOLE_HTTP_SERVER_CONFIG_RELOAD_ASYNC:
            case self::SWOOLE_HTTP_SERVER_CONFIG_ENABLE_REUSE_PORT:
            case self::SWOOLE_HTTP_SERVER_CONFIG_SSL_COMPRESS:
            case self::SWOOLE_HTTP_SERVER_CONFIG_OPEN_TCP_NODELAY:
            case self::SWOOLE_HTTP_SERVER_CONFIG_TCP_FASTOPEN:
            case self::SWOOLE_HTTP_SERVER_CONFIG_OPEN_TCP_KEEPALIVE:
            case self::SWOOLE_HTTP_SERVER_CONFIG_OPEN_HTTP_PROTOCOL:
            case self::SWOOLE_HTTP_SERVER_CONFIG_DISCARD_TIMEOUT_REQUEST:
            case self::SWOOLE_HTTP_SERVER_CONFIG_ENABLE_DELAY_RECEIVE:
            case self::SWOOLE_HTTP_SERVER_CONFIG_OPEN_EOF_CHECK:
            case self::SWOOLE_HTTP_SERVER_CONFIG_OPEN_EOF_SPLIT:
            case self::SWOOLE_HTTP_SERVER_CONFIG_OPEN_LENGTH_CHECK:
                Assertion::boolean($value, sprintf('Setting "%s" must be a boolean.', $key));

                break;
            case self::SWOOLE_HTTP_SERVER_CONFIG_TASK_MAX_REQUEST:
            case self::SWOOLE_HTTP_SERVER_CONFIG_PACKAGE_BODY_OFFSET:
                Assertion::integer($value, sprintf('Setting "%s" must be an integer.', $key));
                Assertion::greaterOrEqualThan($value, 0, sprintf('Setting "%s" must be >= 0.', $key));

                break;
            case self::SWOOLE_HTTP_SERVER_CONFIG_INPUT_BUFFER_SIZE:
            case self::SWOOLE_HTTP_SERVER_CONFIG_SOCKET_BUFFER_SIZE:
            case self::SWOOLE_HTTP_SERVER_CONFIG_HEARTBEAT_IDLE_TIME:
            case self::SWOOLE_HTTP_SERVER_CONFIG_MAX_QUEUED_BYTES:
            case self::SWOOLE_HTTP_SERVER_CONFIG_WRITE_BUFFER_SIZE:
                Assertion::integer($value, sprintf('Setting "%s" must be an integer.', $key));
                Assertion::greaterThan($value, 0, sprintf('Setting "%s" must be > 0.', $key));

                break;
            case self::SWOOLE_HTTP_SERVER_CONFIG_SSL_PROTOCOLS:
                Assertion::integer($value, sprintf('Setting "%s" must be an integer (bitwise).', $key));

                break;
            case self::SWOOLE_HTTP_SERVER_CONFIG_PACKAGE_LENGTH_TYPE:
                Assertion::string($value, sprintf('Setting "%s" must be a string.', $key));
                Assertion::inArray($value, ['C', 'c', 'S', 's', 'n', 'N', 'v', 'V'],
                    sprintf('Setting "%s" must be a valid pack() format specifier.', $key));

                break;
            case self::SWOOLE_HTTP_SERVER_CONFIG_STATIC_HANDLER_LOCATIONS:
                Assertion::isArray($value, sprintf('Setting "%s" must be an array.', $key));
                Assertion::allString($value, sprintf('Setting "%s" must contain only strings.', $key));

                break;
            default:
                return;
        }
    }
}
