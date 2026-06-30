<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    // The 'swoole' Monolog channel is used by:
    // - CoroutineWatchdog: stall detection events
    // - Thread-safety warnings: cross-thread violations, pool exhaustion
    // - Settings validation: unrecognized or deprecated settings
    // - EntityManagerResetter: debug logging on EM reset
    //
    // The monolog.logger.swoole service is auto-created by MonologBundle's
    // LoggerChannelPass from the monolog.logger tag applied to services
    // (e.g., ExceptionLoggingTransportHandler in services.php).
    // This file documents the channel's purpose for discoverability.
    //
    // Users can override this channel in their own monolog.yaml config.
    // If MonologBundle is not installed, this file is a no-op (checked in SwooleExtension).
};
