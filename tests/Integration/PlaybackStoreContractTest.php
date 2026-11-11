<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Session\Domain\Model\Device\Device;
use App\Session\Domain\Model\Playback\PlaybackMedia;
use App\Session\Domain\Model\Playback\PlaybackState;
use App\Session\Infrastructure\Doctrine\PlaybackStore;
use App\Session\Infrastructure\Doctrine\Repository\DeviceDoctrineRepository;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Application\Exception\ConflictException;
use App\Tests\Fixtures\Session\PlaybackCostFixture;
use App\Tests\Fixtures\Session\PlaybackPersistenceFixture;
use App\Tests\Fixtures\Session\PlaybackStatementLog;
use App\Tests\Support\FinalSchemaBootstrap;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Fixtures/Session/playback-snapshot-cost.php';

/**
 * What the playback store does that the coordinator's tests do not assert: how it resolves a
 * repeated write, which clock stamps a row, and the shape and scalar types of the rows it returns.
 */
final class PlaybackStoreContractTest extends TestCase
{
    private const array INCARNATION_COLUMNS = [
        'id', 'destination_id', 'user_id', 'epoch', 'capability_hash', 'expires_at', 'retired_at', 'observed_volume', 'observed_state', 'position',
        'tab_id', 'local_recovery_eligible', 'recovery_credential_hash', 'recovery_expires_at', 'recovery_revoked_at', 'recovery_result', 'page_id',
    ];
    private const array COMMAND_COLUMNS = [
        'id', 'session_id', 'user_id', 'attempt_id', 'request_id', 'request_digest', 'incarnation_id', 'epoch', 'revision', 'type', 'payload',
        'outcome', 'acknowledged_at', 'expires_at', 'created_at', 'rejection_reason', 'disclosed_at', 'settled_revision',
    ];
    private const array TRANSFER_COLUMNS = [
        'id', 'session_id', 'user_id', 'request_id', 'request_digest', 'source_incarnation_id', 'target_incarnation_id', 'state', 'desired_state',
        'position', 'revision', 'expires_at', 'created_at',
    ];

    private string $schema;
    private EntityManager $manager;
    private Connection $connection;
    private Connection $observer;
    private PlaybackStore $store;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Requires disposable PostgreSQL.');
        }
        $this->schema = 'playback_store_' . bin2hex(random_bytes(8));
        $this->connection = PlaybackPersistenceFixture::connect('public');
        $this->connection->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->connection->executeStatement('SET search_path TO ' . $this->schema . ', public');
        FinalSchemaBootstrap::bootstrap($this->connection);
        $this->manager = PlaybackPersistenceFixture::manager($this->connection);
        $this->observer = PlaybackPersistenceFixture::connect($this->schema);
        $this->store = new PlaybackStore($this->manager);
    }

    protected function tearDown(): void
    {
        if (!isset($this->manager)) {
            return;
        }
        while ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        $this->manager->close();
        $this->observer->close();
        $this->connection->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
        $this->connection->close();
    }

    public function testAnAddressedCommandInsertedTwiceUnderOneReplayKeyIsStoredOnce(): void
    {
        $account = $this->account();
        $first = $this->command($account, ['type' => 'play']);
        $replayed = ['id' => new Uuid()->toString(), 'attempt_id' => new Uuid()->toString(), 'payload' => ['position' => 9.0]] + $first;

        $this->store->insertCommand($first);
        $this->store->insertCommand($replayed);

        $rows = $this->observer->fetchAllAssociative('SELECT id,attempt_id,payload FROM playback_commands WHERE session_id=?', [$account['session']]);
        self::assertCount(1, $rows);
        self::assertSame($first['id'], $rows[0]['id']);
        self::assertSame($first['attempt_id'], $rows[0]['attempt_id']);
        self::assertEquals($first['payload'], json_decode($rows[0]['payload'], true, flags: JSON_THROW_ON_ERROR));
    }

    public function testACommandUnderAnotherRevisionOrTypeIsNotAReplay(): void
    {
        $account = $this->account();
        $first = $this->command($account, ['type' => 'play']);

        $this->store->insertCommand($first);
        $this->store->insertCommand(['id' => new Uuid()->toString(), 'revision' => $first['revision'] + 1] + $first);
        $this->store->insertCommand(['id' => new Uuid()->toString(), 'type' => 'stop'] + $first);

        self::assertSame(3, (int) $this->observer->fetchOne('SELECT count(*) FROM playback_commands WHERE session_id=?', [$account['session']]));
    }

    public function testRetainedCommandsWithEqualKeysAreEachStored(): void
    {
        $account = $this->account();
        $first = $this->command($account, ['type' => 'play', 'incarnation_id' => null, 'epoch' => null]);

        $this->store->insertCommand($first);
        $this->store->insertCommand(['id' => new Uuid()->toString()] + $first);

        $rows = $this->observer->fetchAllAssociative('SELECT outcome,acknowledged_at,incarnation_id,epoch FROM playback_commands WHERE session_id=? ORDER BY id', [$account['session']]);
        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertSame('retained', $row['outcome']);
            self::assertNotNull($row['acknowledged_at']);
            self::assertNull($row['incarnation_id']);
            self::assertNull($row['epoch']);
        }
    }

    public function testSavingATransferAgainUpdatesItsProgressAndRenewsItsExpiryOnly(): void
    {
        $account = $this->account();
        $other = $this->register($account['owner'], $account['device']);
        $first = $this->transfer($account, ['state' => 'preparing', 'position' => 12.5, 'revision' => 3, 'desired_state' => 'paused']);
        $this->store->saveTransfer($first);
        $this->connection->executeStatement("UPDATE playback_transfers SET created_at=created_at-INTERVAL '1 hour',expires_at=expires_at-INTERVAL '1 hour' WHERE id=?", [$first['id']]);
        $before = $this->observer->fetchAssociative('SELECT * FROM playback_transfers WHERE id=?', [$first['id']]);

        $this->store->saveTransfer([
            'state' => 'starting', 'position' => 14.25, 'revision' => 5,
            'request_id' => new Uuid()->toString(), 'request_digest' => 'another digest', 'desired_state' => 'playing',
            'source_incarnation_id' => $other['incarnationId'], 'target_incarnation_id' => $other['incarnationId'],
        ] + $first);

        $after = $this->observer->fetchAssociative('SELECT * FROM playback_transfers WHERE id=?', [$first['id']]);
        self::assertSame(1, (int) $this->observer->fetchOne('SELECT count(*) FROM playback_transfers WHERE session_id=?', [$account['session']]));
        self::assertSame('starting', $after['state']);
        self::assertSame(14.25, (float) $after['position']);
        self::assertSame(5, (int) $after['revision']);
        foreach (['request_id', 'request_digest', 'desired_state', 'source_incarnation_id', 'target_incarnation_id', 'created_at', 'session_id', 'user_id'] as $kept) {
            self::assertSame($before[$kept], $after[$kept], $kept);
        }
        self::assertSame($first['request_digest'], $after['request_digest']);
        self::assertNull($after['source_incarnation_id']);
        self::assertTrue($this->observer->fetchOne("SELECT expires_at>CURRENT_TIMESTAMP+INTERVAL '19 seconds' AND expires_at<=CURRENT_TIMESTAMP+INTERVAL '20 seconds' FROM playback_transfers WHERE id=?", [$first['id']]));
    }

    public function testRegisteringTheSameEndpointAgainKeepsOneDestinationAndRetiresTheEarlierIncarnation(): void
    {
        $owner = $this->owner();
        $device = $this->device($owner);
        $endpoint = new Uuid()->toString();

        $first = $this->store->register($owner, ['deviceId' => $device, 'endpointId' => $endpoint, 'name' => 'First name', 'capabilities' => ['song']]);
        $second = $this->store->register($owner, ['deviceId' => $device, 'endpointId' => $endpoint, 'name' => 'Second name', 'capabilities' => ['song', 'video', 'volume']]);

        self::assertSame(['destinationId', 'incarnationId', 'epoch', 'capability'], array_keys($first));
        self::assertSame($first['destinationId'], $second['destinationId']);
        self::assertNotSame($first['incarnationId'], $second['incarnationId']);
        self::assertNotSame($first['capability'], $second['capability']);
        self::assertSame(1, $first['epoch']);
        self::assertSame(2, $second['epoch']);
        $destinations = $this->observer->fetchAllAssociative('SELECT * FROM playback_destinations WHERE user_id=?', [$owner]);
        self::assertCount(1, $destinations);
        self::assertSame($first['destinationId'], $destinations[0]['public_id']);
        self::assertSame('Second name', $destinations[0]['name']);
        self::assertSame('browser', $destinations[0]['kind']);
        self::assertSame(['song', 'video', 'volume'], json_decode($destinations[0]['capabilities'], true, flags: JSON_THROW_ON_ERROR));
        self::assertSame($device, $destinations[0]['device_id']);
        self::assertSame($endpoint, $destinations[0]['endpoint_id']);
        $incarnations = $this->observer->fetchAllAssociativeIndexed('SELECT id,epoch,retired_at,capability_hash,observed_state,position FROM playback_destination_incarnations WHERE destination_id=?', [$destinations[0]['id']]);
        self::assertCount(2, $incarnations);
        self::assertNotNull($incarnations[$first['incarnationId']]['retired_at']);
        self::assertNull($incarnations[$second['incarnationId']]['retired_at']);
        self::assertSame(hash('sha256', $second['capability']), $incarnations[$second['incarnationId']]['capability_hash']);
        self::assertSame('unknown', $incarnations[$second['incarnationId']]['observed_state']);
        self::assertSame(0.0, (float) $incarnations[$second['incarnationId']]['position']);
    }

    public function testARegistrationNamesItsDestinationAfterTheDeviceAndPlaysSongsAndVideoUnlessToldOtherwise(): void
    {
        $owner = $this->owner();
        $device = $this->device($owner, 'Kitchen browser');

        $this->store->register($owner, ['deviceId' => $device, 'endpointId' => new Uuid()->toString()]);

        $destination = $this->observer->fetchAssociative('SELECT name,capabilities FROM playback_destinations WHERE user_id=?', [$owner]);
        self::assertSame('Kitchen browser', $destination['name']);
        self::assertSame(['song', 'video'], json_decode($destination['capabilities'], true, flags: JSON_THROW_ON_ERROR));
    }

    public function testARegistrationWithLocalRecoveryStoresItsRecoveryAuthorityAndOneWithoutStoresNone(): void
    {
        $owner = $this->owner();
        $device = $this->device($owner);
        $tab = new Uuid()->toString();

        $plain = $this->store->register($owner, ['deviceId' => $device, 'endpointId' => new Uuid()->toString(), 'tabId' => $tab]);
        $recoverable = $this->store->register($owner, ['deviceId' => $device, 'endpointId' => new Uuid()->toString(), 'tabId' => $tab, 'localRecoveryEligible' => true]);

        self::assertSame(['destinationId', 'incarnationId', 'epoch', 'capability'], array_keys($plain));
        self::assertSame(['destinationId', 'incarnationId', 'epoch', 'capability', 'recoveryCredential', 'recoveryExpiresAt', 'recoveryTabId'], array_keys($recoverable));
        self::assertSame($tab, $recoverable['recoveryTabId']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $recoverable['recoveryCredential']);
        $columns = 'tab_id,local_recovery_eligible,recovery_credential_hash,recovery_expires_at,recovery_revoked_at,recovery_result';
        self::assertSame(
            ['tab_id' => null, 'local_recovery_eligible' => false, 'recovery_credential_hash' => null, 'recovery_expires_at' => null, 'recovery_revoked_at' => null, 'recovery_result' => null],
            $this->observer->fetchAssociative("SELECT {$columns} FROM playback_destination_incarnations WHERE id=?", [$plain['incarnationId']]),
        );
        $stored = $this->observer->fetchAssociative("SELECT {$columns} FROM playback_destination_incarnations WHERE id=?", [$recoverable['incarnationId']]);
        self::assertSame($tab, $stored['tab_id']);
        self::assertTrue($stored['local_recovery_eligible']);
        self::assertSame(hash('sha256', $recoverable['recoveryCredential']), $stored['recovery_credential_hash']);
        self::assertNull($stored['recovery_revoked_at']);
        self::assertNull($stored['recovery_result']);
        // The expiry is the instant the registration answered, thirty days ahead.
        self::assertSame(new \DateTimeImmutable($recoverable['recoveryExpiresAt'])->getTimestamp(), new \DateTimeImmutable($stored['recovery_expires_at'])->getTimestamp());
        self::assertEqualsWithDelta(time() + 30 * 86400, new \DateTimeImmutable($stored['recovery_expires_at'])->getTimestamp(), 60);
    }

    public function testARegistrationStoresThePageIdentityItNamesAndASnapshotNeverShowsIt(): void
    {
        $owner = $this->owner();
        $device = $this->device($owner);
        $tab = ['deviceId' => $device, 'endpointId' => new Uuid()->toString()];
        $page = new Uuid()->toString();

        $named = $this->store->register($owner, ['pageId' => $page] + $tab);
        $unnamed = $this->store->register($owner, $tab);

        self::assertSame(['destinationId', 'incarnationId', 'epoch', 'capability'], array_keys($named));
        self::assertSame($page, $this->observer->fetchOne('SELECT page_id FROM playback_destination_incarnations WHERE id=?', [$named['incarnationId']]));
        self::assertNull($this->observer->fetchOne('SELECT page_id FROM playback_destination_incarnations WHERE id=?', [$unnamed['incarnationId']]));
        self::assertSame($page, $this->store->endpoint($owner, $named['incarnationId'])['page_id']);
        self::assertNull($this->store->endpoint($owner, $unnamed['incarnationId'])['page_id']);
        $snapshot = $this->store->snapshot($this->store->lockSession($owner), $named['incarnationId']);
        self::assertStringNotContainsString($page, json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    public function testRetiringAnIncarnationEndsItsRegistrationOnceAndRecordsOnlyWhatItIsTold(): void
    {
        $account = $this->account();
        $this->store->observe($account['incarnation'], 'playing', 2.0, 0.5);
        $row = fn(): array => $this->connection->fetchAssociative(
            'SELECT observed_state,position,observed_volume,retired_at=CURRENT_TIMESTAMP AS stamped,expires_at,capability_hash FROM playback_destination_incarnations WHERE id=?',
            [$account['incarnation']],
        );
        ['capability_hash' => $capability, 'expires_at' => $expires] = $row();

        // Retired without proof of silence: what the incarnation last reported stands.
        $this->connection->beginTransaction();
        $this->store->retireIncarnation($account['incarnation'], stopped: false);
        self::assertSame(['observed_state' => 'playing', 'position' => 2.0, 'observed_volume' => 0.5, 'stamped' => true, 'expires_at' => $expires, 'capability_hash' => $capability], $row());
        $this->connection->commit();
        $retired = $this->observer->fetchOne('SELECT retired_at FROM playback_destination_incarnations WHERE id=?', [$account['incarnation']]);
        self::assertFalse($this->store->endpoint($account['owner'], $account['incarnation'])['available']);

        // Recorded as stopped, then as stopped at a reported cursor; the first retirement stands.
        $this->store->retireIncarnation($account['incarnation'], stopped: true);
        self::assertSame(['stopped', 2.0], [$row()['observed_state'], $row()['position']]);
        $this->store->retireIncarnation($account['incarnation'], stopped: true, position: 9.5);
        self::assertSame(['stopped', 9.5], [$row()['observed_state'], $row()['position']]);
        self::assertSame($retired, $this->observer->fetchOne('SELECT retired_at FROM playback_destination_incarnations WHERE id=?', [$account['incarnation']]));
        // Neither the presence it last reported nor its capability changes.
        self::assertSame([$expires, $capability], [$row()['expires_at'], $row()['capability_hash']]);
    }

    public function testASuccessorTakesOverTheUnansweredCommandsAndTheOpenTransferOfTheIncarnationItSucceeds(): void
    {
        $account = $this->account();
        $earlier = $account['incarnation'];
        $successor = $this->store->register($account['owner'], ['deviceId' => $account['device'], 'endpointId' => new Uuid()->toString()]);
        $answered = $this->command($account, ['type' => 'pause', 'revision' => 5]);
        $shown = $this->command($account, ['type' => 'play', 'revision' => 6]);
        $elsewhere = $this->command($account, ['type' => 'volume', 'revision' => 6, 'incarnation_id' => $successor['incarnationId'], 'epoch' => $successor['epoch']]);
        foreach ([$answered, $shown, $elsewhere] as $command) {
            $this->store->insertCommand($command);
        }
        $this->store->acknowledgeCommand($answered['id'], 'paused');
        $this->store->snapshot($this->store->lockSession($account['owner']), $earlier);
        $settled = $this->transfer($account, ['state' => 'failed', 'source_incarnation_id' => $earlier]);
        $open = $this->transfer($account, ['state' => 'quiescing', 'source_incarnation_id' => $earlier]);
        $this->store->saveTransfer($settled);
        $this->store->saveTransfer($open);
        $command = fn(string $id): array => $this->observer->fetchAssociative('SELECT incarnation_id,epoch,revision,disclosed_at IS NOT NULL AS shown,outcome,expires_at FROM playback_commands WHERE id=?', [$id]);
        $transfer = fn(string $id): array => $this->observer->fetchAssociative('SELECT source_incarnation_id,target_incarnation_id,state,expires_at FROM playback_transfers WHERE id=?', [$id]);
        $before = ['answered' => $command($answered['id']), 'shown' => $command($shown['id']), 'elsewhere' => $command($elsewhere['id']), 'settled' => $transfer($settled['id']), 'open' => $transfer($open['id'])];
        self::assertTrue($before['shown']['shown']);

        $this->store->succeedIncarnation($earlier, $successor['incarnationId'], $successor['epoch']);

        // Only the unanswered command moves; it keeps its revision, its expiry and the mark that it was shown.
        self::assertSame(['incarnation_id' => $successor['incarnationId'], 'epoch' => $successor['epoch']] + $before['shown'], $command($shown['id']));
        self::assertSame($before['answered'], $command($answered['id']));
        self::assertSame($before['elsewhere'], $command($elsewhere['id']));
        // Only the open transfer is re-pointed, in each role the earlier incarnation held.
        self::assertSame(['source_incarnation_id' => $successor['incarnationId'], 'target_incarnation_id' => $successor['incarnationId']] + $before['open'], $transfer($open['id']));
        self::assertSame($before['settled'], $transfer($settled['id']));
    }

    public function testStoredInstantsAreTheTransactionsDatabaseClock(): void
    {
        $account = $this->account();
        $this->connection->beginTransaction();
        // The transaction's clock stands still while the wall clock moves on.
        $this->connection->executeQuery('SELECT pg_sleep(0.05)')->free();
        $addressed = $this->command($account, ['type' => 'play']);
        $retained = $this->command($account, ['type' => 'pause', 'incarnation_id' => null, 'epoch' => null]);
        $transfer = $this->transfer($account);

        $this->store->insertCommand($addressed);
        $this->store->insertCommand($retained);
        $this->store->saveTransfer($transfer);
        $this->store->acknowledgeCommand($addressed['id'], 'playing');
        $registered = $this->register($account['owner'], $account['device']);
        $this->store->observe($account['incarnation'], 'paused', 3.5);

        $facts = $this->connection->fetchAssociative(<<<'SQL'
            SELECT
                (SELECT created_at=CURRENT_TIMESTAMP AND acknowledged_at=CURRENT_TIMESTAMP AND expires_at=CURRENT_TIMESTAMP+INTERVAL '20 seconds'
                    FROM playback_commands WHERE id=:addressed) AS addressed,
                (SELECT created_at=CURRENT_TIMESTAMP AND acknowledged_at=CURRENT_TIMESTAMP AND expires_at=CURRENT_TIMESTAMP+INTERVAL '20 seconds'
                    FROM playback_commands WHERE id=:retained) AS retained,
                (SELECT created_at=CURRENT_TIMESTAMP AND expires_at=CURRENT_TIMESTAMP+INTERVAL '20 seconds'
                    FROM playback_transfers WHERE id=:transfer) AS transfer,
                (SELECT expires_at=CURRENT_TIMESTAMP+INTERVAL '30 seconds' FROM playback_destination_incarnations WHERE id=:registered) AS registered,
                (SELECT expires_at=CURRENT_TIMESTAMP+INTERVAL '30 seconds' FROM playback_destination_incarnations WHERE id=:observed) AS observed,
                (SELECT created_at=CURRENT_TIMESTAMP FROM playback_destinations d
                    JOIN playback_destination_incarnations i ON i.destination_id=d.id WHERE i.id=:registered) AS destination,
                clock_timestamp()>CURRENT_TIMESTAMP+INTERVAL '50 milliseconds' AS moved
            SQL, ['addressed' => $addressed['id'], 'retained' => $retained['id'], 'transfer' => $transfer['id'],
            'registered' => $registered['incarnationId'], 'observed' => $account['incarnation']]);
        $this->connection->commit();

        self::assertSame(
            ['addressed' => true, 'retained' => true, 'transfer' => true, 'registered' => true, 'observed' => true, 'destination' => true, 'moved' => true],
            $facts,
        );
    }

    public function testASnapshotInANestedTransactionReturnsAndMarksNoCommands(): void
    {
        $account = $this->account();
        $command = $this->command($account, ['type' => 'play']);
        $this->store->insertCommand($command);
        $session = $this->store->lockSession($account['owner']);
        $disclosed = fn(): mixed => $this->connection->fetchOne('SELECT disclosed_at FROM playback_commands WHERE id=?', [$command['id']]);

        $this->connection->beginTransaction();
        $this->connection->beginTransaction();
        $nested = $this->store->snapshot($session, $account['incarnation']);
        self::assertSame([], $nested['commands']);
        self::assertNull($disclosed());
        $this->connection->commit();

        $outer = $this->store->snapshot($session, $account['incarnation']);
        self::assertSame([$command['id']], array_column($outer['commands'], 'id'));
        $shown = $disclosed();
        self::assertNotNull($shown);
        $this->connection->commit();

        // Outside any transaction the same command is returned again and keeps its first mark.
        self::assertSame([$command['id']], array_column($this->store->snapshot($session, $account['incarnation'])['commands'], 'id'));
        self::assertSame($shown, $disclosed());
        // A snapshot for no incarnation, or for another one, returns and marks nothing of it.
        self::assertSame([], $this->store->snapshot($session, null)['commands']);
    }

    public function testRowsCarryColumnNamesIdentifiersAsStringsInstantsAsParsableStringsAndJsonAsArrays(): void
    {
        $account = $this->account(recoverable: true);
        $command = $this->command($account, ['type' => 'play', 'payload' => ['media' => ['kind' => 'song', 'publicId' => 'track'], 'queue' => ['track'], 'position' => 30.5]]);
        $this->store->insertCommand($command);
        $transfer = $this->transfer($account);
        $this->store->saveTransfer($transfer);

        $endpoint = $this->store->endpoint($account['owner'], $account['incarnation']);
        self::assertEqualsCanonicalizing([...self::INCARNATION_COLUMNS, 'public_id', 'kind', 'capabilities', 'available'], array_keys($endpoint));
        self::assertSame($account['incarnation'], $endpoint['id']);
        self::assertSame($account['owner'], $endpoint['user_id']);
        self::assertSame($account['destinationId'], $endpoint['public_id']);
        self::assertSame($account['tab'], $endpoint['tab_id']);
        self::assertSame(36, strlen($endpoint['destination_id']));
        self::assertSame('browser', $endpoint['kind']);
        self::assertSame(['song', 'video'], $endpoint['capabilities']);
        self::assertSame('unknown', $endpoint['observed_state']);
        self::assertNull($endpoint['retired_at']);
        self::assertNull($endpoint['recovery_result']);
        $this->assertInstant('playback_destination_incarnations', 'expires_at', $account['incarnation'], $endpoint['expires_at']);
        $this->assertInstant('playback_destination_incarnations', 'recovery_expires_at', $account['incarnation'], $endpoint['recovery_expires_at']);

        $recovery = $this->store->localRecovery($account['owner'], $account['incarnation'], $account['tab'], $account['recoveryCredential']);
        self::assertEqualsCanonicalizing(self::INCARNATION_COLUMNS, array_keys($recovery));
        self::assertSame($account['incarnation'], $recovery['id']);
        self::assertNull($recovery['recovery_result']);
        $this->store->completeLocalRecovery($account['incarnation'], ['incarnationId' => $account['incarnation'], 'outcome' => 'recovered', 'revision' => 4]);
        // jsonb does not keep key order, so stored documents are compared by value.
        self::assertEquals(
            ['incarnationId' => $account['incarnation'], 'outcome' => 'recovered', 'revision' => 4],
            $this->store->localRecovery($account['owner'], $account['incarnation'], $account['tab'], $account['recoveryCredential'])['recovery_result'],
        );
        self::assertEquals(
            ['incarnationId' => $account['incarnation'], 'outcome' => 'recovered', 'revision' => 4],
            $this->store->endpoint($account['owner'], $account['incarnation'])['recovery_result'],
        );

        foreach (['command' => $this->store->command($account['owner'], $command['id']), 'pendingControl' => $this->store->pendingControl($account['session'])] as $read => $row) {
            self::assertIsArray($row, $read);
            self::assertEqualsCanonicalizing(self::COMMAND_COLUMNS, array_keys($row), $read);
            foreach (['id', 'session_id', 'user_id', 'attempt_id', 'request_id', 'incarnation_id', 'request_digest', 'type'] as $kept) {
                self::assertSame($command[$kept], $row[$kept], $read . ' ' . $kept);
            }
            self::assertEquals(['media' => ['kind' => 'song', 'publicId' => 'track'], 'queue' => ['track'], 'position' => 30.5], $row['payload'], $read);
            foreach (['outcome', 'acknowledged_at', 'rejection_reason', 'disclosed_at', 'settled_revision'] as $unset) {
                self::assertNull($row[$unset], $read . ' ' . $unset);
            }
            $this->assertInstant('playback_commands', 'expires_at', $command['id'], $row['expires_at']);
            $this->assertInstant('playback_commands', 'created_at', $command['id'], $row['created_at']);
        }

        $current = $this->store->currentTransfer($account['session']);
        self::assertIsArray($current);
        self::assertEqualsCanonicalizing(self::TRANSFER_COLUMNS, array_keys($current));
        foreach (['id', 'session_id', 'user_id', 'request_id', 'request_digest', 'target_incarnation_id', 'state', 'desired_state'] as $kept) {
            self::assertSame($transfer[$kept], $current[$kept], $kept);
        }
        self::assertNull($current['source_incarnation_id']);
        $this->assertInstant('playback_transfers', 'expires_at', $transfer['id'], $current['expires_at']);
        $this->assertInstant('playback_transfers', 'created_at', $transfer['id'], $current['created_at']);

        $snapshot = $this->store->snapshot($this->store->lockSession($account['owner']), $account['incarnation']);
        self::assertSame(['id', 'attemptId', 'revision', 'incarnationId', 'epoch', 'type', 'payload', 'expiresAt'], array_keys($snapshot['commands'][0]));
        self::assertEquals(['media' => ['kind' => 'song', 'publicId' => 'track'], 'queue' => ['track'], 'position' => 30.5], $snapshot['commands'][0]['payload']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}\+00:00$/D', $snapshot['commands'][0]['expiresAt']);
        self::assertSame(
            ['destinationId', 'name', 'kind', 'capabilities', 'incarnationId', 'epoch', 'observedState', 'position', 'observedVolume', 'available'],
            array_keys($snapshot['destinations'][0]),
        );
        self::assertSame(['attemptId', 'state', 'sourceIncarnationId', 'targetIncarnationId', 'position', 'desiredState'], array_keys($snapshot['transfer']));
    }

    public function testNumericAndBooleanValuesKeepTheirTypes(): void
    {
        $account = $this->account(recoverable: true);
        $command = $this->command($account, ['type' => 'play']);
        $this->store->insertCommand($command);
        $this->store->saveTransfer($this->transfer($account, ['position' => 12.0]));
        // Each map names the PHP type of a value the coordinator or the browser receives. The comparison
        // is by key, because the order of a row's keys is not part of what the store promises.
        $types = static fn(array $row, array $keys): array => array_map(get_debug_type(...), array_intersect_key($row, array_flip($keys)));

        $unobserved = $this->store->endpoint($account['owner'], $account['incarnation']);
        self::assertEquals(
            ['epoch' => 'int', 'observed_volume' => 'null', 'position' => 'float', 'local_recovery_eligible' => 'bool', 'available' => 'bool'],
            $types($unobserved, ['available', 'epoch', 'position', 'observed_volume', 'local_recovery_eligible']),
        );
        self::assertTrue($unobserved['available']);
        self::assertTrue($unobserved['local_recovery_eligible']);
        self::assertSame(1, $unobserved['epoch']);
        self::assertSame(0.0, $unobserved['position']);

        $this->store->observe($account['incarnation'], 'paused', 12.0, 0.5);
        $observed = $this->store->endpoint($account['owner'], $account['incarnation']);
        self::assertEquals(
            ['epoch' => 'int', 'observed_volume' => 'float', 'position' => 'float', 'local_recovery_eligible' => 'bool', 'available' => 'bool'],
            $types($observed, ['available', 'epoch', 'position', 'observed_volume', 'local_recovery_eligible']),
        );
        self::assertSame(12.0, $observed['position']);
        self::assertSame(0.5, $observed['observed_volume']);

        $recovery = $this->store->localRecovery($account['owner'], $account['incarnation'], $account['tab'], $account['recoveryCredential']);
        self::assertEquals(
            ['epoch' => 'int', 'observed_volume' => 'float', 'position' => 'float', 'local_recovery_eligible' => 'bool'],
            $types($recovery, ['epoch', 'position', 'observed_volume', 'local_recovery_eligible']),
        );

        foreach (['command' => $this->store->command($account['owner'], $command['id']), 'pendingControl' => $this->store->pendingControl($account['session'])] as $read => $row) {
            self::assertIsArray($row, $read);
            self::assertEquals(['epoch' => 'int', 'revision' => 'int'], $types($row, ['epoch', 'revision']), $read);
            self::assertSame([1, 7], [$row['epoch'], $row['revision']], $read);
        }

        $transfer = $this->store->currentTransfer($account['session']);
        self::assertIsArray($transfer);
        self::assertEquals(['position' => 'float', 'revision' => 'int'], $types($transfer, ['position', 'revision']));
        self::assertSame([12.0, 7], [$transfer['position'], $transfer['revision']]);

        $snapshot = $this->store->snapshot($this->store->lockSession($account['owner']), $account['incarnation']);
        self::assertEquals(
            ['epoch' => 'int', 'position' => 'float', 'observedVolume' => 'float', 'available' => 'bool'],
            $types($snapshot['destinations'][0], ['available', 'epoch', 'position', 'observedVolume']),
        );
        self::assertSame([1, 12.0, 0.5, true], [$snapshot['destinations'][0]['epoch'], $snapshot['destinations'][0]['position'], $snapshot['destinations'][0]['observedVolume'], $snapshot['destinations'][0]['available']]);
        self::assertEquals(['revision' => 'int', 'epoch' => 'int'], $types($snapshot['commands'][0], ['epoch', 'revision']));
        self::assertEquals(
            ['revision' => 'int', 'currentTrackIndex' => 'int', 'position' => 'float'],
            $types($snapshot, ['revision', 'currentTrackIndex', 'position']),
        );
        self::assertSame(['position' => 'float'], $types($snapshot['transfer'], ['position']));

        // A destination whose incarnation never reported a volume carries none, and one that lapsed is unavailable.
        $this->connection->executeStatement("UPDATE playback_destination_incarnations SET observed_volume=NULL,expires_at=CURRENT_TIMESTAMP-INTERVAL '1 second' WHERE id=?", [$account['incarnation']]);
        $lapsed = $this->store->snapshot($this->store->lockSession($account['owner']), null)['destinations'][0];
        self::assertNull($lapsed['observedVolume']);
        self::assertFalse($lapsed['available']);
        self::assertFalse($this->store->endpoint($account['owner'], $account['incarnation'])['available']);
    }

    public function testASessionSavedWithoutMediaReadsBackWithoutMediaAndOneWithMediaKeepsIt(): void
    {
        $owner = $this->owner();
        $session = $this->store->lockSession($owner);
        self::assertNull($session->media());
        self::assertSame(0, $session->revision());
        self::assertSame(PlaybackState::Stopped, $session->desiredState());

        $session->control('queue', null, [], 0, null, null);
        $this->store->saveSession($session);
        $saved = $this->store->lockSession($owner);
        self::assertNull($saved->media());
        self::assertSame(1, $saved->revision());
        self::assertNull($this->store->snapshot($saved, null)['media']);

        $track = new PublicId();
        $saved->control('load', new PlaybackMedia('song', $track), [$track->toString()], 0, 4.5, PlaybackState::Paused);
        $this->store->saveSession($saved);
        $loaded = $this->store->lockSession($owner);
        self::assertSame(['kind' => 'song', 'publicId' => $track->toString()], $loaded->media()?->toArray());
        self::assertSame([$track->toString()], $loaded->getQueue());
        self::assertSame(4.5, $loaded->getPosition());
        self::assertSame(PlaybackState::Paused, $loaded->desiredState());
        self::assertSame($session->getId()->toString(), $loaded->getId()->toString());
    }

    public function testTheFirstLockOfAnAccountCreatesItsSessionAndLaterLocksInsertNothing(): void
    {
        $owner = $this->owner();
        $log = new PlaybackStatementLog();
        $connection = PlaybackCostFixture::countingConnection($this->schema, $log);
        $store = new PlaybackStore(PlaybackPersistenceFixture::manager($connection));
        $inserts = static fn(array $statements): int => count(array_filter($statements, static fn(string $statement): bool => str_contains($statement, 'INSERT INTO listening_sessions')));

        $created = null;
        $first = $log->during(function () use ($store, $owner, &$created): void {
            $created = $store->lockSession($owner);
        });
        self::assertSame(1, $inserts($first));
        self::assertSame(0, $created->revision());
        self::assertSame(PlaybackState::Stopped, $created->desiredState());
        self::assertSame('unknown', $created->observedState()->value);
        self::assertSame([], $created->getQueue());
        self::assertNull($created->media());
        self::assertNull($created->activeIncarnationId());
        self::assertSame($owner, $created->getUserId()->toString());

        $again = null;
        $second = $log->during(function () use ($store, $owner, &$again): void {
            $again = $store->lockSession($owner);
        });
        $connection->close();
        self::assertSame(0, $inserts($second));
        self::assertLessThan(count($first), count($second));
        self::assertSame($created->getId()->toString(), $again->getId()->toString());
        self::assertSame($created->getPublicId()->toString(), $again->getPublicId()->toString());
        self::assertEquals($created->getCreatedAt(), $again->getCreatedAt());
        self::assertSame(1, (int) $this->observer->fetchOne('SELECT count(*) FROM listening_sessions WHERE user_id=?', [$owner]));
    }

    public function testTheSessionLockIsARowLockHeldUntilTheTransactionEnds(): void
    {
        $owner = $this->owner();
        $this->store->lockSession($owner);

        $this->connection->beginTransaction();
        $this->store->lockSession($owner);
        self::assertFalse($this->observer->fetchOne('SELECT count(*)=1 FROM (SELECT 1 FROM listening_sessions WHERE user_id=? FOR UPDATE SKIP LOCKED) free', [$owner]));
        $this->connection->commit();

        self::assertTrue($this->observer->fetchOne('SELECT count(*)=1 FROM (SELECT 1 FROM listening_sessions WHERE user_id=? FOR UPDATE SKIP LOCKED) free', [$owner]));
    }

    public function testASnapshotNamesTheSelectedDestinationAndTheOneItsActiveIncarnationBelongsTo(): void
    {
        $owner = $this->owner();
        $device = $this->device($owner);
        $endpoint = new Uuid()->toString();
        [$earlier, $latest] = array_map(fn(): array => $this->store->register($owner, ['deviceId' => $device, 'endpointId' => $endpoint]), range(1, 2));
        $other = $this->register($owner, $device);
        $this->store->lockSession($owner);
        $point = function (?string $selected, ?string $active) use ($owner): array {
            $this->connection->executeStatement(
                'UPDATE listening_sessions SET selected_destination_id=(SELECT id FROM playback_destinations WHERE public_id=?), active_incarnation_id=? WHERE user_id=?',
                [$selected, $active, $owner],
            );
            $snapshot = $this->store->snapshot($this->store->lockSession($owner), null);
            return [$snapshot['selectedDestinationId'], $snapshot['activeDestinationId'], $snapshot['activeIncarnationId']];
        };

        self::assertSame($earlier['destinationId'], $latest['destinationId']);
        self::assertSame([null, null, null], $point(null, null));
        self::assertSame([$other['destinationId'], $latest['destinationId'], $latest['incarnationId']], $point($other['destinationId'], $latest['incarnationId']));
        // A later registration of the tab replaced the incarnation that still holds the session.
        self::assertSame([$other['destinationId'], $latest['destinationId'], $earlier['incarnationId']], $point($other['destinationId'], $earlier['incarnationId']));
        self::assertSame([$latest['destinationId'], null, null], $point($latest['destinationId'], null));
    }

    public function testASnapshotCarriesEachDestinationOnceWithItsLatestIncarnation(): void
    {
        $owner = $this->owner();
        $device = $this->device($owner);
        $endpoint = new Uuid()->toString();
        $registrations = array_map(fn(): array => $this->store->register($owner, ['deviceId' => $device, 'endpointId' => $endpoint]), range(1, 3));
        $empty = $this->store->register($owner, ['deviceId' => $device, 'endpointId' => new Uuid()->toString(), 'name' => 'Never connected']);
        $this->connection->executeStatement('DELETE FROM playback_destination_incarnations WHERE id=?', [$empty['incarnationId']]);
        $this->connection->executeStatement("UPDATE playback_destinations SET created_at=created_at+INTERVAL '1 second' WHERE public_id=?", [$empty['destinationId']]);
        $this->store->observe($registrations[2]['incarnationId'], 'paused', 8.5, 0.25);

        $destinations = $this->store->snapshot($this->store->lockSession($owner), null)['destinations'];

        self::assertSame([$registrations[0]['destinationId'], $empty['destinationId']], array_column($destinations, 'destinationId'));
        self::assertSame(
            ['destinationId' => $registrations[0]['destinationId'], 'name' => 'Playback store browser', 'kind' => 'browser', 'capabilities' => ['song', 'video'],
                'incarnationId' => $registrations[2]['incarnationId'], 'epoch' => 3, 'observedState' => 'paused', 'position' => 8.5, 'observedVolume' => 0.25, 'available' => true],
            $destinations[0],
        );
        self::assertSame(
            ['destinationId' => $empty['destinationId'], 'name' => 'Never connected', 'kind' => 'browser', 'capabilities' => ['song', 'video'],
                'incarnationId' => null, 'epoch' => null, 'observedState' => null, 'position' => null, 'observedVolume' => null, 'available' => false],
            $destinations[1],
        );
    }

    public function testAnEndpointsAvailabilityFollowsItsRegistrationOnEveryRead(): void
    {
        $account = $this->account();
        $available = fn(): bool => $this->store->endpoint($account['owner'], $account['incarnation'])['available'];
        self::assertTrue($available());

        $this->connection->executeStatement("UPDATE playback_destination_incarnations SET expires_at=CURRENT_TIMESTAMP-INTERVAL '1 second' WHERE id=?", [$account['incarnation']]);
        self::assertFalse($available());
        $this->connection->executeStatement("UPDATE playback_destination_incarnations SET expires_at=CURRENT_TIMESTAMP+INTERVAL '1 hour' WHERE id=?", [$account['incarnation']]);
        self::assertTrue($available());
        $this->connection->executeStatement('UPDATE playback_destination_incarnations SET retired_at=CURRENT_TIMESTAMP WHERE id=?', [$account['incarnation']]);
        self::assertFalse($available());
    }

    public function testAnEndpointIsReadOnlyForItsOwnerAndWithItsCapability(): void
    {
        $account = $this->account();
        $capability = $this->register($account['owner'], $account['device']);
        $stranger = $this->owner();

        self::assertSame($capability['incarnationId'], $this->store->endpoint($account['owner'], $capability['incarnationId'], $capability['capability'])['id']);
        foreach ([[$stranger, $capability['incarnationId'], null], [$account['owner'], $capability['incarnationId'], 'another capability'], [$account['owner'], new Uuid()->toString(), null]] as [$owner, $incarnation, $presented]) {
            try {
                $this->store->endpoint($owner, $incarnation, $presented);
                self::fail('An endpoint was read without authority over it.');
            } catch (ConflictException $error) {
                self::assertSame('Endpoint authority is invalid.', $error->getMessage());
            }
        }
    }

    public function testARequestedTransferCarriesTheReasonItWasFirstRefusedFor(): void
    {
        $account = $this->account();
        $transfer = $this->transfer($account);
        $this->store->saveTransfer($transfer);
        self::assertSame(
            ['id' => $transfer['id'], 'request_id' => $transfer['request_id'], 'state' => 'preparing', 'rejection_reason' => null],
            $this->store->requestedTransfer($account['session'], $transfer['request_id']),
        );
        self::assertNull($this->store->requestedTransfer($account['session'], new Uuid()->toString()));

        $later = $this->command($account, ['type' => 'prepare', 'attempt_id' => $transfer['id'], 'request_id' => $transfer['request_id']]);
        $earlier = $this->command($account, ['type' => 'start', 'attempt_id' => $transfer['id'], 'request_id' => $transfer['request_id']]);
        $answered = $this->command($account, ['type' => 'quiesce', 'attempt_id' => $transfer['id'], 'request_id' => $transfer['request_id']]);
        $elsewhere = $this->command($account, ['type' => 'prepare']);
        foreach ([[$later, 'rejected', 'video_codec_unsupported'], [$earlier, 'rejected', 'audio_codec_unsupported'], [$answered, 'quiesced', null], [$elsewhere, 'rejected', 'container_unsupported']] as [$command, $outcome, $reason]) {
            $this->store->insertCommand($command);
            $this->store->acknowledgeCommand($command['id'], $outcome, $reason);
        }
        // Creation order decides, not the order of the identifiers.
        $this->connection->executeStatement("UPDATE playback_commands SET created_at=created_at+INTERVAL '1 minute' WHERE id=?", [$later['id']]);
        $this->connection->executeStatement("UPDATE playback_commands SET created_at=created_at-INTERVAL '1 minute' WHERE id=?", [$elsewhere['id']]);

        self::assertSame(
            ['id' => $transfer['id'], 'request_id' => $transfer['request_id'], 'state' => 'preparing', 'rejection_reason' => 'audio_codec_unsupported'],
            $this->store->requestedTransfer($account['session'], $transfer['request_id']),
        );
    }

    public function testARequestedCommandIsTheFirstCommandOfItsRequest(): void
    {
        $account = $this->account();
        $first = $this->command($account, ['type' => 'play']);
        $chased = ['id' => new Uuid()->toString(), 'type' => 'stop', 'revision' => 8] + $first;
        $this->store->insertCommand($first);
        $this->store->insertCommand($chased);
        $this->connection->executeStatement("UPDATE playback_commands SET created_at=created_at+INTERVAL '1 minute' WHERE id=?", [$chased['id']]);
        $this->store->acknowledgeCommand($first['id'], 'rejected', 'browser_rejected_source');

        self::assertSame(
            ['id' => $first['id'], 'request_id' => $first['request_id'], 'outcome' => 'rejected', 'rejection_reason' => 'browser_rejected_source'],
            $this->store->requestedCommand($account['session'], $first['request_id']),
        );
        self::assertNull($this->store->requestedCommand($account['session'], new Uuid()->toString()));
    }

    public function testARequestIsRememberedByItsDigestWhetherItBecameATransferOrACommand(): void
    {
        $account = $this->account();
        $transfer = $this->transfer($account, ['request_digest' => 'transfer digest']);
        $this->store->saveTransfer($transfer);
        $command = $this->command($account, ['type' => 'play', 'request_digest' => 'command digest']);
        $this->store->insertCommand($command);

        self::assertFalse($this->store->rememberRequest($account['session'], new Uuid()->toString(), 'transfer digest'));
        foreach ([[$transfer['request_id'], 'transfer digest'], [$command['request_id'], 'command digest']] as [$request, $digest]) {
            self::assertTrue($this->store->rememberRequest($account['session'], $request, $digest));
            try {
                $this->store->rememberRequest($account['session'], $request, 'another digest');
                self::fail('A request identity was accepted for another payload.');
            } catch (ConflictException $error) {
                self::assertSame('The playback request identity has already been used for another payload.', $error->getMessage());
            }
        }
        // Another account's session does not know the request.
        $other = $this->account();
        self::assertFalse($this->store->rememberRequest($other['session'], $transfer['request_id'], 'transfer digest'));
    }

    public function testQuiescenceAndDisclosureAreAnsweredFromTheAttemptsCommands(): void
    {
        $account = $this->account();
        $attempt = new Uuid()->toString();
        $quiesce = $this->command($account, ['type' => 'quiesce', 'attempt_id' => $attempt]);
        $this->store->insertCommand($quiesce);
        self::assertFalse($this->store->sourceQuiesced($attempt, $account['incarnation']));
        self::assertFalse($this->store->attemptDisclosed($attempt, 'quiesce'));

        $this->store->snapshot($this->store->lockSession($account['owner']), $account['incarnation']);
        self::assertTrue($this->store->attemptDisclosed($attempt, 'quiesce'));
        self::assertFalse($this->store->attemptDisclosed($attempt, 'start'));
        self::assertFalse($this->store->attemptDisclosed(new Uuid()->toString(), 'quiesce'));

        $this->store->acknowledgeCommand($quiesce['id'], 'quiesced');
        self::assertTrue($this->store->sourceQuiesced($attempt, $account['incarnation']));
        self::assertFalse($this->store->sourceQuiesced($attempt, new Uuid()->toString()));
        self::assertFalse($this->store->sourceQuiesced(new Uuid()->toString(), $account['incarnation']));
    }

    public function testThePendingControlIsTheOldestUnansweredControlAndNoTransferCommand(): void
    {
        $account = $this->account();
        self::assertNull($this->store->pendingControl($account['session']));
        self::assertFalse($this->store->hasPendingControl($account['session']));
        $prepare = $this->command($account, ['type' => 'prepare']);
        $newer = $this->command($account, ['type' => 'volume', 'payload' => ['volume' => 0.5]]);
        $older = $this->command($account, ['type' => 'seek']);
        foreach ([$prepare, $newer, $older] as $command) {
            $this->store->insertCommand($command);
        }
        $this->connection->executeStatement("UPDATE playback_commands SET created_at=created_at-INTERVAL '1 minute' WHERE id=?", [$older['id']]);
        $this->connection->executeStatement("UPDATE playback_commands SET created_at=created_at-INTERVAL '2 minutes' WHERE id=?", [$prepare['id']]);

        self::assertSame($older['id'], $this->store->pendingControl($account['session'])['id'] ?? null);
        $this->store->acknowledgeCommand($older['id'], 'applied');
        self::assertSame($newer['id'], $this->store->pendingControl($account['session'])['id'] ?? null);
        $this->store->acknowledgeCommand($newer['id'], 'applied');
        self::assertNull($this->store->pendingControl($account['session']));
        self::assertFalse($this->store->hasPendingControl($account['session']));
    }

    public function testReadsLeaveNoEntityBehindInTheUnitOfWork(): void
    {
        $account = $this->account(recoverable: true);
        $command = $this->command($account, ['type' => 'play']);
        $this->store->insertCommand($command);
        $transfer = $this->transfer($account);
        $this->store->saveTransfer($transfer);
        $this->manager->clear();

        $this->connection->beginTransaction();
        $session = $this->store->lockSession($account['owner']);
        $this->store->endpoint($account['owner'], $account['incarnation']);
        $this->store->localRecovery($account['owner'], $account['incarnation'], $account['tab'], $account['recoveryCredential']);
        $this->store->command($account['owner'], $command['id']);
        $this->store->pendingControl($account['session']);
        $this->store->currentTransfer($account['session']);
        $this->store->requestedCommand($account['session'], $command['request_id']);
        $this->store->requestedTransfer($account['session'], $transfer['request_id']);
        $this->store->rememberRequest($account['session'], $command['request_id'], $command['request_digest']);
        $this->store->sourceQuiesced($command['attempt_id'], $account['incarnation']);
        $this->store->attemptDisclosed($command['attempt_id'], 'play');
        $this->store->snapshot($session, $account['incarnation']);
        $this->connection->commit();

        self::assertSame(0, $this->manager->getUnitOfWork()->size());
        self::assertSame([], $this->manager->getUnitOfWork()->getIdentityMap());
    }

    public function testAnAnswerIsWrittenOnce(): void
    {
        $account = $this->account();
        $command = $this->command($account, ['type' => 'play']);
        $this->store->insertCommand($command);
        $this->store->acknowledgeCommand($command['id'], 'rejected', 'audio_codec_unsupported');
        $first = $this->observer->fetchAssociative('SELECT outcome,rejection_reason,acknowledged_at FROM playback_commands WHERE id=?', [$command['id']]);
        self::assertSame(['rejected', 'audio_codec_unsupported'], [$first['outcome'], $first['rejection_reason']]);
        self::assertNotNull($first['acknowledged_at']);

        $this->store->acknowledgeCommand($command['id'], 'playing');
        $this->store->cancelCommands($command['attempt_id']);
        $this->store->settleNeverEffective($command['attempt_id'], 9);
        $this->store->supersedeIncarnationCommands($account['incarnation']);

        self::assertSame($first, $this->observer->fetchAssociative('SELECT outcome,rejection_reason,acknowledged_at FROM playback_commands WHERE id=?', [$command['id']]));
        self::assertNull($this->observer->fetchOne('SELECT settled_revision FROM playback_commands WHERE id=?', [$command['id']]));
    }

    public function testClosingAnAttemptsCommandsLeavesItsAnsweredOnesAndOtherAttemptsAlone(): void
    {
        $account = $this->account();
        $outcomes = fn(): array => $this->observer->fetchAllKeyValue('SELECT type,outcome FROM playback_commands WHERE session_id=? ORDER BY type', [$account['session']]);
        $settledAttempt = new Uuid()->toString();
        $cancelledAttempt = new Uuid()->toString();
        $answered = $this->command($account, ['type' => 'prepare', 'attempt_id' => $settledAttempt]);
        $settled = [$this->command($account, ['type' => 'play', 'attempt_id' => $settledAttempt]), $this->command($account, ['type' => 'stop', 'attempt_id' => $settledAttempt])];
        $cancelled = $this->command($account, ['type' => 'quiesce', 'attempt_id' => $cancelledAttempt]);
        $other = $this->command($account, ['type' => 'seek']);
        foreach ([$answered, ...$settled, $cancelled, $other] as $command) {
            $this->store->insertCommand($command);
        }
        $this->store->acknowledgeCommand($answered['id'], 'prepared');

        $this->connection->beginTransaction();
        $this->store->settleNeverEffective($settledAttempt, 11);
        $this->store->cancelCommands($cancelledAttempt);
        $stamped = $this->connection->fetchOne("SELECT bool_and(acknowledged_at=CURRENT_TIMESTAMP) FROM playback_commands WHERE session_id=? AND outcome IN ('never_effective','superseded')", [$account['session']]);
        $this->connection->commit();

        self::assertTrue($stamped);
        self::assertSame(['play' => 'never_effective', 'prepare' => 'prepared', 'quiesce' => 'superseded', 'seek' => null, 'stop' => 'never_effective'], $outcomes());
        self::assertSame(
            ['play' => 11, 'prepare' => null, 'quiesce' => null, 'seek' => null, 'stop' => 11],
            $this->observer->fetchAllKeyValue('SELECT type,settled_revision FROM playback_commands WHERE session_id=? ORDER BY type', [$account['session']]),
        );

        // A recovered incarnation's unanswered commands are closed, whatever their attempt.
        $elsewhere = $this->register($account['owner'], $account['device']);
        $kept = $this->command($account, ['type' => 'pause', 'incarnation_id' => $elsewhere['incarnationId']]);
        $this->store->insertCommand($kept);
        $this->store->supersedeIncarnationCommands($account['incarnation']);
        self::assertSame(['pause' => null, 'play' => 'never_effective', 'prepare' => 'prepared', 'quiesce' => 'superseded', 'seek' => 'superseded', 'stop' => 'never_effective'], $outcomes());
    }

    public function testAnObservationKeepsTheVolumeUnlessItReportsOneAndRenewsTheRegistration(): void
    {
        $account = $this->account();
        $endpoint = $this->store->endpoint($account['owner'], $account['incarnation']);
        self::assertSame('unknown', $endpoint['observed_state']);
        $row = fn(): array => $this->connection->fetchAssociative(
            "SELECT observed_state,position,observed_volume,expires_at=CURRENT_TIMESTAMP+INTERVAL '30 seconds' AS renewed,retired_at FROM playback_destination_incarnations WHERE id=?",
            [$account['incarnation']],
        );

        $this->connection->beginTransaction();
        $this->connection->executeQuery('SELECT pg_sleep(0.05)')->free();
        $this->store->observe($account['incarnation'], 'playing', 4.0, 0.25);
        self::assertSame(['observed_state' => 'playing', 'position' => 4.0, 'observed_volume' => 0.25, 'renewed' => true, 'retired_at' => null], $row());
        $this->store->observe($account['incarnation'], 'paused', 5.5);
        self::assertSame(['observed_state' => 'paused', 'position' => 5.5, 'observed_volume' => 0.25, 'renewed' => true, 'retired_at' => null], $row());
        $this->connection->commit();

        // The state written is the one reported, whatever the row held when the endpoint was read.
        $this->connection->executeStatement("UPDATE playback_destination_incarnations SET observed_state='unknown',expires_at=CURRENT_TIMESTAMP-INTERVAL '1 hour' WHERE id=?", [$account['incarnation']]);
        $this->connection->beginTransaction();
        $this->store->observe($account['incarnation'], 'stopped', 6.0);
        self::assertSame(['observed_state' => 'stopped', 'position' => 6.0, 'observed_volume' => 0.25, 'renewed' => true, 'retired_at' => null], $row());
        $this->connection->executeStatement("UPDATE playback_destination_incarnations SET expires_at=CURRENT_TIMESTAMP-INTERVAL '1 hour' WHERE id=?", [$account['incarnation']]);
        $this->store->observeVolume($account['incarnation'], 1.0);
        self::assertSame(['observed_state' => 'stopped', 'position' => 6.0, 'observed_volume' => 1.0, 'renewed' => true, 'retired_at' => null], $row());
        $this->store->markRunnerUnknown($account['incarnation']);
        $this->connection->executeStatement("UPDATE playback_destination_incarnations SET expires_at=CURRENT_TIMESTAMP-INTERVAL '1 hour' WHERE id=?", [$account['incarnation']]);
        $this->store->markRunnerUnknown($account['incarnation']);
        self::assertSame(['observed_state' => 'unknown', 'position' => 6.0, 'observed_volume' => 1.0, 'renewed' => false, 'retired_at' => null], $row());
        // A reported cursor renews the registration and leaves the last report about playback as it stands.
        $this->store->reportCursor($account['incarnation'], 7.5);
        self::assertSame(['observed_state' => 'unknown', 'position' => 7.5, 'observed_volume' => 1.0, 'renewed' => true, 'retired_at' => null], $row());
        $this->connection->commit();
    }

    public function testACompletedRecoveryRetiresTheIncarnationOnceAndStoresItsResult(): void
    {
        $account = $this->account(recoverable: true);
        $this->store->observe($account['incarnation'], 'playing', 2.0);

        $this->connection->beginTransaction();
        $this->store->completeLocalRecovery($account['incarnation'], ['incarnationId' => $account['incarnation'], 'outcome' => 'recovered', 'revision' => 4]);
        $first = $this->connection->fetchAssociative('SELECT retired_at=CURRENT_TIMESTAMP AS stamped,retired_at,observed_state,recovery_result FROM playback_destination_incarnations WHERE id=?', [$account['incarnation']]);
        $this->connection->commit();
        self::assertTrue($first['stamped']);
        self::assertSame('stopped', $first['observed_state']);
        self::assertEquals(['incarnationId' => $account['incarnation'], 'outcome' => 'recovered', 'revision' => 4], json_decode($first['recovery_result'], true, flags: JSON_THROW_ON_ERROR));

        $this->store->completeLocalRecovery($account['incarnation'], ['incarnationId' => $account['incarnation'], 'outcome' => 'recovered', 'revision' => 6]);
        $again = $this->observer->fetchAssociative('SELECT retired_at,recovery_result FROM playback_destination_incarnations WHERE id=?', [$account['incarnation']]);
        self::assertSame($first['retired_at'], $again['retired_at']);
        self::assertSame(6, json_decode($again['recovery_result'], true, flags: JSON_THROW_ON_ERROR)['revision']);
    }

    public function testTheSnapshotThatFirstReturnsACommandMarksItAndLaterOnesLeaveTheMark(): void
    {
        $account = $this->account();
        $other = $this->register($account['owner'], $account['device']);
        $shown = $this->command($account, ['type' => 'play']);
        $answered = $this->command($account, ['type' => 'seek']);
        $elsewhere = $this->command($account, ['type' => 'pause', 'incarnation_id' => $other['incarnationId']]);
        foreach ([$shown, $answered, $elsewhere] as $command) {
            $this->store->insertCommand($command);
        }
        $this->store->acknowledgeCommand($answered['id'], 'applied');
        $session = $this->store->lockSession($account['owner']);
        $marks = fn(): array => $this->observer->fetchAllKeyValue('SELECT type,disclosed_at FROM playback_commands WHERE session_id=? ORDER BY type', [$account['session']]);
        self::assertSame(['pause' => null, 'play' => null, 'seek' => null], $marks());

        $this->connection->beginTransaction();
        $first = $this->store->snapshot($session, $account['incarnation']);
        $stamped = $this->connection->fetchOne('SELECT disclosed_at=CURRENT_TIMESTAMP FROM playback_commands WHERE id=?', [$shown['id']]);
        $this->connection->commit();
        self::assertSame([$shown['id']], array_column($first['commands'], 'id'));
        self::assertTrue($stamped);
        $marked = $marks();
        self::assertNotNull($marked['play']);
        self::assertNull($marked['pause']);
        self::assertNull($marked['seek']);

        $second = $this->store->snapshot($session, $account['incarnation']);
        self::assertSame($first['commands'], $second['commands']);
        self::assertSame($marked, $marks());

        // A command added later is marked by the next snapshot, and the earlier mark stays.
        $later = $this->command($account, ['type' => 'stop', 'revision' => 8]);
        $this->store->insertCommand($later);
        self::assertSame([$shown['id'], $later['id']], array_column($this->store->snapshot($session, $account['incarnation'])['commands'], 'id'));
        self::assertSame($marked['play'], $marks()['play']);
        self::assertNotNull($marks()['stop']);
    }

    public function testAWholeNumberInACommandsPayloadReadsBackNumericallyEqual(): void
    {
        $account = $this->account();
        $command = $this->command($account, ['type' => 'load', 'payload' => ['position' => 30.0, 'currentTrackIndex' => 2, 'queue' => [], 'media' => null, 'desiredState' => 'playing']]);
        $this->store->insertCommand($command);

        $stored = $this->store->command($account['owner'], $command['id'])['payload'];
        self::assertEquals(30, $stored['position']);
        self::assertEquals(2, $stored['currentTrackIndex']);
        self::assertEquals($command['payload'], $stored);
        self::assertEquals($command['payload'], $this->store->snapshot($this->store->lockSession($account['owner']), $account['incarnation'])['commands'][0]['payload']);
    }

    public function testSavingASessionStampsItWithTheTransactionsClockAndMirrorsTheDesiredState(): void
    {
        $account = $this->account();
        $device = Uuid::fromString($account['device']);
        $this->connection->executeStatement('UPDATE listening_sessions SET active_device_id=:device WHERE id=:id', ['device' => $device->toString(), 'id' => $account['session']]);
        $before = $this->observer->fetchAssociative('SELECT created_at,public_id,user_id FROM listening_sessions WHERE id=?', [$account['session']]);
        $track = new PublicId();

        $this->connection->beginTransaction();
        $this->connection->executeQuery('SELECT pg_sleep(0.05)')->free();
        $session = $this->store->lockSession($account['owner']);
        $session->control('load', new PlaybackMedia('song', $track), [$track->toString()], 0, 7.25, PlaybackState::Playing);
        $session->commitDestination(Uuid::fromString($account['incarnation']), Uuid::fromString($this->connection->fetchOne('SELECT destination_id FROM playback_destination_incarnations WHERE id=?', [$account['incarnation']])), PlaybackState::Playing, 7.25);
        $this->store->saveSession($session);
        $row = $this->connection->fetchAssociative('SELECT *,updated_at=CURRENT_TIMESTAMP AS updated,last_used_at=CURRENT_TIMESTAMP AS used FROM listening_sessions WHERE id=?', [$account['session']]);
        $this->connection->commit();

        self::assertTrue($row['updated']);
        self::assertTrue($row['used']);
        self::assertSame(['playing', 'playing', 'playing'], [$row['playback_state'], $row['desired_state'], $row['observed_state']]);
        self::assertSame($device->toString(), $row['active_device_id']);
        self::assertSame($account['incarnation'], $row['active_incarnation_id']);
        self::assertSame(1, $row['revision']);
        self::assertSame(7.25, $row['position']);
        self::assertSame(0, $row['current_track_index']);
        self::assertEquals(['kind' => 'song', 'publicId' => $track->toString()], json_decode($row['media'], true, flags: JSON_THROW_ON_ERROR));
        self::assertSame([$track->toString()], json_decode($row['queue'], true, flags: JSON_THROW_ON_ERROR));
        self::assertEquals($before, array_intersect_key($row, $before));

        // Releasing the runner clears both references again.
        $released = $this->store->lockSession($account['owner']);
        $released->control('pause', null, null, null, null, null);
        $released->confirmControl(false, null);
        $released->releaseConfirmedPausedRunner();
        $this->store->saveSession($released);
        $cleared = $this->observer->fetchAssociative('SELECT selected_destination_id,active_incarnation_id,active_device_id,playback_state FROM listening_sessions WHERE id=?', [$account['session']]);
        self::assertSame(['selected_destination_id' => null, 'active_incarnation_id' => null, 'active_device_id' => $device->toString(), 'playback_state' => 'paused'], $cleared);
    }

    public function testWritesLeaveNoEntityBehindAndNothingScheduled(): void
    {
        $owner = $this->owner();
        $device = $this->device($owner);
        $this->manager->clear();
        $unit = $this->manager->getUnitOfWork();
        $clean = function (string $after) use ($unit): void {
            self::assertSame(0, $unit->size(), $after);
            self::assertSame([], $unit->getScheduledEntityInsertions(), $after);
            self::assertSame([], $unit->getScheduledEntityUpdates(), $after);
            self::assertSame([], $unit->getScheduledEntityDeletions(), $after);
        };

        $this->connection->beginTransaction();
        $session = $this->store->lockSession($owner);
        $endpoint = $this->store->register($owner, ['deviceId' => $device, 'endpointId' => new Uuid()->toString(), 'tabId' => new Uuid()->toString(), 'localRecoveryEligible' => true, 'pageId' => new Uuid()->toString()]);
        $clean('a registration');
        $later = $this->store->register($owner, ['deviceId' => $device, 'endpointId' => new Uuid()->toString()]);
        $account = ['owner' => $owner, 'session' => $session->getId()->toString(), 'incarnation' => $endpoint['incarnationId']];
        $command = $this->command($account, ['type' => 'play']);
        $this->store->insertCommand($command);
        $this->store->insertCommand(['id' => new Uuid()->toString()] + $command);
        $this->store->insertCommand($this->command($account, ['type' => 'pause', 'incarnation_id' => null, 'epoch' => null]));
        $clean('command inserts');
        $transfer = $this->transfer($account);
        $this->store->saveTransfer($transfer);
        $this->store->saveTransfer(['state' => 'starting'] + $transfer);
        $clean('a transfer');
        $session->control('queue', null, [], 0, null, null);
        $this->store->saveSession($session);
        $this->store->acknowledgeCommand($command['id'], 'playing');
        $this->store->cancelCommands($transfer['id']);
        $this->store->settleNeverEffective($command['attempt_id'], 3);
        $this->store->supersedeIncarnationCommands($endpoint['incarnationId']);
        $this->store->observe($endpoint['incarnationId'], 'paused', 1.0, 0.5);
        $this->store->observeVolume($endpoint['incarnationId'], 0.75);
        $this->store->reportCursor($endpoint['incarnationId'], 2.0);
        $this->store->markRunnerUnknown($endpoint['incarnationId']);
        $this->store->completeLocalRecovery($endpoint['incarnationId'], ['outcome' => 'recovered']);
        $this->store->retireIncarnation($endpoint['incarnationId'], stopped: true, position: 3.0);
        $this->store->succeedIncarnation($endpoint['incarnationId'], $later['incarnationId'], $later['epoch']);
        $this->store->snapshot($session, $endpoint['incarnationId']);
        $clean('every other write');
        $this->connection->commit();

        self::assertSame(2, (int) $this->observer->fetchOne('SELECT count(*) FROM playback_commands WHERE user_id=?', [$owner]));
        self::assertSame(2, (int) $this->observer->fetchOne('SELECT count(*) FROM playback_destinations WHERE user_id=?', [$owner]));
    }

    /**
     * Reads the store's source, so it also holds for branches no scenario runs. The store may reach
     * the connection only through its connection() method and must call a method on the result at
     * once: a connection kept in a variable, a property or a parameter fails here, and so does a
     * second place that asks the entity manager for it, or a native query. Two things stay out of
     * its sight: a statement another class issues on the store's behalf, and a call whose name is
     * put together at run time.
     */
    public function testOnlyTheFiveStatementsDqlCannotExpressReachTheConnection(): void
    {
        $source = (string) file_get_contents(new \ReflectionClass(PlaybackStore::class)->getFileName());
        $tokens = array_values(array_filter(\PhpToken::tokenize($source), static fn(\PhpToken $token): bool => !$token->isIgnorable()));
        $uses = [];
        foreach ($tokens as $at => $token) {
            if (!$token->is(T_STRING) || $token->text !== 'connection' || $tokens[$at - 1]->is(T_FUNCTION)) {
                continue;
            }
            $written = implode('', array_map(static fn(\PhpToken $part): string => $part->text, array_slice($tokens, $at - 2, 8)));
            $uses[] = preg_match('/^\$this->connection\(\)->(\w+)\($/', $written, $called) === 1 ? $called[1] : 'held: ' . $written;
        }
        self::assertSame(
            ['executeQuery', 'executeStatement', 'fetchOne', 'fetchOne', 'fetchOne', 'getTransactionNestingLevel', 'isTransactionActive'],
            $uses,
            'Each use of the connection is a call on it where it is obtained.',
        );
        $names = array_count_values(array_map(
            static fn(\PhpToken $token): string => $token->text,
            array_filter($tokens, static fn(\PhpToken $token): bool => $token->is(T_STRING)),
        ));
        self::assertSame(1, $names['getConnection'] ?? 0, 'Only connection() asks the entity manager for the connection.');
        self::assertSame(1, $names['Connection'] ?? 0, 'Only the return type of connection() names the connection type.');
        self::assertSame([], array_keys(array_intersect_key($names, array_flip(['createNativeQuery', 'getNativeConnection', 'getWrappedConnection']))));
        preg_match_all('/connection\(\)\s*->\s*(executeStatement|executeQuery|fetch\w+)\(\s*(?:<<<\'SQL\'\s*)?[\'"]?\s*([A-Z ]+?[a-z_]+)/', $source, $calls, PREG_SET_ORDER);

        self::assertSame([
            ['executeQuery', 'SELECT pg_advisory_xact_lock'],
            ['executeStatement', 'INSERT INTO listening_sessions'],
            ['fetchOne', 'UPDATE playback_destination_incarnations'],
            ['fetchOne', 'UPDATE playback_destination_incarnations'],
            ['fetchOne', 'SELECT recovery_expires_at'],
        ], array_map(static fn(array $call): array => [$call[1], $call[2]], $calls));
        self::assertSame(5, preg_match_all('/connection\(\)\s*->\s*(?:execute|fetch|prepare|insert|update|delete)/', $source));
    }

    private function owner(): string
    {
        $owner = new UserEntity(new PublicId(), 'Playback store owner', new Uuid()->toString() . '@baander.app', 'unused-password', '');
        $this->manager->persist($owner);
        $this->manager->flush();
        return $owner->getId()->toString();
    }

    private function device(string $owner, string $name = 'Playback store browser'): string
    {
        $device = new Uuid();
        new DeviceDoctrineRepository($this->manager)->save(Device::create(Uuid::fromString($owner), $device, $name));
        return $device->toString();
    }

    /** @return array<string,mixed> */
    private function register(string $owner, string $device, bool $recoverable = false): array
    {
        return $this->store->register($owner, ['deviceId' => $device, 'endpointId' => new Uuid()->toString()]
            + ($recoverable ? ['tabId' => new Uuid()->toString(), 'localRecoveryEligible' => true] : []));
    }

    /**
     * An account with a session row and one registered endpoint.
     *
     * @return array{owner:string,device:string,session:string,incarnation:string,destinationId:string,tab:?string,recoveryCredential:?string}
     */
    private function account(bool $recoverable = false): array
    {
        $owner = $this->owner();
        $device = $this->device($owner);
        $endpoint = $this->register($owner, $device, $recoverable);
        return ['owner' => $owner, 'device' => $device, 'session' => $this->store->lockSession($owner)->getId()->toString(),
            'incarnation' => $endpoint['incarnationId'], 'destinationId' => $endpoint['destinationId'],
            'tab' => $endpoint['recoveryTabId'] ?? null, 'recoveryCredential' => $endpoint['recoveryCredential'] ?? null];
    }

    /**
     * @param array{owner:string,session:string,incarnation:string} $account
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function command(array $account, array $overrides): array
    {
        return $overrides + ['id' => new Uuid()->toString(), 'session_id' => $account['session'], 'user_id' => $account['owner'],
            'attempt_id' => new Uuid()->toString(), 'request_id' => new Uuid()->toString(), 'request_digest' => 'digest',
            'incarnation_id' => $account['incarnation'], 'epoch' => 1, 'revision' => 7, 'type' => 'play', 'payload' => ['position' => 1.5]];
    }

    /**
     * @param array{owner:string,session:string,incarnation:string} $account
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function transfer(array $account, array $overrides = []): array
    {
        return $overrides + ['id' => new Uuid()->toString(), 'session_id' => $account['session'], 'user_id' => $account['owner'],
            'request_id' => new Uuid()->toString(), 'request_digest' => 'digest', 'source_incarnation_id' => null,
            'target_incarnation_id' => $account['incarnation'], 'state' => 'preparing', 'desired_state' => 'paused', 'position' => 1.5, 'revision' => 7];
    }

    /** The row's instant, as the store returned it, parses to the instant the database holds. */
    private function assertInstant(string $table, string $column, string $id, mixed $returned): void
    {
        self::assertIsString($returned, $column);
        self::assertTrue($this->observer->fetchOne("SELECT {$column}=CAST(? AS timestamptz) FROM {$table} WHERE id=?", [$returned, $id]), $column);
        self::assertSame(
            (int) $this->observer->fetchOne("SELECT floor(extract(epoch FROM {$column})) FROM {$table} WHERE id=?", [$id]),
            new \DateTimeImmutable($returned)->getTimestamp(),
            $column,
        );
    }
}
