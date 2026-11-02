<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Session\Application\Port\PlaybackOperation;
use App\Session\Application\Service\PlaybackCoordinator;
use App\Session\Domain\Model\Device\Device;
use App\Session\Infrastructure\Doctrine\PlaybackStore;
use App\Session\Infrastructure\Doctrine\Repository\DeviceDoctrineRepository;
use App\Tests\Fixtures\Session\PlaybackPersistenceFixture;
use App\Tests\Support\FinalSchemaBootstrap;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use Doctrine\ORM\EntityManager;
use Doctrine\DBAL\Connection;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Schema\Schema;
use App\Shared\Application\Exception\ConflictException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once dirname(__DIR__) . '/Fixtures/Session/playback-concurrent-transfer.php';

final class LocalPlaybackRecoveryPersistenceTest extends TestCase
{
    private string $schemaName;
    private EntityManager $manager;
    private Connection $observer;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Requires disposable PostgreSQL.');
        }
        $this->schemaName = 'playback_recovery_' . bin2hex(random_bytes(8));
        $connection = PlaybackPersistenceFixture::connect('public');
        $connection->executeStatement('CREATE SCHEMA ' . $this->schemaName);
        $connection->executeStatement('SET search_path TO ' . $this->schemaName . ', public');
        FinalSchemaBootstrap::bootstrap($connection);
        $this->manager = PlaybackPersistenceFixture::manager($connection);
        $this->observer = PlaybackPersistenceFixture::connect($this->schemaName);
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
        $connection->executeStatement('DROP SCHEMA ' . $this->schemaName . ' CASCADE');
        $connection->close();
    }

    private function createUser(): Uuid
    {
        $owner = new UserEntity(new PublicId(), 'Playback owner', new Uuid()->toString() . '@baander.app', 'unused-password', '');
        $this->manager->persist($owner);
        $this->manager->flush();
        return $owner->getId();
    }

    private function coordinator(): PlaybackCoordinator
    {
        return PlaybackPersistenceFixture::coordinator($this->manager);
    }

    /**
     * @param list<string> $capabilities
     * @return array<string,mixed>
     */
    private function endpoint(PlaybackCoordinator $service, Uuid $owner, ?Uuid $device = null, array $capabilities = ['song', 'video']): array
    {
        $device ??= new Uuid();
        $repository = new DeviceDoctrineRepository($this->manager);
        if ($repository->findByUserAndDevice($owner, $device) === null) {
            $repository->save(Device::create($owner, $device, 'Playback browser'));
        }
        return $service->execute($owner, PlaybackOperation::Endpoint, ['deviceId' => $device->toString(), 'endpointId' => new Uuid()->toString(), 'capabilities' => $capabilities, 'localRecoveryEligible' => true, 'tabId' => new Uuid()->toString()])['endpoint'];
    }

    /**
     * @param array<string,mixed> $endpoint
     * @return array<string,mixed>
     */
    private function pending(PlaybackCoordinator $service, Uuid $owner, array $endpoint): array
    {
        return $service->execute($owner, PlaybackOperation::Snapshot, ['incarnationId' => $endpoint['incarnationId']], $endpoint['capability']);
    }

    /**
     * @param array<string,mixed> $endpoint
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    private function ack(PlaybackCoordinator $service, Uuid $owner, array $endpoint, array $command, string $outcome, ?float $position = null, ?float $volume = null): array
    {
        $payload = ['commandId' => $command['id'], 'attemptId' => $command['attemptId'], 'revision' => (int) $command['revision'],
            'incarnationId' => $endpoint['incarnationId'], 'epoch' => (int) $command['epoch'], 'outcome' => $outcome];
        if ($position !== null) {
            $payload['position'] = $position;
        }
        if ($volume !== null) {
            $payload['volume'] = $volume;
        }
        return $service->execute($owner, PlaybackOperation::Acknowledge, $payload, $endpoint['capability']);
    }

    /**
     * @param array<string,mixed> $target
     * @return array<string,mixed>
     */
    private function activate(PlaybackCoordinator $service, Uuid $owner, array $target): array
    {
        $snapshot = $service->execute($owner, PlaybackOperation::Snapshot, []);
        $service->execute($owner, PlaybackOperation::Transfer, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $snapshot['revision'], 'targetIncarnationId' => $target['incarnationId']]);
        $prepare = $this->pending($service, $owner, $target)['commands'][0];
        $this->ack($service, $owner, $target, $prepare, 'prepared');
        $start = $this->pending($service, $owner, $target)['commands'][0];
        return $this->ack($service, $owner, $target, $start, 'started');
    }

    /**
     * @param array<string,mixed> $endpoint
     * @param array<string,mixed>|null $checkpoint
     * @return array<string,mixed>
     */
    private function recover(PlaybackCoordinator $service, Uuid $owner, array $endpoint, ?array $checkpoint = null): array
    {
        $snapshot = $service->execute($owner, PlaybackOperation::Snapshot, []);
        return $service->execute($owner, PlaybackOperation::RecoverLocal, [
            'incarnationId' => $endpoint['incarnationId'], 'tabId' => $endpoint['recoveryTabId'],
            'recoveryCredential' => $endpoint['recoveryCredential'],
            'checkpoint' => $checkpoint ?? ['revision' => $snapshot['revision'], 'media' => $snapshot['media'],
                'currentTrackIndex' => $snapshot['currentTrackIndex'], 'position' => 17.5],
        ]);
    }

    public function testRecoveryReleasesActiveRunnerAndReplayCannotChangeLaterTransfer(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $source = $this->endpoint($service, $owner);
        $this->activate($service, $owner, $source);
        $recovered = $this->recover($service, $owner, $source);
        self::assertNull($recovered['activeIncarnationId']);
        self::assertNull($recovered['selectedDestinationId']);
        self::assertSame('paused', $recovered['desiredState']);
        self::assertSame(17.5, $recovered['position']);
        self::assertSame([], $recovered['commands']);
        self::assertArrayNotHasKey('endpoint', $recovered);
        self::assertStringNotContainsString($source['recoveryCredential'], json_encode($recovered, JSON_THROW_ON_ERROR));
        $target = $this->endpoint($service, $owner);
        $later = $this->activate($service, $owner, $target);
        $replay = $this->recover($service, $owner, $source);
        self::assertEquals($recovered['recoveryResult'], $replay['recoveryResult']);
        self::assertSame($later['revision'], $replay['revision']);
        self::assertSame($target['incarnationId'], $replay['activeIncarnationId']);
    }

    public function testIneligibleRegistrationHasNoRecoveryAuthority(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $device = new Uuid();
        (new DeviceDoctrineRepository($this->manager))->save(Device::create($owner, $device, 'Ineligible browser'));
        $tab = new Uuid();
        $endpoint = $service->execute($owner, PlaybackOperation::Endpoint, ['deviceId' => $device->toString(),
            'endpointId' => new Uuid()->toString(), 'tabId' => $tab->toString(), 'localRecoveryEligible' => false])['endpoint'];
        self::assertArrayNotHasKey('recoveryCredential', $endpoint);
        $this->expectException(ConflictException::class);
        $service->execute($owner, PlaybackOperation::RecoverLocal, ['incarnationId' => $endpoint['incarnationId'],
            'tabId' => $tab->toString(), 'recoveryCredential' => str_repeat('a', 64),
            'checkpoint' => ['revision' => 0, 'media' => null, 'currentTrackIndex' => 0, 'position' => 0]]);
    }

    public function testRegistrationStoresOnlyHashesAndDoesNotRevokeOldRecoveryOnReconnect(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $device = new Uuid();
        $endpoint = $this->endpoint($service, $owner, $device);
        $row = $this->manager->getConnection()->fetchAssociative('SELECT * FROM playback_destination_incarnations WHERE id=:id', ['id' => $endpoint['incarnationId']]);
        self::assertSame(hash('sha256', $endpoint['recoveryCredential']), $row['recovery_credential_hash']);
        self::assertStringNotContainsString($endpoint['recoveryCredential'], json_encode($row, JSON_THROW_ON_ERROR));
        $this->endpoint($service, $owner, $device);
        self::assertSame('recovered', $this->recover($service, $owner, $endpoint)['recoveryResult']['outcome']);
    }

    public function testWrongBindingsAndExpiredOrRevokedCredentialsAreRefused(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser();
        $service = $this->coordinator();
        $endpoint = $this->endpoint($service, $owner);
        $payload = ['incarnationId' => $endpoint['incarnationId'], 'tabId' => $endpoint['recoveryTabId'],
            'recoveryCredential' => $endpoint['recoveryCredential'],
            'checkpoint' => ['revision' => 0, 'media' => null, 'currentTrackIndex' => 0, 'position' => 0]];
        foreach ([[$other, $payload], [$owner, array_replace($payload, ['tabId' => new Uuid()->toString()])],
            [$owner, array_replace($payload, ['incarnationId' => new Uuid()->toString()])],
            [$owner, array_replace($payload, ['recoveryCredential' => str_repeat('0', 64)])],
            [$owner, array_replace($payload, ['recoveryCredential' => $endpoint['capability']])]] as [$actor, $input]) {
            try {
                $service->execute($actor, PlaybackOperation::RecoverLocal, $input);
                self::fail('Invalid recovery authority must be refused.');
            } catch (ConflictException $error) {
                self::assertSame('Local recovery authority is invalid or expired.', $error->getMessage());
            }
        }
        foreach (["recovery_expires_at=CURRENT_TIMESTAMP", "recovery_expires_at=CURRENT_TIMESTAMP+INTERVAL '1 day', recovery_revoked_at=CURRENT_TIMESTAMP"] as $assignment) {
            $this->manager->getConnection()->executeStatement('UPDATE playback_destination_incarnations SET ' . $assignment . ' WHERE id=:id', ['id' => $endpoint['incarnationId']]);
            try {
                $service->execute($owner, PlaybackOperation::RecoverLocal, $payload);
                self::fail('Expired or revoked recovery authority must be refused.');
            } catch (ConflictException $error) {
                self::assertSame('Local recovery authority is invalid or expired.', $error->getMessage());
            }
        }
    }

    public function testRecoveryExpiryUsesTheClockAfterTransactionWaits(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $endpoint = $this->endpoint($service, $owner);
        $connection = $this->manager->getConnection();
        $connection->beginTransaction();
        try {
            $connection->executeStatement("UPDATE playback_destination_incarnations SET recovery_expires_at=clock_timestamp()+INTERVAL '50 milliseconds' WHERE id=:id", ['id' => $endpoint['incarnationId']]);
            $connection->executeQuery('SELECT pg_sleep(0.1)')->free();
            $this->expectException(ConflictException::class);
            $this->recover($service, $owner, $endpoint);
        } finally {
            $connection->rollBack();
        }
    }

    public function testRecoveryCredentialCannotReadNormalCommands(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $endpoint = $this->endpoint($service, $owner);
        $this->expectException(ConflictException::class);
        $service->execute($owner, PlaybackOperation::Snapshot, ['incarnationId' => $endpoint['incarnationId']], $endpoint['recoveryCredential']);
    }

    public function testRecoveredTargetKeepsUnacknowledgedSourceStopAsAnObligation(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $source = $this->endpoint($service, $owner);
        $target = $this->endpoint($service, $owner);
        $snapshot = $this->activate($service, $owner, $source);
        $service->execute($owner, PlaybackOperation::Transfer, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $snapshot['revision'], 'targetIncarnationId' => $target['incarnationId']]);
        $this->ack($service, $owner, $target, $this->pending($service, $owner, $target)['commands'][0], 'prepared');
        $oldQuiesce = $this->pending($service, $owner, $source)['commands'][0];
        $recovered = $this->recover($service, $owner, $target);
        self::assertNull($recovered['transfer']);
        self::assertSame($source['incarnationId'], $recovered['activeIncarnationId']);
        self::assertSame('unknown', $recovered['observedState']);
        $stop = $this->pending($service, $owner, $source)['commands'][0];
        self::assertSame('stop', $stop['type']);
        self::assertNotSame($oldQuiesce['id'], $stop['id']);
        $next = $this->endpoint($service, $owner);
        try {
            $service->execute($owner, PlaybackOperation::Transfer, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $recovered['revision'], 'targetIncarnationId' => $next['incarnationId']]);
            self::fail('A replacement cannot start before the source acknowledges stop.');
        } catch (ConflictException $error) {
            self::assertSame('A runner control is awaiting acknowledgement.', $error->getMessage());
        }
        $this->manager->getConnection()->executeStatement("UPDATE playback_commands SET expires_at=CURRENT_TIMESTAMP-INTERVAL '1 second' WHERE id=:id", ['id' => $stop['id']]);
        $expired = $service->execute($owner, PlaybackOperation::Snapshot, []);
        self::assertSame('paused', $expired['desiredState']);
        self::assertSame('unknown', $expired['observedState']);
        $retry = $this->pending($service, $owner, $source)['commands'][0];
        self::assertNotSame($stop['id'], $retry['id']);
        $rejected = $this->ack($service, $owner, $source, $retry, 'rejected');
        self::assertSame('paused', $rejected['desiredState']);
        $stop = $this->pending($service, $owner, $source)['commands'][0];
        self::assertNotSame($retry['id'], $stop['id']);
        $settled = $this->ack($service, $owner, $source, $stop, 'stopped');
        $replacement = $service->execute($owner, PlaybackOperation::Transfer, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $settled['revision'], 'targetIncarnationId' => $next['incarnationId']]);
        self::assertSame('preparing', $replacement['transfer']['state']);
    }

    public function testRecoveringBothRunnersAfterUnacknowledgedQuiescenceAllowsReplacement(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $source = $this->endpoint($service, $owner);
        $target = $this->endpoint($service, $owner);
        $snapshot = $this->activate($service, $owner, $source);
        $service->execute($owner, PlaybackOperation::Transfer, [
            'requestId' => new Uuid()->toString(), 'expectedRevision' => $snapshot['revision'],
            'targetIncarnationId' => $target['incarnationId'],
        ]);
        $quiescing = $this->ack($service, $owner, $target, $this->pending($service, $owner, $target)['commands'][0], 'prepared');
        $unacknowledged = $this->pending($service, $owner, $source)['commands'][0];
        self::assertSame('quiesce', $unacknowledged['type']);
        $connection = $this->manager->getConnection();
        $connection->executeStatement("UPDATE playback_transfers SET expires_at=CURRENT_TIMESTAMP-INTERVAL '1 second' WHERE id=:id", [
            'id' => $quiescing['transfer']['attemptId'],
        ]);
        $uncertain = $service->execute($owner, PlaybackOperation::Snapshot, []);
        self::assertSame('uncertain', $uncertain['transfer']['state']);
        self::assertSame('superseded', $connection->fetchOne('SELECT outcome FROM playback_commands WHERE id=:id', ['id' => $unacknowledged['id']]));
        $targetStop = $this->pending($service, $owner, $target)['commands'][0];
        self::assertSame('stop', $targetStop['type']);

        $sourceRecovered = $this->recover($service, $owner, $source);
        self::assertSame($uncertain['revision'], $sourceRecovered['revision']);
        self::assertSame('uncertain', $sourceRecovered['transfer']['state']);
        self::assertSame($targetStop['id'], $this->pending($service, $owner, $target)['commands'][0]['id']);
        $sourceRecord = $connection->fetchAssociative('SELECT retired_at,observed_state,recovery_result FROM playback_destination_incarnations WHERE id=:id', [
            'id' => $source['incarnationId'],
        ]);
        self::assertIsArray($sourceRecord);
        self::assertNotNull($sourceRecord['retired_at']);
        self::assertSame('stopped', $sourceRecord['observed_state']);
        self::assertEquals($sourceRecovered['recoveryResult'], json_decode($sourceRecord['recovery_result'], true, flags: JSON_THROW_ON_ERROR));

        $service = $this->coordinator();
        $targetRecovered = $this->recover($service, $owner, $target);
        self::assertNull($targetRecovered['transfer']);
        self::assertSame('paused', $targetRecovered['desiredState']);
        self::assertSame([], $this->pending($service, $owner, $source)['commands']);
        self::assertSame([], $this->pending($service, $owner, $target)['commands']);
        $replacement = $this->endpoint($service, $owner);
        $next = $service->execute($owner, PlaybackOperation::Transfer, [
            'requestId' => new Uuid()->toString(), 'expectedRevision' => $targetRecovered['revision'],
            'targetIncarnationId' => $replacement['incarnationId'],
        ]);
        self::assertNull($next['transfer']['sourceIncarnationId']);
        $starting = $this->ack($service, $owner, $replacement, $this->pending($service, $owner, $replacement)['commands'][0], 'prepared');
        self::assertSame('starting', $starting['transfer']['state']);
        $committed = $this->ack($service, $owner, $replacement, $this->pending($service, $owner, $replacement)['commands'][0], 'started');
        self::assertSame($replacement['incarnationId'], $committed['activeIncarnationId']);
        self::assertNull($committed['transfer']);
    }

    public function testRecoveringSourceDuringTargetStartPreservesTargetCommandAndRevision(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $source = $this->endpoint($service, $owner);
        $target = $this->endpoint($service, $owner);
        $snapshot = $this->activate($service, $owner, $source);
        $service->execute($owner, PlaybackOperation::Transfer, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $snapshot['revision'], 'targetIncarnationId' => $target['incarnationId']]);
        $this->ack($service, $owner, $target, $this->pending($service, $owner, $target)['commands'][0], 'prepared');
        $starting = $this->ack($service, $owner, $source, $this->pending($service, $owner, $source)['commands'][0], 'quiesced', 7.5);
        $command = $this->pending($service, $owner, $target)['commands'][0];
        $recovered = $this->recover($service, $owner, $source);
        self::assertSame($starting['revision'], $recovered['revision']);
        self::assertSame('starting', $recovered['transfer']['state']);
        self::assertSame($command['id'], $this->pending($service, $owner, $target)['commands'][0]['id']);
    }
}
