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
use App\Tests\Fixtures\Session\PlaybackPersistenceFixture;
use App\Tests\Support\FinalSchemaBootstrap;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Fixtures/Session/playback-concurrent-transfer.php';

/**
 * What the playback store does that the coordinator's tests do not assert: how it resolves a
 * repeated write, which clock stamps a row, and the shape and scalar types of the rows it returns.
 */
final class PlaybackStoreContractTest extends TestCase
{
    private const array INCARNATION_COLUMNS = [
        'id', 'destination_id', 'user_id', 'epoch', 'capability_hash', 'expires_at', 'retired_at', 'observed_volume', 'observed_state', 'position',
        'tab_id', 'local_recovery_eligible', 'recovery_credential_hash', 'recovery_expires_at', 'recovery_revoked_at', 'recovery_result',
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
