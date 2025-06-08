<?php

namespace App\Modules\Apm;

use App\Modules\Apm\Collectors\SwooleMetricsCollector;
use App\Modules\Apm\Listeners\{AuthenticationListener,
    CacheEventListener,
    DatabaseQueryListener,
    DefaultTerminatedHandler,
    HttpClientListener,
    OctaneMetricsListener,
    QueueListener,
    RedisListener,
    RequestReceivedHandler,
    RequestWorkerStartHandler,
    TaskReceivedHandler,
    TickReceivedHandler};
use App\Modules\Apm\Middleware\ApmMiddleware;
use App\Modules\Apm\Services\SwooleMetricsService;
use Illuminate\Auth\Events\Attempting;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Cache\Events\{CacheHit, CacheMissed, KeyForgotten, KeyWritten};
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Events\{ConnectionFailed, RequestSending, ResponseReceived};
use Illuminate\Queue\Events\{JobExceptionOccurred, JobFailed, JobProcessed, JobProcessing, JobQueued};
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Events\{RequestReceived,
    RequestTerminated,
    TaskReceived,
    TaskTerminated,
    TickReceived,
    TickTerminated,
    WorkerStarting,
    WorkerStopping};
use Psr\Log\LoggerInterface;

class ElasticApmAgentServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register the APM manager as singleton
        $this->app->singleton(OctaneApmManager::class, function ($app) {
            $logger = $app->bound(LoggerInterface::class) ? $app->make(LoggerInterface::class) : null;
            $config = $this->buildApmConfig($app);
            return new OctaneApmManager($logger, $config);
        });

        // Register all listeners as singletons
        $this->registerListeners();

        $this->app->bind(SwooleMetricsCollector::class, function ($app) {
            return new SwooleMetricsCollector(
                $app->make(OctaneApmManager::class),
                $app->make(LoggerInterface::class),
            );
        });

        // For the metrics service, we need to be more careful since it holds state
        $this->app->bind(SwooleMetricsService::class, function ($app) {
            return new SwooleMetricsService(
                $app->make(SwooleMetricsCollector::class),
            );
        });

        $this->app->bind(OctaneMetricsListener::class, function ($app) {
            return new OctaneMetricsListener();
        });

    }

    /**
     * Build APM configuration
     */
    private function buildApmConfig($app): array
    {
        $config = $app->make('config')->get('apm', []);

        // Set defaults from app config if not set
        $config['service_name'] = $config['service_name'] ?? $app->make('config')->get('app.name', 'unknown');
        $config['environment'] = $config['environment'] ?? $app->make('config')->get('app.env', 'unknown');
        $config['service_version'] = $config['service_version'] ?? $app->version() ?? 'unknown';

        // Ensure sampling rate is valid
        $config['sampling_rate'] = max(0.0, min(1.0, (float)($config['sampling_rate'] ?? 1.0)));

        return $config;
    }

    /**
     * Register all event listeners as singletons
     */
    private function registerListeners(): void
    {
        $listeners = [
            DatabaseQueryListener::class,
            DefaultTerminatedHandler::class,
            RequestReceivedHandler::class,
            RequestWorkerStartHandler::class,
            TaskReceivedHandler::class,
            TickReceivedHandler::class,
            HttpClientListener::class,
            CacheEventListener::class,
            RedisListener::class,
            QueueListener::class,
        ];

        foreach ($listeners as $listener) {
            $this->app->singleton($listener, function ($app) use ($listener) {
                $logger = $app->bound(LoggerInterface::class) ? $app->make(LoggerInterface::class) : null;
                return new $listener($logger);
            });
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (!$this->shouldEnableApm()) {
            $this->app->make('log')->info('Elastic APM Agent disabled', [
                'reason' => $this->getDisabledReason(),
            ]);
            return;
        }

        $this->registerEventListeners();
        $this->warmApmManager();
        $this->logApmInitialization();
    }

    /**
     * Check if APM should be enabled
     */
    private function shouldEnableApm(): bool
    {
        return config('apm.enabled', true) &&
            class_exists(\Elastic\Apm\ElasticApm::class) &&
            $this->isValidEnvironment();
    }

    /**
     * Check if the environment is valid for APM
     */
    private function isValidEnvironment(): bool
    {
        $environment = config('app.env', 'production');
        $enabledEnvironments = config('apm.enabled_environments', ['production', 'staging', 'local']);

        return in_array($environment, $enabledEnvironments);
    }

    /**
     * Get reason why APM is disabled
     */
    private function getDisabledReason(): string
    {
        if (!config('apm.enabled', true)) {
            return 'disabled_in_config';
        }

        if (!class_exists(\Elastic\Apm\ElasticApm::class)) {
            return 'elastic_apm_class_not_found';
        }

        if (!$this->isValidEnvironment()) {
            return 'environment_not_enabled';
        }

        return 'unknown';
    }

    /**
     * Register all event listeners with their respective events
     */
    private function registerEventListeners(): void
    {
        /** @var Dispatcher $dispatcher */
        $dispatcher = $this->app->make(Dispatcher::class);

        try {
            // Octane events
            $this->registerOctaneEvents($dispatcher);
            $this->registerOctaneMetrics();

            if (config('apm.monitoring.auth', true)) {
                $this->registerAuthEvents($dispatcher);
            }

            // Database events
            if (config('apm.monitoring.database', true)) {
                $this->registerDatabaseEvents($dispatcher);
            }

            if (config('apm.monitoring.redis', true)) {
                $this->registerRedisEvents($dispatcher);
            }

            // HTTP client events
            if (config('apm.monitoring.http_client', true)) {
                $this->registerHttpClientEvents($dispatcher);
            }

            // Cache events
            if (config('apm.monitoring.cache', true)) {
                $this->registerCacheEvents($dispatcher);
            }

            // Queue events
            if (config('apm.monitoring.queue', true)) {
                $this->registerQueueEvents($dispatcher);
            }

        } catch (\Throwable $e) {
            $this->app->make('log')->error('Failed to register APM event listeners', [
                'exception' => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]);
        }
    }

    /**
     * Register Octane-specific events
     */
    private function registerOctaneEvents(Dispatcher $dispatcher): void
    {
        $octaneEvents = [
            RequestReceived::class   => RequestReceivedHandler::class,
            RequestTerminated::class => DefaultTerminatedHandler::class,
            WorkerStarting::class    => RequestWorkerStartHandler::class,
            TaskReceived::class      => TaskReceivedHandler::class,
            TaskTerminated::class    => DefaultTerminatedHandler::class,
            TickReceived::class      => TickReceivedHandler::class,
            TickTerminated::class    => DefaultTerminatedHandler::class,
        ];

        foreach ($octaneEvents as $event => $listener) {
            if (class_exists($event)) {
                $dispatcher->listen($event, $listener);
            }
        }
    }

    private function registerOctaneMetrics()
    {
        $events = [
            WorkerStarting::class => [OctaneMetricsListener::class, 'handleWorkerStarting'],
            WorkerStopping::class => [OctaneMetricsListener::class, 'handleWorkerStopping'],
        ];

        foreach ($events as $event => $listener) {
            if (class_exists($event)) {
                $this->app->make(Dispatcher::class)->listen($event, $listener);
            }
        }
    }

    private function registerAuthEvents(Dispatcher $dispatcher)
    {
        $events = [
            Attempting::class    => [AuthenticationListener::class, 'handleAttempting'],
            Authenticated::class => [AuthenticationListener::class, 'handleAuthenticated'],
            Login::class         => [AuthenticationListener::class, 'handleLogin'],
            Logout::class        => [AuthenticationListener::class, 'handleLogout'],
            Lockout::class       => [AuthenticationListener::class, 'handleLockout'],
            Registered::class    => [AuthenticationListener::class, 'handleRegistered'],
            Verified::class      => [AuthenticationListener::class, 'handleVerified'],
            PasswordReset::class => [AuthenticationListener::class, 'handlePasswordReset'],
        ];

        foreach ($events as $event => $listener) {
            if (class_exists($event)) {
                $dispatcher->listen($event, $listener);
            }
        }
    }

    /**
     * Register database events
     */
    private function registerDatabaseEvents(Dispatcher $dispatcher): void
    {
        $dispatcher->listen(QueryExecuted::class, DatabaseQueryListener::class);
    }

    private function registerRedisEvents(Dispatcher $dispatcher): void
    {
        $dispatcher->listen(CommandExecuted::class, RedisListener::class);
    }

    /**
     * Register HTTP client events
     */
    private function registerHttpClientEvents(Dispatcher $dispatcher): void
    {
        $httpEvents = [
            RequestSending::class   => [HttpClientListener::class, 'handleRequestSending'],
            ResponseReceived::class => [HttpClientListener::class, 'handleResponseReceived'],
            ConnectionFailed::class => [HttpClientListener::class, 'handleConnectionFailed'],
        ];

        foreach ($httpEvents as $event => $listener) {
            if (class_exists($event)) {
                $dispatcher->listen($event, $listener);
            }
        }
    }

    /**
     * Register cache events
     */
    private function registerCacheEvents(Dispatcher $dispatcher): void
    {
        $cacheEvents = [
            CacheHit::class     => [CacheEventListener::class, 'handleCacheHit'],
            CacheMissed::class  => [CacheEventListener::class, 'handleCacheMissed'],
            KeyWritten::class   => [CacheEventListener::class, 'handleKeyWritten'],
            KeyForgotten::class => [CacheEventListener::class, 'handleKeyForgotten'],
        ];

        foreach ($cacheEvents as $event => $listener) {
            if (class_exists($event)) {
                $dispatcher->listen($event, $listener);
            }
        }
    }

    /**
     * Register queue events
     */
    private function registerQueueEvents(Dispatcher $dispatcher): void
    {
        $queueEvents = [
            JobQueued::class            => [QueueListener::class, 'handleJobQueued'],
            JobProcessing::class        => [QueueListener::class, 'handleJobProcessing'],
            JobProcessed::class         => [QueueListener::class, 'handleJobProcessed'],
            JobFailed::class            => [QueueListener::class, 'handleJobFailed'],
            JobExceptionOccurred::class => [QueueListener::class, 'handleJobExceptionOccurred'],
        ];

        foreach ($queueEvents as $event => $listener) {
            if (class_exists($event)) {
                $dispatcher->listen($event, $listener);
            }
        }
    }

    /**
     * Warm the APM manager to ensure it's ready
     */
    private function warmApmManager(): void
    {
        try {
            $manager = $this->app->make(OctaneApmManager::class);

            // Verify the manager is working
            if (!$manager->isEnabled()) {
                $this->app->make('log')->warning('APM manager is not enabled', [
                    'stats' => $manager->getTransactionStats(),
                ]);
            }
        } catch (\Throwable $e) {
            $this->app->make('log')->error('Failed to warm APM manager', [
                'exception' => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]);
        }
    }

    /**
     * Log APM initialization
     */
    private function logApmInitialization(): void
    {
        try {
            $this->app->make('log')->info('Elastic APM Agent initialized successfully', [
                'service_name'    => config('apm.service_name'),
                'environment'     => config('apm.environment'),
                'service_version' => config('apm.service_version'),
                'sampling_rate'   => config('apm.sampling_rate'),
                'monitoring'      => [
                    'database'    => config('apm.monitoring.database', true),
                    'cache'       => config('apm.monitoring.cache', true),
                    'http_client' => config('apm.monitoring.http_client', true),
                ],
                'laravel_version' => app()->version(),
                'php_version'     => PHP_VERSION,
            ]);
        } catch (\Throwable $e) {
            $this->app->make('log')->error('Failed to log APM initialization', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            OctaneApmManager::class,
            ApmMiddleware::class,
        ];
    }
}