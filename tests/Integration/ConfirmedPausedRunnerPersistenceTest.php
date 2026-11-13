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
                    SELECT :id,session_id,user_id,:attempt,:request,request_digest,incarnation_id,epoch,:revision,:type,payload,
                        :outcome,CASE WHEN :pending=1 THEN NULL ELSE acknowledged_at END,expires_at,created_at-INTERVAL '1 second'
                    FROM playback_commands WHERE id=:proof
                    SQL, ['id' => new Uuid()->toString(), 'attempt' => new Uuid()->toString(), 'request' => new Uuid()->toString(),
                        // A seek can move playback, so an answered one stands against the pause. A volume
                        // change does not, and holds the release only while it is unanswered.
                        'type' => $case === 'older pending' ? 'volume' : 'seek',
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
        // The fixture's pause is acknowledged at revision 2 and leaves the session at revision 3. Each
        // later command is listed as its own revision, its outcome and, for one settled as never
        // effective, the session revision that settlement left.
        /** @var array<string,array{commands:list<array{int,string,int|null}>,revision:int,released:bool}> $cases */
        $cases = [
            'one settled command' => ['commands' => [[4, 'never_effective', 5]], 'revision' => 5, 'released' => true],
            'a settled command another one replaced, then that one settled' => ['commands' => [[4, 'never_effective', 4], [5, 'never_effective', 6]], 'revision' => 6, 'released' => true],
            'two settled commands' => ['commands' => [[4, 'never_effective', 5], [6, 'never_effective', 7]], 'revision' => 7, 'released' => true],
            'a superseded command' => ['commands' => [[4, 'superseded', null]], 'revision' => 5, 'released' => false],
            'a settled and a superseded command' => ['commands' => [[4, 'never_effective', 5], [6, 'superseded', null]], 'revision' => 7, 'released' => false],
            'a superseded command that a settled one replaced' => ['commands' => [[4, 'superseded', null], [5, 'never_effective', 6]], 'revision' => 6, 'released' => false],
            'a session that moved after the last settlement' => ['commands' => [[4, 'never_effective', 5]], 'revision' => 6, 'released' => false],
            'a session behind the last settlement' => ['commands' => [[4, 'never_effective', 6]], 'revision' => 5, 'released' => false],
            'a session that moved without any settlement' => ['commands' => [], 'revision' => 4, 'released' => false],
        ];
        foreach ($cases as $case => ['commands' => $commands, 'revision' => $revision, 'released' => $released]) {
            $fixture = $this->pausedBrowser();
            foreach ($commands as [$commandRevision, $outcome, $settledRevision]) {
                $connection->executeStatement(<<<'SQL'
                    INSERT INTO playback_commands(id,session_id,user_id,attempt_id,request_id,request_digest,incarnation_id,epoch,revision,type,payload,outcome,settled_revision,disclosed_at,acknowledged_at,expires_at,created_at)
                    SELECT :id,session_id,user_id,:attempt,:request,request_digest,incarnation_id,epoch,:revision,'play',payload,
                        :outcome,:settled,CASE WHEN :outcome='superseded' THEN acknowledged_at END,acknowledged_at,expires_at,created_at+INTERVAL '1 second'
                    FROM playback_commands WHERE id=:proof
                    SQL, ['id' => new Uuid()->toString(), 'attempt' => new Uuid()->toString(), 'request' => new Uuid()->toString(),
                    'revision' => $commandRevision, 'outcome' => $outcome, 'settled' => $settledRevision, 'proof' => $fixture['command']]);
            }
            $connection->executeStatement('UPDATE listening_sessions SET revision=:revision WHERE id=:session', ['revision' => $revision, 'session' => $fixture['session']]);

            $result = PlaybackPersistenceFixture::coordinator($this->manager)->execute($fixture['owner'], PlaybackOperation::Snapshot, []);

            self::assertSame($released ? null : $fixture['incarnation'], $result['activeIncarnationId'], $case);
            self::assertSame($released ? $revision + 1 : $revision, $result['revision'], $case);
            self::assertSame($released, $this->observer->fetchOne('SELECT retired_at FROM playback_destination_incarnations WHERE id=?', [$fixture['incarnation']]) !== null, $case);
            self::assertSame(31.25, $result['position'], $case);
        }
    }

    public function testQueueEditsAndVolumeChangesAreNoLaterCommandsWhereTheSessionStandsOneRevisionPastTheLastOfThem(): void
    {
        $connection = $this->manager->getConnection();
        // The fixture's pause is acknowledged at revision 2 and leaves the session at revision 3. A
        // control advances the session once and carries that revision, and its answer or its expiry
        // advances it once more. Each later command is its type, its own revision, its outcome and,
        // for one settled as never effective, the session revision that settlement left.
        /** @var array<string,array{commands:list<array{string,int,string,int|null}>,revision:int,released:bool}> $cases */
        $cases = [
            'an applied volume change' => ['commands' => [['volume', 4, 'applied', null]], 'revision' => 5, 'released' => true],
            'an applied queue edit' => ['commands' => [['queue', 4, 'applied', null]], 'revision' => 5, 'released' => true],
            'a rejected queue edit' => ['commands' => [['queue', 4, 'rejected', null]], 'revision' => 5, 'released' => true],
            'a volume change that expired after the runner was shown it' => ['commands' => [['volume', 4, 'superseded', null]], 'revision' => 5, 'released' => true],
            'two volume changes' => ['commands' => [['volume', 4, 'applied', null], ['volume', 6, 'applied', null]], 'revision' => 7, 'released' => true],
            'a settled play, then a queue edit' => ['commands' => [['play', 4, 'never_effective', 5], ['queue', 6, 'applied', null]], 'revision' => 7, 'released' => true],
            'a queue edit, then a settled play' => ['commands' => [['queue', 4, 'applied', null], ['play', 6, 'never_effective', 7]], 'revision' => 7, 'released' => true],
            'a shown volume change a stop replaced, the stop settled' => ['commands' => [['volume', 4, 'superseded', null], ['stop', 5, 'never_effective', 6]], 'revision' => 6, 'released' => true],
            'a volume change with the session one revision further' => ['commands' => [['volume', 4, 'applied', null]], 'revision' => 6, 'released' => false],
            'a volume change with the session where the change was issued' => ['commands' => [['volume', 4, 'applied', null]], 'revision' => 4, 'released' => false],
            'a queue edit after the last settlement, with the session at that settlement' => ['commands' => [['play', 4, 'never_effective', 5], ['queue', 6, 'applied', null]], 'revision' => 5, 'released' => false],
            'an applied seek' => ['commands' => [['seek', 4, 'applied', null]], 'revision' => 5, 'released' => false],
            'an applied load' => ['commands' => [['load', 4, 'applied', null]], 'revision' => 5, 'released' => false],
            'a shown play between two volume changes' => ['commands' => [['volume', 4, 'applied', null], ['play', 6, 'superseded', null], ['volume', 8, 'applied', null]], 'revision' => 9, 'released' => false],
        ];
        foreach ($cases as $case => ['commands' => $commands, 'revision' => $revision, 'released' => $released]) {
            $fixture = $this->pausedBrowser();
            foreach ($commands as $at => [$type, $commandRevision, $outcome, $settledRevision]) {
                $connection->executeStatement(<<<'SQL'
                    INSERT INTO playback_commands(id,session_id,user_id,attempt_id,request_id,request_digest,incarnation_id,epoch,revision,type,payload,outcome,settled_revision,disclosed_at,acknowledged_at,expires_at,created_at)
                    SELECT :id,session_id,user_id,:attempt,:request,request_digest,incarnation_id,epoch,:revision,:type,payload,
                        :outcome,:settled,CASE WHEN :outcome<>'never_effective' THEN acknowledged_at END,acknowledged_at,expires_at,created_at+make_interval(secs => :age)
                    FROM playback_commands WHERE id=:proof
                    SQL, ['id' => new Uuid()->toString(), 'attempt' => new Uuid()->toString(), 'request' => new Uuid()->toString(),
                    'revision' => $commandRevision, 'type' => $type, 'outcome' => $outcome, 'settled' => $settledRevision, 'age' => $at + 1, 'proof' => $fixture['command']]);
            }
            $connection->executeStatement('UPDATE listening_sessions SET revision=:revision WHERE id=:session', ['revision' => $revision, 'session' => $fixture['session']]);

            $result = PlaybackPersistenceFixture::coordinator($this->manager)->execute($fixture['owner'], PlaybackOperation::Snapshot, []);

            self::assertSame($released ? null : $fixture['incarnation'], $result['activeIncarnationId'], $case);
            self::assertSame($released ? $revision + 1 : $revision, $result['revision'], $case);
            self::assertSame($released, $this->observer->fetchOne('SELECT retired_at FROM playback_destination_incarnations WHERE id=?', [$fixture['incarnation']]) !== null, $case);
            self::assertSame(31.25, $result['position'], $case);
        }
    }

    public function testACommandIsSettledAsNeverEffectiveOnlyWithTheRevisionItLeft(): void
    {
        $fixture = $this->pausedBrowser();
        $connection = $this->manager->getConnection();
        $changes = [
            "UPDATE playback_commands SET outcome='never_effective' WHERE id=:command",
            "UPDATE playback_commands SET settled_revision=revision WHERE id=:command",
            "UPDATE playback_commands SET outcome='never_effective',settled_revision=revision-1 WHERE id=:command",
        ];
        foreach ($changes as $change) {
            try {
                $connection->executeStatement($change, ['command' => $fixture['command']]);
                self::fail($change);
            } catch (\Doctrine\DBAL\Exception\DriverException $error) {
                self::assertStringContainsString('chk_playback_commands_settled_revision', $error->getMessage(), $change);
            }
        }
        self::assertSame(1, $connection->executeStatement("UPDATE playback_commands SET outcome='never_effective',settled_revision=revision WHERE id=:command", ['command' => $fixture['command']]));
    }
}
