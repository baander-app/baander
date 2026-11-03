<?php

declare(strict_types=1);

namespace App\Shared\Application\DTO;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The largest video and audio slot counts a full deployment admits.
 *
 * The pooler's client limit is the only fixed resource bound on media work: web workers,
 * worker roles and media jobs all hold pooled client connections, and PgBouncer refuses
 * clients beyond its `max_client_conn`. The web and worker processes hold a number fixed by
 * the profile; what remains is left for media jobs, each opening its own connections. A kind's
 * maximum is that remainder with the other kind at zero, and together the two kinds must fit
 * it as well.
 */
#[Exclude]
final readonly class MediaSlotBounds
{
    /** PgBouncer's `max_client_conn` in docker/postgres/pooler/pgbouncer.ini; a tooling test keeps the two equal. */
    public const int POOLER_CLIENT_LIMIT = 500;

    /** Each web worker's control connection for manual scheduler occurrences, beside its Doctrine pool. */
    public const int WEB_WORKER_DEDICATED_CONNECTIONS = 1;
    /** An ordinary consumer's Doctrine connection. */
    public const int ORDINARY_CONSUMER_CONNECTIONS = 1;
    /** The scheduled consumer's Doctrine and occurrence execution connections, and its console child's Doctrine connection. */
    public const int SCHEDULED_CONSUMER_CONNECTIONS = 3;
    /** One lease agent per worker unit, each on one control connection. */
    public const int LEASE_AGENT_CONNECTIONS = 2;
    /** The scheduler's Doctrine connection and its recovery, materializer, dispatch and authority control connections. */
    public const int SCHEDULER_CONNECTIONS = 5;
    /** The outbox relay's and the autoscaler's Doctrine connections, and the media manager's Doctrine and two authority connections. */
    public const int CONTROL_ROLE_CONNECTIONS = 5;
    /** A console command and the deployment controller an operator runs beside the deployment. */
    public const int OPERATOR_CONNECTIONS = 2;
    /** A video job's Doctrine, transcode ownership renewal and media authority connections. */
    public const int VIDEO_JOB_CONNECTIONS = 3;
    /** An audio job's Doctrine and media authority connections. */
    public const int AUDIO_JOB_CONNECTIONS = 2;

    /** Pooled client connections the web and worker processes hold, without media jobs. */
    public int $fixedClients;
    /** Pooled client connections left for media jobs. */
    public int $mediaJobClients;
    public int $maximumVideoSlots;
    public int $maximumAudioSlots;

    public function __construct(int $webWorkers, int $doctrinePoolSlots, int $ordinaryConsumers)
    {
        $this->fixedClients = $webWorkers * ($doctrinePoolSlots + self::WEB_WORKER_DEDICATED_CONNECTIONS)
            + $ordinaryConsumers * self::ORDINARY_CONSUMER_CONNECTIONS
            + self::SCHEDULED_CONSUMER_CONNECTIONS
            + self::LEASE_AGENT_CONNECTIONS
            + self::SCHEDULER_CONNECTIONS
            + self::CONTROL_ROLE_CONNECTIONS
            + self::OPERATOR_CONNECTIONS;
        $this->mediaJobClients = self::POOLER_CLIENT_LIMIT - $this->fixedClients;
        if ($this->mediaJobClients < 0) {
            throw new \LogicException('Web and worker processes alone exceed the pooler client limit.');
        }
        $this->maximumVideoSlots = intdiv($this->mediaJobClients, self::VIDEO_JOB_CONNECTIONS);
        $this->maximumAudioSlots = intdiv($this->mediaJobClients, self::AUDIO_JOB_CONNECTIONS);
    }

    /** @throws \InvalidArgumentException naming the bound the slots exceed */
    public function assertAccepts(int $videoSlots, int $audioSlots): void
    {
        foreach (['video' => [$videoSlots, $this->maximumVideoSlots, self::VIDEO_JOB_CONNECTIONS], 'audio' => [$audioSlots, $this->maximumAudioSlots, self::AUDIO_JOB_CONNECTIONS]] as $kind => [$slots, $maximum, $connections]) {
            if ($slots < 0) {
                throw new \InvalidArgumentException(sprintf('Full deployment profile requires zero or more %s slots.', $kind));
            }
            if ($slots > $maximum) {
                throw new \InvalidArgumentException(sprintf(
                    'Full deployment profile accepts at most %d %s slots: the pooler admits %d client connections, web and worker processes hold %d, and each %s job opens %d.',
                    $maximum, $kind, self::POOLER_CLIENT_LIMIT, $this->fixedClients, $kind, $connections,
                ));
            }
        }
        $needed = $this->mediaJobConnections($videoSlots, $audioSlots);
        if ($needed > $this->mediaJobClients) {
            throw new \InvalidArgumentException(sprintf(
                'Full deployment profile cannot run %d video and %d audio slots together: their jobs open %d pooled client connections, more than the %d the pooler\'s %d leave after web and worker processes.',
                $videoSlots, $audioSlots, $needed, $this->mediaJobClients, self::POOLER_CLIENT_LIMIT,
            ));
        }
    }

    public function mediaJobConnections(int $videoSlots, int $audioSlots): int
    {
        return $videoSlots * self::VIDEO_JOB_CONNECTIONS + $audioSlots * self::AUDIO_JOB_CONNECTIONS;
    }
}
