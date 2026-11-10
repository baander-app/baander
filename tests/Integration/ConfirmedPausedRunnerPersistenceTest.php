<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Session\Application\Port\PlaybackOperation;
use App\Session\Domain\Model\Device\Device;
use App\Session\Domain\Model\Playback\PlaybackMedia;
use App\Session\Domain\Model\Playback\PlaybackState;
use App\Session\Infrastructure\Doctrine\PlaybackStore;
use App\Session\Infrastructure\Doctrine\Repository\DeviceDoctrineRepository;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Session\PlaybackPersistenceFixture;
use App\Tests\Support\FinalSchemaBootstrap;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Fixtures/Session/playback-concurrent-transfer.php';

final class ConfirmedPausedRunnerPersistenceTest extends TestCase
{
    private string $schema;
    private EntityManager $manager;
    private Connection $observer;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Requires disposable PostgreSQL.');
        }
        $this->schema = 'confirmed_pause_' . bin2hex(random_bytes(8));
        $connection = PlaybackPersistenceFixture::connect('public');
        $connection->executeStatement('CREATE SCHEMA ' . $this->schema);
        $connection->executeStatement('SET search_path TO ' . $this->schema . ', public');
        FinalSchemaBootstrap::bootstrap($connection);
        $this->manager = PlaybackPersistenceFixture::manager($connection);
        $this->observer = PlaybackPersistenceFixture::connect($this->schema);
    }

    protected function tearDown(): void
    {
        if (!isset($this->manager)) {
            return;
        }
        $connection = $this->manager->getConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        $this->manager->close();
        $this->observer->close();
        $connection->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
        $connection->close();
    }

    /** @return array{owner:Uuid,incarnation:string,session:string,command:string,queue:list<string>} */
    private function pausedBrowser(): array
    {
        $user = new UserEntity(new PublicId(), 'Paused browser owner', new Uuid()->toString() . '@baander.app', 'unused-password', '');
        $this->manager->persist($user);
        $this->manager->flush();
        $owner = $user->getId();
        $device = new Uuid();
        (new DeviceDoctrineRepository($this->manager))->save(Device::create($owner, $device, 'Paused browser'));
        $endpoint = PlaybackPersistenceFixture::coordinator($this->manager)->execute($owner, PlaybackOperation::Endpoint, [
            'deviceId' => $device->toString(), 'endpointId' => new Uuid()->toString(),
        ])['endpoint'];
        $connection = $this->manager->getConnection();
        $connection->beginTransaction();
        $store = new PlaybackStore($this->manager);
        $session = $store->lockSession($owner->toString());
        $queue = array_map(static fn(): string => new PublicId()->toString(), range(1, 77));
        $session->control('load', new PlaybackMedia('song', PublicId::fromString($queue[12])), $queue, 12, 31.25, PlaybackState::Playing);
        $session->control('pause', null, null, null, null, null);
        $command = new Uuid()->toString();
        $store->insertCommand(['id' => $command, 'session_id' => $session->getId()->toString(), 'user_id' => $owner->toString(),
            'attempt_id' => new Uuid()->toString(), 'request_id' => new Uuid()->toString(), 'request_digest' => 'fixture',
            'incarnation_id' => $endpoint['incarnationId'], 'epoch' => $endpoint['epoch'], 'revision' => $session->revision(), 'type' => 'pause', 'payload' => []]);
        $store->acknowledgeCommand($command, 'paused');
        $session->advanceRevision();
        // Use the registered destination identity, preserving the real foreign key.
        $destination = $connection->fetchOne('SELECT destination_id FROM playback_destination_incarnations WHERE id=?', [$endpoint['incarnationId']]);
        $session->commitDestination(Uuid::fromString($endpoint['incarnationId']), Uuid::fromString($destination), PlaybackState::Paused, 31.25);
        $store->saveSession($session);
        $connection->executeStatement("UPDATE playback_destination_incarnations SET observed_state='paused',expires_at=CURRENT_TIMESTAMP-INTERVAL '1 minute' WHERE id=?", [$endpoint['incarnationId']]);
        $connection->commit();
        return ['owner' => $owner, 'incarnation' => $endpoint['incarnationId'], 'session' => $session->getId()->toString(), 'command' => $command, 'queue' => $queue];
    }

    public function testSnapshotCommitsRetirementAndRetainsSeventySevenItemQueueWithoutInventingAStop(): void
    {
        $fixture = $this->pausedBrowser();
        $result = PlaybackPersistenceFixture::coordinator($this->manager)->execute($fixture['owner'], PlaybackOperation::Snapshot, []);
        self::assertNull($result['activeIncarnationId']);
        self::assertNull($result['selectedDestinationId']);
        self::assertSame($fixture['queue'], $result['queue']);
        self::assertSame(12, $result['currentTrackIndex']);
        self::assertSame(['kind' => 'song', 'publicId' => $fixture['queue'][12]], $result['media']);
        self::assertSame(31.25, $result['position']);
        self::assertSame('paused', $result['desiredState']);
        self::assertSame('paused', $result['observedState']);
        self::assertSame(4, $result['revision']);
        $row = $this->observer->fetchAssociative('SELECT retired_at,observed_state,recovery_result FROM playback_destination_incarnations WHERE id=?', [$fixture['incarnation']]);
        self::assertNotNull($row['retired_at']);
        self::assertSame('paused', $row['observed_state']);
        self::assertNull($row['recovery_result']);
        $again = PlaybackPersistenceFixture::coordinator($this->manager)->execute($fixture['owner'], PlaybackOperation::Snapshot, []);
        self::assertSame(4, $again['revision']);
    }

    public function testUnsafeOrAmbiguousEvidenceNeverRetiresRunner(): void
    {
        $connection = $this->manager->getConnection();
        $changes = [
            "UPDATE playback_commands SET acknowledged_at=NULL,outcome=NULL WHERE id=:command",
            "UPDATE playback_commands SET outcome='rejected' WHERE id=:command",
            "UPDATE playback_commands SET type='load',outcome='applied' WHERE id=:command",
            "UPDATE playback_commands SET epoch=epoch+1 WHERE id=:command",
            "UPDATE playback_commands SET revision=revision-1 WHERE id=:command",
            "UPDATE playback_destination_incarnations SET observed_state='playing' WHERE id=:incarnation",
            "UPDATE playback_destination_incarnations SET expires_at=CURRENT_TIMESTAMP+INTERVAL '1 hour' WHERE id=:incarnation",
            "UPDATE playback_destinations SET kind='cast' WHERE id=(SELECT destination_id FROM playback_destination_incarnations WHERE id=:incarnation)",
            "UPDATE listening_sessions SET desired_state='playing' WHERE id=:session",
            "UPDATE listening_sessions SET observed_state='unknown' WHERE id=:session",
        ];
        foreach ($changes as $change) {
            $fixture = $this->pausedBrowser();
            preg_match('/:(command|incarnation|session)/', $change, $matches);
            $connection->executeStatement($change, [$matches[1] => $fixture[$matches[1]]]);
            $result = PlaybackPersistenceFixture::coordinator($this->manager)->execute($fixture['owner'], PlaybackOperation::Snapshot, []);
            self::assertSame($fixture['incarnation'], $result['activeIncarnationId'], $change);
            self::assertNull($this->observer->fetchOne('SELECT retired_at FROM playback_destination_incarnations WHERE id=?', [$fixture['incarnation']]), $change);
        }
    }

    public function testRetirementAndSessionReleaseRollBackTogether(): void
    {
        $fixture = $this->pausedBrowser();
        $connection = $this->manager->getConnection();
        $connection->beginTransaction();
        PlaybackPersistenceFixture::coordinator($this->manager)->execute($fixture['owner'], PlaybackOperation::Snapshot, []);
        self::assertSame($fixture['incarnation'], $this->observer->fetchOne('SELECT active_incarnation_id FROM listening_sessions WHERE id=?', [$fixture['session']]));
        self::assertNull($this->observer->fetchOne('SELECT retired_at FROM playback_destination_incarnations WHERE id=?', [$fixture['incarnation']]));
        $connection->rollBack();
        self::assertSame($fixture['incarnation'], $connection->fetchOne('SELECT active_incarnation_id FROM listening_sessions WHERE id=?', [$fixture['session']]));
        self::assertNull($connection->fetchOne('SELECT retired_at FROM playback_destination_incarnations WHERE id=?', [$fixture['incarnation']]));
    }

    public function testOtherCommandsAndTransferObligationsBlockRetirement(): void
    {
        $connection = $this->manager->getConnection();
        foreach (['later', 'same revision', 'older pending', 'transfer'] as $case) {
            $fixture = $this->pausedBrowser();
            if ($case === 'transfer') {
                (new PlaybackStore($this->manager))->saveTransfer([
                    'id' => new Uuid()->toString(), 'session_id' => $fixture['session'], 'user_id' => $fixture['owner']->toString(),
                    'request_id' => new Uuid()->toString(), 'request_digest' => 'fixture', 'source_incarnation_id' => $fixture['incarnation'],
                    'target_incarnation_id' => $fixture['incarnation'], 'state' => 'uncertain', 'desired_state' => 'paused', 'position' => 31.25, 'revision' => 3,
                ]);
            } else {
                $connection->executeStatement(<<<'SQL'
                    INSERT INTO playback_commands(id,session_id,user_id,attempt_id,request_id,request_digest,incarnation_id,epoch,revision,type,payload,outcome,acknowledged_at,expires_at,created_at)
                    SELECT :id,session_id,user_id,:attempt,:request,request_digest,incarnation_id,epoch,:revision,'volume',payload,
                        :outcome,CASE WHEN :pending=1 THEN NULL ELSE acknowledged_at END,expires_at,created_at-INTERVAL '1 second'
                    FROM playback_commands WHERE id=:proof
                    SQL, ['id' => new Uuid()->toString(), 'attempt' => new Uuid()->toString(), 'request' => new Uuid()->toString(),
                        'revision' => match ($case) { 'later' => 3, 'same revision' => 2, default => 1 },
                        'outcome' => $case === 'older pending' ? null : 'applied', 'pending' => $case === 'older pending' ? 1 : 0,
                        'proof' => $fixture['command']]);
            }
            $result = PlaybackPersistenceFixture::coordinator($this->manager)->execute($fixture['owner'], PlaybackOperation::Snapshot, []);
            self::assertSame($fixture['incarnation'], $result['activeIncarnationId'], $case);
            self::assertNull($this->observer->fetchOne('SELECT retired_at FROM playback_destination_incarnations WHERE id=?', [$fixture['incarnation']]), $case);
            if ($case === 'transfer') {
                self::assertSame('uncertain', $result['transfer']['state']);
            }
        }
    }

    public function testCommandsSettledAsNeverEffectiveDoNotCountAgainstThePauseAcknowledgement(): void
    {
        $connection = $this->manager->getConnection();
        // The fixture's pause is acknowledged at revision 2 and leaves the session at revision 3. Issuing a
        // control and settling it as never effective advances the revision once each.
        $cases = [
            'one settled command' => ['commands' => [[4, 'never_effective']], 'revision' => 5, 'released' => true],
            'two settled commands' => ['commands' => [[4, 'never_effective'], [6, 'never_effective']], 'revision' => 7, 'released' => true],
            'a superseded command' => ['commands' => [[4, 'superseded']], 'revision' => 5, 'released' => false],
            'a settled and a superseded command' => ['commands' => [[4, 'never_effective'], [6, 'superseded']], 'revision' => 7, 'released' => false],
            'a revision no settled command accounts for' => ['commands' => [[4, 'never_effective']], 'revision' => 6, 'released' => false],
            'a settled command without its revisions' => ['commands' => [[3, 'never_effective']], 'revision' => 3, 'released' => false],
        ];
        foreach ($cases as $case => ['commands' => $commands, 'revision' => $revision, 'released' => $released]) {
            $fixture = $this->pausedBrowser();
            foreach ($commands as [$commandRevision, $outcome]) {
                $connection->executeStatement(<<<'SQL'
                    INSERT INTO playback_commands(id,session_id,user_id,attempt_id,request_id,request_digest,incarnation_id,epoch,revision,type,payload,outcome,acknowledged_at,expires_at,created_at)
                    SELECT :id,session_id,user_id,:attempt,:request,request_digest,incarnation_id,epoch,:revision,'play',payload,
                        :outcome,acknowledged_at,expires_at,created_at+INTERVAL '1 second'
                    FROM playback_commands WHERE id=:proof
                    SQL, ['id' => new Uuid()->toString(), 'attempt' => new Uuid()->toString(), 'request' => new Uuid()->toString(),
                    'revision' => $commandRevision, 'outcome' => $outcome, 'proof' => $fixture['command']]);
            }
            $connection->executeStatement('UPDATE listening_sessions SET revision=:revision WHERE id=:session', ['revision' => $revision, 'session' => $fixture['session']]);

            $result = PlaybackPersistenceFixture::coordinator($this->manager)->execute($fixture['owner'], PlaybackOperation::Snapshot, []);

            self::assertSame($released ? null : $fixture['incarnation'], $result['activeIncarnationId'], $case);
            self::assertSame($released ? $revision + 1 : $revision, $result['revision'], $case);
            self::assertSame($released, $this->observer->fetchOne('SELECT retired_at FROM playback_destination_incarnations WHERE id=?', [$fixture['incarnation']]) !== null, $case);
            self::assertSame(31.25, $result['position'], $case);
        }
    }
}
