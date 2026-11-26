<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Session\Application\Port\PlaybackOperation;
use App\Shared\Application\Exception\MemberInputBoundException;
use App\Tests\Fixtures\Session\PlaybackPersistenceScenarios;
use PHPUnit\Framework\TestCase;

/** A playback session whose stored queue is over its member-input bound, as a row stored before the bound. */
final class PlaybackMemberInputBoundTest extends TestCase
{
    use PlaybackPersistenceScenarios;

    public function testAHeartbeatThatRewritesTheQueueIsAConflictWhoseReasonIsNotAStaleRevision(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $runner = $this->endpoint($service, $owner);
        $playing = $this->playing($service, $owner, $runner);
        $connection = $this->manager->getConnection();
        $connection->executeStatement('ALTER TABLE listening_sessions DROP CONSTRAINT chk_listening_sessions_queue_size');
        $queue = array_map(static fn (int $index): string => sprintf('q%020d', $index), range(0, 11_999));
        $connection->executeStatement('UPDATE listening_sessions SET queue = ?::jsonb WHERE user_id = ?', [json_encode($queue, JSON_THROW_ON_ERROR), $owner->toString()]);
        $connection->executeStatement('ALTER TABLE listening_sessions ADD CONSTRAINT chk_listening_sessions_queue_size CHECK (octet_length(queue::text) <= 262144) NOT VALID');

        $conflict = null;
        try {
            $service->execute($owner, PlaybackOperation::Observe, ['incarnationId' => $runner['incarnationId'], 'expectedRevision' => $playing['revision'], 'observedState' => 'playing', 'position' => 50.0], $runner['capability']);
        } catch (MemberInputBoundException $refused) {
            $conflict = $refused;
        }

        // The web session coordinator drops a control request and resyncs only on playback_control_stale_revision.
        self::assertInstanceOf(MemberInputBoundException::class, $conflict, 'The heartbeat is refused as a conflict.');
        self::assertSame(['reason' => 'member_input_bound'], $conflict->details);
        self::assertSame(42.5, (float) $this->observer->fetchOne('SELECT position FROM listening_sessions WHERE user_id = ?', [$owner->toString()]));
    }
}
