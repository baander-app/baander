<?php

declare(strict_types=1);

use App\Catalog\Application\Command\BatchExtractCoversCommand;
use App\Catalog\Application\Message\DrainAlbumMatchesMessage;
use App\Catalog\Application\Message\DrainVideoMatchesMessage;
use App\Catalog\Application\Message\RefreshVideoMatchMessage;
use App\Library\Application\Command\ScanLibraryCommand;
use App\Library\Application\Message\FilesDiscovered;
use App\Lyrics\Application\Command\FetchLyricsCommand;
use App\Media\Application\Command\GenerateImageDerivativeCommand;
use App\Media\Application\Command\PruneMissingImagesCommand;
use App\Metadata\Application\Command\ExtractAlbumCoverCommand;
use App\Metadata\Application\Message\SyncAlbumMessage;
use App\Metadata\Application\Message\SyncArtistMessage;
use App\Metadata\Application\Message\SyncArtistsMessage;
use App\Metadata\Application\Message\SyncLibraryMessage;
use App\Metadata\Application\Message\SyncMovieMessage;
use App\Metadata\Application\Message\SyncPeopleMessage;
use App\Metadata\Application\Message\SyncSongMessage;
use App\Notification\Application\DTO\SendEmailCommand;
use App\Notification\Application\DTO\SendPushCommand;
use App\Notification\Application\DTO\SendPushToSubscriptionCommand;
use App\Notification\Application\DTO\SendWebhookCommand;
use App\Radio\Application\Command\SyncCountryStationsCommand;
use App\Recommendation\Application\Command\ExecuteRecommendationJobCommand;
use App\Scheduler\Application\Command\ExecuteScheduledOccurrenceCommand;
use App\Shared\Domain\Event\Outbox\RelayOutboxCommand;
use App\Shared\Infrastructure\Messenger\CorrelationIdMiddleware;
use App\Shared\Infrastructure\Messenger\JobCancellationMiddleware;
use App\Shared\Infrastructure\Messenger\JobMonitoringMiddleware;
use App\Shared\Infrastructure\Messenger\JsonTransportSerializer;
use App\Shared\Infrastructure\Messenger\ResultStampMiddleware;
use App\Tests\Fixtures\Messaging\FailureTransportProbe;
use App\Transcode\Application\Command\PrepareTimelinePreview;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $parameters = $container->parameters();
    $parameters->set('app.messenger.failure_table', 'failed_messages');
    $parameters->set('app.messenger.failure_queue', 'failed');

    // Resource classes describe the work; capacity reservations remain worker policy.
    $workloads = [
        BatchExtractCoversCommand::class => [
            'transport' => 'async',
            'resource_class' => 'coordination',
            'default' => true,
        ],
        DrainAlbumMatchesMessage::class => [
            'transport' => 'async',
            'resource_class' => 'io',
            'default' => true,
        ],
        DrainVideoMatchesMessage::class => [
            'transport' => 'async',
            'resource_class' => 'io',
            'default' => true,
        ],
        RefreshVideoMatchMessage::class => [
            'transport' => 'async',
            'resource_class' => 'io',
            'default' => true,
        ],
        ScanLibraryCommand::class => [
            'transport' => 'async',
            'resource_class' => 'io',
            'default' => true,
        ],
        FilesDiscovered::class => [
            'transport' => 'async',
            'resource_class' => 'io',
            'default' => true,
        ],
        ExtractAlbumCoverCommand::class => [
            'transport' => 'async',
            'resource_class' => 'cpu',
            'default' => true,
        ],
        SyncSongMessage::class => [
            'transport' => 'async',
            'resource_class' => 'io',
            'default' => true,
        ],
        SyncAlbumMessage::class => [
            'transport' => 'async',
            'resource_class' => 'io',
            'default' => true,
        ],
        SyncPeopleMessage::class => [
            'transport' => 'async',
            'resource_class' => 'io',
            'default' => true,
        ],
        SyncMovieMessage::class => [
            'transport' => 'async',
            'resource_class' => 'io',
            'default' => true,
        ],
        SyncLibraryMessage::class => [
            'transport' => 'async',
            'resource_class' => 'coordination',
            'default' => true,
        ],
        SyncArtistsMessage::class => [
            'transport' => 'async',
            'resource_class' => 'io',
            'default' => true,
        ],
        SyncArtistMessage::class => [
            'transport' => 'async',
            'resource_class' => 'io',
            'default' => true,
        ],
        SendEmailCommand::class => [
            'transport' => 'async',
            'resource_class' => 'io',
            'default' => true,
        ],
        SendPushCommand::class => [
            'transport' => 'async',
            'resource_class' => 'io',
            'default' => true,
        ],
        SendPushToSubscriptionCommand::class => [
            'transport' => 'async',
            'resource_class' => 'io',
            'default' => true,
        ],
        PruneMissingImagesCommand::class => [
            'transport' => 'async',
            'resource_class' => 'io',
            'default' => true,
        ],
        SyncCountryStationsCommand::class => [
            'transport' => 'async',
            'resource_class' => 'io',
            'default' => true,
        ],
        ExecuteScheduledOccurrenceCommand::class => [
            'transport' => 'scheduler',
            'resource_class' => 'coordination',
            'default' => true,
        ],
        RelayOutboxCommand::class => [
            'transport' => 'async',
            'resource_class' => 'coordination',
            'default' => true,
        ],
        ExecuteRecommendationJobCommand::class => [
            'transport' => 'async',
            'resource_class' => 'cpu',
            'default' => true,
        ],
        GenerateImageDerivativeCommand::class => [
            'transport' => 'async',
            'resource_class' => 'cpu',
            'default' => true,
        ],
        PrepareTimelinePreview::class => [
            'transport' => 'async',
            'resource_class' => 'cpu',
            'default' => true,
        ],
        SendWebhookCommand::class => [
            'transport' => 'async',
            'resource_class' => 'io',
            'default' => false,
        ],
        FetchLyricsCommand::class => ['transport' => 'async', 'resource_class' => 'io', 'default' => false],
    ];
    if ($container->env() === 'test') {
        $workloads[FailureTransportProbe::class] = [
            'transport' => 'async',
            'resource_class' => 'coordination',
            'default' => true,
        ];
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
    $parameters->set('app.messenger.async_observation_options', ['stream' => 'messages'] + $redisOptions);
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
            ['id' => CorrelationIdMiddleware::class],
            // Assign the job ID before storage, then propagate cancellation to handlers.
            ['id' => JobMonitoringMiddleware::class],
            ['id' => JobCancellationMiddleware::class],
            ['id' => ResultStampMiddleware::class],
        ]]],
    ]]);
};
