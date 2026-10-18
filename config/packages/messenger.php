<?php

declare(strict_types=1);

use App\Lyrics\Application\Command\FetchLyricsCommand;
use App\Shared\Infrastructure\Messenger\JsonTransportSerializer;
use App\Tests\Fixtures\Messaging\FailureTransportProbe;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $parameters = $container->parameters();
    $parameters->set('app.messenger.failure_table', 'failed_messages');
    $parameters->set('app.messenger.failure_queue', 'failed');

    // Resource classes describe the work; capacity reservations remain worker policy.
    $workloads = [
        \App\Catalog\Application\Command\BatchExtractCoversCommand::class => ['transport' => 'async', 'resource_class' => 'coordination', 'default' => true],
        \App\Library\Application\Command\ScanLibraryCommand::class => ['transport' => 'async', 'resource_class' => 'io', 'default' => true],
        \App\Library\Application\Message\FilesDiscovered::class => ['transport' => 'async', 'resource_class' => 'io', 'default' => true],
        \App\Metadata\Application\Command\ExtractAlbumCoverCommand::class => ['transport' => 'async', 'resource_class' => 'cpu', 'default' => true],
        \App\Metadata\Application\Message\SyncSongMessage::class => ['transport' => 'async', 'resource_class' => 'io', 'default' => true],
        \App\Metadata\Application\Message\SyncAlbumMessage::class => ['transport' => 'async', 'resource_class' => 'io', 'default' => true],
        \App\Metadata\Application\Message\SyncLibraryMessage::class => ['transport' => 'async', 'resource_class' => 'coordination', 'default' => true],
        \App\Notification\Application\DTO\SendEmailCommand::class => ['transport' => 'async', 'resource_class' => 'io', 'default' => true],
        \App\Notification\Application\DTO\SendPushCommand::class => ['transport' => 'async', 'resource_class' => 'io', 'default' => true],
        \App\Media\Application\Command\PruneMissingImagesCommand::class => ['transport' => 'async', 'resource_class' => 'io', 'default' => true],
        \App\Radio\Application\Command\SyncCountryStationsCommand::class => ['transport' => 'async', 'resource_class' => 'io', 'default' => true],
        \App\Scheduler\Application\Command\ExecuteScheduledOccurrenceCommand::class => ['transport' => 'scheduler', 'resource_class' => 'coordination', 'default' => true],
        \App\Shared\Domain\Event\Outbox\RelayOutboxCommand::class => ['transport' => 'async', 'resource_class' => 'coordination', 'default' => true],
        \App\Recommendation\Application\Command\ExecuteRecommendationJobCommand::class => ['transport' => 'async', 'resource_class' => 'cpu', 'default' => true],
        \App\Media\Application\Command\GenerateImageDerivativeCommand::class => ['transport' => 'async', 'resource_class' => 'cpu', 'default' => true],
        \App\Notification\Application\DTO\SendWebhookCommand::class => ['transport' => 'async', 'resource_class' => 'io', 'default' => false],
        FetchLyricsCommand::class => ['transport' => 'async', 'resource_class' => 'io', 'default' => false],
    ];
    if ($container->env() === 'test') {
        $workloads[FailureTransportProbe::class] = ['transport' => 'async', 'resource_class' => 'coordination', 'default' => true];
    }
    $retry = ['max_retries' => 3, 'delay' => 1000, 'multiplier' => 2, 'max_delay' => 60000];
    $policies = ['async' => $retry, 'scheduler' => $retry];
    $parameters->set('app.messenger.workloads', $workloads);
    $parameters->set('app.messenger.transport_policies', $policies);
    $routing = [];
    foreach ($workloads as $message => $workload) {
        if ($workload['default']) {
            $routing[$message] = $workload['transport'];
        }
    }

    $redisOptions = [
        'group' => 'baander',
        'consumer' => '%env(MESSENGER_CONSUMER_NAME)%',
        'delete_after_ack' => true,
        // MAXLEN trims unread and pending deliveries. Retain accepted work until ack.
        'stream_max_entries' => 0,
        'redeliver_timeout' => 3600,
        'claim_interval' => 60000,
    ];
    $container->extension('framework', ['messenger' => [
        'serializer' => ['default_serializer' => JsonTransportSerializer::class],
        'transports' => [
            'async' => [
                'dsn' => $container->env() === 'test' ? 'in-memory://' : '%env(MESSENGER_TRANSPORT_DSN)%',
                'options' => ['stream' => 'messages'] + $redisOptions,
                'retry_strategy' => $policies['async'],
            ],
            // Occurrences require deployment authority; the DSN path selects their stream.
            'scheduler' => [
                'dsn' => $container->env() === 'test' ? 'in-memory://' : '%env(REDIS_URL)%/scheduler_occurrences/baander',
                'options' => ['stream' => 'scheduler_occurrences'] + $redisOptions,
                'retry_strategy' => $policies['scheduler'],
            ],
            // The initial migration owns the listable PostgreSQL failure table.
            'failed' => [
                'dsn' => 'doctrine://default',
                'options' => [
                    'table_name' => '%app.messenger.failure_table%',
                    'queue_name' => '%app.messenger.failure_queue%',
                    'auto_setup' => false,
                    'use_notify' => false,
                ],
                // RelistFailedRetrySubscriber retains failed manual retries for inspection.
                'retry_strategy' => ['max_retries' => 0, 'delay' => 0],
            ],
        ],
        'failure_transport' => 'failed',
        'routing' => $routing,
        'buses' => ['messenger.bus.default' => ['middleware' => [
            ['id' => \App\Shared\Infrastructure\Messenger\CorrelationIdMiddleware::class],
            // Assign the job ID before storage, then propagate cancellation to handlers.
            ['id' => \App\Shared\Infrastructure\Messenger\JobMonitoringMiddleware::class],
            ['id' => \App\Shared\Infrastructure\Messenger\JobCancellationMiddleware::class],
            ['id' => \App\Shared\Infrastructure\Messenger\ResultStampMiddleware::class],
        ]]],
    ]]);
};
