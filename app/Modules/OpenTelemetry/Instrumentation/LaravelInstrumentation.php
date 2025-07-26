<?php

namespace App\Modules\OpenTelemetry\Instrumentation;

use App\Modules\OpenTelemetry\OpenTelemetryManager;
use App\Modules\OpenTelemetry\SpanBuilder;
use Illuminate\Auth\Events\Attempting;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Events\LocaleUpdated;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use OpenTelemetry\API\Trace\StatusCode;

class LaravelInstrumentation extends ServiceProvider
{
    private OpenTelemetryManager $telemetry;

    public function boot(): void
    {
        $this->telemetry = app(OpenTelemetryManager::class);
        $this->registerEventListeners();
        $this->registerFilesystemInstrumentation();
        $this->registerCacheInstrumentation();
    }

    private function registerEventListeners(): void
    {
        Event::listen(Attempting::class, [$this, 'handleAuthAttempting']);
        Event::listen(Authenticated::class, [$this, 'handleAuthenticated']);
        Event::listen(Login::class, [$this, 'handleLogin']);
        Event::listen(Logout::class, [$this, 'handleLogout']);
        Event::listen(Failed::class, [$this, 'handleAuthFailed']);
        Event::listen(Registered::class, [$this, 'handleRegistered']);

        Event::listen(MessageSending::class, [$this, 'handleMessageSending']);
        Event::listen(MessageSent::class, [$this, 'handleMessageSent']);

        Event::listen(JobProcessing::class, [$this, 'handleJobProcessing']);
        Event::listen(JobProcessed::class, [$this, 'handleJobProcessed']);
        Event::listen(JobFailed::class, [$this, 'handleJobFailed']);

        Event::listen(RouteMatched::class, [$this, 'handleRouteMatched']);

        Event::listen(LocaleUpdated::class, [$this, 'handleLocaleUpdated']);
    }

    public function handleAuthAttempting(Attempting $event): void
    {
        SpanBuilder::create('auth.attempt')
            ->asInternal()
            ->attributes([
                'auth.guard'    => $event->guard,
                'auth.username' => $event->credentials['email'] ?? $event->credentials['username'] ?? 'unknown',
                'auth.remember' => $event->remember ?? false,
            ])
            ->tags([
                'auth.event' => 'attempting',
                'auth.guard' => $event->guard,
            ])
            ->trace(function ($span) use ($event) {
                Log::channel('otel_debug')->info('Auth attempt traced', [
                    'guard'    => $event->guard,
                    'username' => $event->credentials['email'] ?? $event->credentials['username'] ?? 'unknown',
                ]);
            });
    }

    public function handleAuthenticated(Authenticated $event): void
    {
        SpanBuilder::create('auth.authenticated')
            ->asInternal()
            ->attributes([
                'auth.guard'     => $event->guard,
                'auth.user.id'   => $event->user->getAuthIdentifier(),
                'auth.user.type' => get_class($event->user),
            ])
            ->tags([
                'auth.event' => 'authenticated',
                'auth.guard' => $event->guard,
                'user.id'    => (string)$event->user->getAuthIdentifier(),
            ])
            ->trace(function ($span) use ($event) {
                Log::channel('otel_debug')->info('User authenticated', [
                    'user_id' => $event->user->getAuthIdentifier(),
                    'guard'   => $event->guard,
                ]);
            });
    }

    public function handleLogin(Login $event): void
    {
        SpanBuilder::create('auth.login')
            ->asInternal()
            ->attributes([
                'auth.guard'    => $event->guard,
                'auth.user.id'  => $event->user->getAuthIdentifier(),
                'auth.remember' => $event->remember,
            ])
            ->tags([
                'auth.event' => 'login',
                'auth.guard' => $event->guard,
                'user.id'    => (string)$event->user->getAuthIdentifier(),
            ])
            ->trace(function ($span) use ($event) {
                Log::channel('otel_debug')->info('User logged in', [
                    'user_id'  => $event->user->getAuthIdentifier(),
                    'guard'    => $event->guard,
                    'remember' => $event->remember,
                ]);
            });
    }

    public function handleLogout(Logout $event): void
    {
        SpanBuilder::create('auth.logout')
            ->asInternal()
            ->attributes([
                'auth.guard'   => $event->guard,
                'auth.user.id' => $event->user?->getAuthIdentifier(),
            ])
            ->tags([
                'auth.event' => 'logout',
                'auth.guard' => $event->guard,
                'user.id'    => (string)($event->user?->getAuthIdentifier() ?? 'unknown'),
            ])
            ->trace(function ($span) use ($event) {
                Log::channel('otel_debug')->info('User logged out', [
                    'user_id' => $event->user?->getAuthIdentifier(),
                    'guard'   => $event->guard,
                ]);
            });
    }

    public function handleAuthFailed(Failed $event): void
    {
        SpanBuilder::create('auth.failed')
            ->asInternal()
            ->attributes([
                'auth.guard'          => $event->guard,
                'auth.username'       => $event->credentials['email'] ?? $event->credentials['username'] ?? 'unknown',
                'auth.failure_reason' => 'invalid_credentials',
            ])
            ->tags([
                'auth.event'  => 'failed',
                'auth.guard'  => $event->guard,
                'auth.result' => 'failure',
            ])
            ->trace(function ($span) use ($event) {
                $span->setStatus(StatusCode::STATUS_ERROR, 'Authentication failed');
                Log::channel('otel_debug')->warning('Authentication failed', [
                    'guard'    => $event->guard,
                    'username' => $event->credentials['email'] ?? $event->credentials['username'] ?? 'unknown',
                ]);
            });
    }

    public function handleRegistered(Registered $event): void
    {
        SpanBuilder::create('auth.registered')
            ->asInternal()
            ->attributes([
                'auth.user.id'   => $event->user->getAuthIdentifier(),
                'auth.user.type' => get_class($event->user),
            ])
            ->tags([
                'auth.event' => 'registered',
                'user.id'    => (string)$event->user->getAuthIdentifier(),
            ])
            ->trace(function ($span) use ($event) {
                Log::channel('otel_debug')->info('New user registered', [
                    'user_id' => $event->user->getAuthIdentifier(),
                ]);
            });
    }

    public function handleMessageSending(MessageSending $event): void
    {
        SpanBuilder::create('mail.sending')
            ->asProducer()
            ->attributes([
                'mail.subject' => $event->message->getSubject(),
                'mail.to'      => implode(', ', array_keys($event->message->getTo() ?? [])),
                'mail.from'    => implode(', ', array_keys($event->message->getFrom() ?? [])),
                'mail.driver'  => config('mail.default'),
            ])
            ->tags([
                'mail.event'  => 'sending',
                'mail.driver' => config('mail.default'),
            ])
            ->trace(function ($span) use ($event) {
                Log::channel('otel_debug')->info('Email sending', [
                    'subject' => $event->message->getSubject(),
                    'to'      => implode(', ', array_keys($event->message->getTo() ?? [])),
                ]);
            });
    }

    public function handleMessageSent(MessageSent $event): void
    {
        SpanBuilder::create('mail.sent')
            ->asProducer()
            ->attributes([
                'mail.subject' => $event->message->getSubject(),
                'mail.to'      => implode(', ', array_keys($event->message->getTo() ?? [])),
                'mail.from'    => implode(', ', array_keys($event->message->getFrom() ?? [])),
                'mail.driver'  => config('mail.default'),
            ])
            ->tags([
                'mail.event'  => 'sent',
                'mail.driver' => config('mail.default'),
                'mail.result' => 'success',
            ])
            ->trace(function ($span) use ($event) {
                Log::channel('otel_debug')->info('Email sent successfully', [
                    'subject' => $event->message->getSubject(),
                    'to'      => implode(', ', array_keys($event->message->getTo() ?? [])),
                ]);
            });
    }

    // Queue Event Handlers
    public function handleJobProcessing(JobProcessing $event): void
    {
        SpanBuilder::create('queue.job.processing')
            ->asConsumer()
            ->attributes([
                'job.class'      => $event->job->resolveName(),
                'job.queue'      => $event->job->getQueue(),
                'job.connection' => $event->connectionName,
                'job.attempts'   => $event->job->attempts(),
            ])
            ->tags([
                'job.event'      => 'processing',
                'job.class'      => class_basename($event->job->resolveName()),
                'job.queue'      => $event->job->getQueue(),
                'job.connection' => $event->connectionName,
            ])
            ->trace(function ($span) use ($event) {
                Log::channel('otel_debug')->info('Job processing started', [
                    'job_class'  => $event->job->resolveName(),
                    'queue'      => $event->job->getQueue(),
                    'connection' => $event->connectionName,
                ]);
            });
    }

    public function handleJobProcessed(JobProcessed $event): void
    {
        SpanBuilder::create('queue.job.processed')
            ->asConsumer()
            ->attributes([
                'job.class'      => $event->job->resolveName(),
                'job.queue'      => $event->job->getQueue(),
                'job.connection' => $event->connectionName,
                'job.result'     => 'success',
            ])
            ->tags([
                'job.event'  => 'processed',
                'job.class'  => class_basename($event->job->resolveName()),
                'job.result' => 'success',
            ]);
    }

    public function handleJobFailed(JobFailed $event): void
    {
        SpanBuilder::create('queue.job.failed')
            ->asConsumer()
            ->attributes([
                'job.class'      => $event->job->resolveName(),
                'job.queue'      => $event->job->getQueue(),
                'job.connection' => $event->connectionName,
                'job.error'      => $event->exception->getMessage(),
                'job.result'     => 'failure',
            ])
            ->tags([
                'job.event'  => 'failed',
                'job.class'  => class_basename($event->job->resolveName()),
                'job.result' => 'failure',
            ])
            ->trace(function ($span) use ($event) {
                $span->recordException($event->exception);
                $span->setStatus(StatusCode::STATUS_ERROR, $event->exception->getMessage());

                Log::channel('otel_debug')->error('Job failed', [
                    'job_class' => $event->job->resolveName(),
                    'queue'     => $event->job->getQueue(),
                    'error'     => $event->exception->getMessage(),
                ]);
            });
    }

    public function handleRouteMatched(RouteMatched $event): void
    {
        SpanBuilder::create('route.matched')
            ->asInternal()
            ->attributes([
                'route.name'       => $event->route->getName(),
                'route.uri'        => $event->route->uri(),
                'route.method'     => implode('|', $event->route->methods()),
                'route.action'     => $event->route->getActionName(),
                'route.middleware' => implode(', ', $event->route->gatherMiddleware()),
            ])
            ->tags([
                'route.name'   => $event->route->getName() ?? 'unnamed',
                'route.method' => implode('|', $event->route->methods()),
            ])
            ->trace(function ($span) use ($event) {
                Log::channel('otel_debug')->info('Route matched', [
                    'route_name'   => $event->route->getName(),
                    'route_uri'    => $event->route->uri(),
                    'route_action' => $event->route->getActionName(),
                ]);
            });
    }

    public function handleLocaleUpdated(LocaleUpdated $event): void
    {
        SpanBuilder::create('locale.updated')
            ->asInternal()
            ->attributes([
                'locale.previous' => app()->getLocale(),
                'locale.new'      => $event->locale,
            ])
            ->tags([
                'locale.event' => 'updated',
                'locale.new'   => $event->locale,
            ]);
    }

    private function registerFilesystemInstrumentation(): void
    {
        $this->app->extend('filesystem', function ($filesystem, $app) {
            return new TracedFilesystemManager($app, $this->telemetry);
        });
    }

    private function registerCacheInstrumentation(): void
    {
        $this->app->extend('cache', function ($cache, $app) {
            return new TracedCacheManager($app, $this->telemetry);
        });
    }
}