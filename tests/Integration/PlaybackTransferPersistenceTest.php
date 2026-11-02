<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Media\Application\Port\PlaybackMediaAccessInterface;
use App\Session\Application\Port\PlaybackOperation;
use App\Session\Application\Service\PlaybackCoordinator;
use App\Session\Domain\Model\Device\Device;
use App\Session\Domain\Model\Playback\PlaybackMedia;
use App\Session\Domain\Model\Playback\PlaybackState;
use App\Session\Infrastructure\Doctrine\PlaybackStore;
use App\Session\Infrastructure\Doctrine\Repository\DeviceDoctrineRepository;
use App\Tests\Fixtures\Session\PlaybackPersistenceFixture;
use App\Tests\Support\FinalSchemaBootstrap;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use Doctrine\ORM\EntityManager;
use Doctrine\DBAL\Connection;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Application\Port\TransactionPortInterface;
use Doctrine\DBAL\Schema\Schema;
use App\Shared\Application\Exception\ConflictException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once dirname(__DIR__) . '/Fixtures/Session/playback-concurrent-transfer.php';

final class PlaybackTransferPersistenceTest extends TestCase
{
    private string $schemaName;
    private EntityManager $manager;
    private Connection $observer;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Requires disposable PostgreSQL.');
        }
        $this->schemaName = 'playback_transfer_' . bin2hex(random_bytes(8));
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
        return $service->execute($owner, PlaybackOperation::Endpoint, ['deviceId' => $device->toString(), 'endpointId' => new Uuid()->toString(), 'capabilities' => $capabilities])['endpoint'];
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

    public function testControlDeliversOnlyAuthenticatedRequestingEndpointCommandsAndKeepsRetryTarget(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $source = $this->endpoint($service, $owner);
        $snapshot = $this->activate($service, $owner, $source);
        $request = ['requestId' => new Uuid()->toString(), 'expectedRevision' => $snapshot['revision'], 'action' => 'pause'];
        $response = $service->execute($owner, PlaybackOperation::Control, $request + ['incarnationId' => $source['incarnationId']], $source['capability']);

        self::assertCount(1, $response['commands']);
        self::assertSame('pause', $response['commands'][0]['type']);
        self::assertSame($source['incarnationId'], $response['commands'][0]['incarnationId']);
        self::assertSame($source['epoch'], $response['commands'][0]['epoch']);
        self::assertSame($response['revision'], $response['commands'][0]['revision']);
        self::assertSame($response['commands'][0]['id'], $response['requestResult']['commandId']);

        $replacement = $this->endpoint($service, $owner);
        $retry = $service->execute($owner, PlaybackOperation::Control, $request + ['incarnationId' => $replacement['incarnationId']], $replacement['capability']);
        self::assertSame([], $retry['commands']);
        self::assertSame($response['requestResult'], $retry['requestResult']);
        self::assertSame($source['incarnationId'], $retry['activeIncarnationId']);
        self::assertSame([], $service->execute($owner, PlaybackOperation::Control, $request)['commands']);
        self::assertSame($response['commands'][0]['id'], $this->pending($service, $owner, $source)['commands'][0]['id']);
        self::assertSame(1, (int) $this->observer->fetchOne('SELECT count(*) FROM playback_commands WHERE request_id=?', [$request['requestId']]));

        $settled = $this->ack($service, $owner, $source, $response['commands'][0], 'paused');
        $remoteResponse = $service->execute($owner, PlaybackOperation::Control, [
            'requestId' => new Uuid()->toString(), 'expectedRevision' => $settled['revision'], 'action' => 'pause',
            'incarnationId' => $replacement['incarnationId'],
        ], $replacement['capability']);
        self::assertSame([], $remoteResponse['commands']);
        self::assertSame($remoteResponse['requestResult']['commandId'], $this->pending($service, $owner, $source)['commands'][0]['id']);
    }

    public function testControlRejectsMissingWrongAndForeignDeliveryCapabilitiesBeforeMutation(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser();
        $service = $this->coordinator();
        $source = $this->endpoint($service, $owner);
        $snapshot = $this->activate($service, $owner, $source);
        $before = (int) $this->observer->fetchOne('SELECT count(*) FROM playback_commands');
        foreach ([[$owner, null], [$owner, 'wrong-capability'], [$other, $source['capability']]] as [$actor, $capability]) {
            try {
                $service->execute($actor, PlaybackOperation::Control, [
                    'requestId' => new Uuid()->toString(), 'expectedRevision' => $snapshot['revision'],
                    'action' => 'pause', 'incarnationId' => $source['incarnationId'],
                ], $capability);
                self::fail('Unauthenticated delivery context must not execute a control.');
            } catch (ConflictException) {
                self::assertSame($before, (int) $this->observer->fetchOne('SELECT count(*) FROM playback_commands'));
            }
        }
    }

    public function testDeniedNewSelectionAndChangedQueueRollBackWithoutCommands(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $source = $this->endpoint($service, $owner);
        $this->activate($service, $owner, $source);
        $connection = $this->manager->getConnection();
        $store = new PlaybackStore($this->manager);
        $oldTrack = new PublicId();
        $connection->transactional(function () use ($store, $owner, $oldTrack): void {
            $session = $store->lockSession($owner->toString());
            $session->control('load', new PlaybackMedia('song', $oldTrack), [$oldTrack->toString()], 0, 19.5, PlaybackState::Paused);
            $store->saveSession($session);
        });
        $before = $service->execute($owner, PlaybackOperation::Snapshot, []);
        $commandCount = (int) $this->observer->fetchOne('SELECT count(*) FROM playback_commands');
        foreach (['selected', 'queued'] as $deniedKind) {
            $denied = new PublicId();
            $selected = $deniedKind === 'selected' ? $denied : $oldTrack;
            $access = $this->createMock(PlaybackMediaAccessInterface::class);
            $access->expects(self::atLeastOnce())->method('assertTrackAccess')->willReturnCallback(static function (Uuid $actor, PublicId $track) use ($denied): void {
                if ($track->toString() === $denied->toString()) {
                    throw new \RuntimeException('Track access denied.');
                }
            });
            $transaction = $this->createStub(TransactionPortInterface::class);
            $transaction->method('run')->willReturnCallback(
                static fn (callable $operation): mixed => $connection->transactional(static fn (): mixed => $operation()),
            );
            $guarded = new PlaybackCoordinator($store, $transaction, $access);
            try {
                $guarded->execute($owner, PlaybackOperation::Control, [
                    'requestId' => new Uuid()->toString(), 'expectedRevision' => $before['revision'], 'action' => 'load',
                    'media' => ['kind' => 'song', 'publicId' => $selected->toString()], 'position' => 0,
                    'queue' => $deniedKind === 'selected' ? [$oldTrack->toString()] : [$oldTrack->toString(), $denied->toString()],
                    'currentTrackIndex' => 0, 'desiredState' => 'playing',
                ]);
                self::fail('A denied selection or queue entry must roll back.');
            } catch (\RuntimeException $error) {
                self::assertSame('Track access denied.', $error->getMessage());
            }
            $after = $service->execute($owner, PlaybackOperation::Snapshot, []);
            foreach (['media', 'queue', 'position', 'currentTrackIndex', 'revision', 'desiredState', 'activeIncarnationId'] as $key) {
                self::assertSame($before[$key], $after[$key], $key);
            }
            self::assertSame($commandCount, (int) $this->observer->fetchOne('SELECT count(*) FROM playback_commands'));
        }
    }

    public function testTwoTabsOnOneDeviceHaveDistinctAuthorityAndWrongAccountCannotAcknowledge(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser();
        $service = $this->coordinator();
        $device = new Uuid();
        $first = $this->endpoint($service, $owner, $device);
        $second = $this->endpoint($service, $owner, $device);
        self::assertNotSame($first['destinationId'], $second['destinationId']);
        self::assertNotSame($first['incarnationId'], $second['incarnationId']);
        foreach ([[$owner, $second['capability']], [$other, $first['capability']]] as [$actor,$capability]) {
            try {
                $service->execute($actor, PlaybackOperation::Snapshot, ['incarnationId' => $first['incarnationId']], $capability);
                self::fail('Another endpoint must not inherit authority from the shared browser token.');
            } catch (ConflictException $error) {
                self::assertSame('Endpoint authority is invalid.', $error->getMessage());
            }
        }
        $public = $service->execute($owner, PlaybackOperation::Snapshot, []);
        self::assertSame([], $public['commands']);
        self::assertStringNotContainsString($first['capability'], json_encode($public, JSON_THROW_ON_ERROR));
    }

    public function testTransferConflictsUntilMatchingSourceCursorAndTargetStartAreAcknowledged(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $source = $this->endpoint($service, $owner);
        $target = $this->endpoint($service, $owner);
        $snapshot = $this->activate($service, $owner, $source);
        $request = ['requestId' => new Uuid()->toString(), 'expectedRevision' => $snapshot['revision'], 'targetIncarnationId' => $target['incarnationId']];
        $snapshot = $service->execute($owner, PlaybackOperation::Transfer, $request);
        self::assertSame('preparing', $snapshot['transfer']['state']);
        self::assertSame($source['incarnationId'], $snapshot['activeIncarnationId']);
        self::assertSame($snapshot['revision'], $service->execute($owner, PlaybackOperation::Transfer, $request)['revision']);
        try {
            $service->execute($owner, PlaybackOperation::Transfer, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $snapshot['revision'], 'targetIncarnationId' => $source['incarnationId']]);
            self::fail('A concurrent transfer must conflict.');
        } catch (ConflictException $error) {
            self::assertSame('Another playback transfer is still being reconciled.', $error->getMessage());
        }
        $prepare = $this->pending($service, $owner, $target)['commands'][0];
        $snapshot = $this->ack($service, $owner, $target, $prepare, 'prepared');
        self::assertSame([], $this->pending($service, $owner, $target)['commands']);
        $quiesce = $this->pending($service, $owner, $source)['commands'][0];
        self::assertSame('quiesce', $quiesce['type']);
        $this->ack($service, $owner, $source, $quiesce, 'quiesced', 91.125);
        $start = $this->pending($service, $owner, $target)['commands'][0];
        self::assertSame(91.125, $start['payload']['position']);
        $snapshot = $this->ack($service, $owner, $target, $start, 'started');
        self::assertSame($target['incarnationId'], $snapshot['activeIncarnationId']);
        self::assertSame(91.125, $snapshot['position']);
        self::assertNull($snapshot['transfer']);
        self::assertSame($snapshot['revision'], $this->ack($service, $owner, $target, $start, 'started')['revision']);
    }

    public function testControlResultRemainsPendingUntilAcknowledgedAndReplaysDuringTransfer(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $source = $this->endpoint($service, $owner);
        $snapshot = $this->activate($service, $owner, $source);
        $request = ['requestId' => new Uuid()->toString(), 'expectedRevision' => $snapshot['revision'], 'action' => 'stop'];
        $pending = $service->execute($owner, PlaybackOperation::Control, $request);
        $command = $this->pending($service, $owner, $source)['commands'][0];
        self::assertSame(['requestId' => $request['requestId'], 'commandId' => $command['id'], 'outcome' => null], $pending['requestResult']);
        $acknowledged = $this->ack($service, $owner, $source, $command, 'stopped');
        self::assertGreaterThan($pending['revision'], $acknowledged['revision']);
        self::assertArrayNotHasKey('requestResult', $service->execute($owner, PlaybackOperation::Snapshot, []));
        $target = $this->endpoint($service, $owner);
        $service->execute($owner, PlaybackOperation::Transfer, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $acknowledged['revision'], 'targetIncarnationId' => $target['incarnationId']]);
        $replay = $service->execute($owner, PlaybackOperation::Control, $request);
        self::assertSame(['requestId' => $request['requestId'], 'commandId' => $command['id'], 'outcome' => 'stopped'], $replay['requestResult']);
        self::assertSame('preparing', $replay['transfer']['state']);
    }

    public function testQueueCommandsAcknowledgeTheCursorWithoutReloadAndExpireWithoutStopping(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $source = $this->endpoint($service, $owner);
        $snapshot = $this->activate($service, $owner, $source);
        $request = ['requestId' => new Uuid()->toString(), 'expectedRevision' => $snapshot['revision'], 'action' => 'queue', 'queue' => [], 'currentTrackIndex' => 0];
        $pending = $service->execute($owner, PlaybackOperation::Control, $request);
        $command = $this->pending($service, $owner, $source)['commands'][0];
        self::assertSame('queue', $command['type']);
        self::assertSame($snapshot['desiredState'], $pending['desiredState']);
        $acknowledged = $this->ack($service, $owner, $source, $command, 'applied', 18.5);
        self::assertSame(18.5, $acknowledged['position']);
        self::assertSame('applied', $service->execute($owner, PlaybackOperation::Control, $request)['requestResult']['outcome']);
        $request['requestId'] = new Uuid()->toString();
        $request['expectedRevision'] = $acknowledged['revision'];
        $pending = $service->execute($owner, PlaybackOperation::Control, $request);
        $this->observer->executeStatement("UPDATE playback_commands SET expires_at=CURRENT_TIMESTAMP-INTERVAL '1 second' WHERE id=:id", ['id' => $pending['requestResult']['commandId']]);
        $expired = $service->execute($owner, PlaybackOperation::Control, $request);
        self::assertSame('superseded', $expired['requestResult']['outcome']);
        self::assertSame($acknowledged['desiredState'], $expired['desiredState']);
        self::assertSame([], $this->pending($service, $owner, $source)['commands']);
    }

    public function testStopAcknowledgementRetainsTheCursorEvenIfThePlayerUnloadsItsElement(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $source = $this->endpoint($service, $owner);
        $snapshot = $this->activate($service, $owner, $source);
        $snapshot = $service->execute($owner, PlaybackOperation::Observe, ['incarnationId' => $source['incarnationId'], 'expectedRevision' => $snapshot['revision'], 'observedState' => 'playing', 'position' => 42.5], $source['capability']);
        $snapshot = $service->execute($owner, PlaybackOperation::Control, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $snapshot['revision'], 'action' => 'stop']);
        self::assertSame('stopped', $snapshot['desiredState']);
        self::assertSame('playing', $snapshot['observedState']);
        $command = $this->pending($service, $owner, $source)['commands'][0];
        self::assertSame('stop', $command['type']);
        self::assertSame(42.5, $command['payload']['position']);
        $snapshot = $this->ack($service, $owner, $source, $command, 'stopped', 0.0);
        self::assertSame('stopped', $snapshot['observedState']);
        self::assertSame(42.5, $snapshot['position']);
        self::assertSame([], $this->pending($service, $owner, $source)['commands']);
    }

    public function testVolumeRequiresAdvertisedCapabilityAndReportsOnlyActualAcknowledgedVolume(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $source = $this->endpoint($service, $owner);
        $snapshot = $this->activate($service, $owner, $source);
        $request = ['requestId' => new Uuid()->toString(), 'expectedRevision' => $snapshot['revision'], 'action' => 'volume', 'volume' => 0.6];
        try {
            $service->execute($owner, PlaybackOperation::Control, $request);
            self::fail('Unsupported volume must never create a command.');
        } catch (ConflictException $error) {
            self::assertSame('The destination does not support volume control.', $error->getMessage());
        }
        self::assertSame([], $this->pending($service, $owner, $source)['commands']);
        self::assertSame($snapshot['revision'], $service->execute($owner, PlaybackOperation::Snapshot, [])['revision']);
        $owner = $this->createUser();
        $source = $this->endpoint($service, $owner, capabilities: ['song', 'video', 'volume']);
        $snapshot = $this->activate($service, $owner, $source);
        $request = ['requestId' => new Uuid()->toString(), 'expectedRevision' => $snapshot['revision'], 'action' => 'volume', 'volume' => 0.6];
        $snapshot = $service->execute($owner, PlaybackOperation::Control, $request);
        $destinations = array_values(array_filter($snapshot['destinations'], static fn(array $destination): bool => $destination['incarnationId'] === $source['incarnationId']));
        self::assertNull($destinations[0]['observedVolume']);
        $command = $this->pending($service, $owner, $source)['commands'][0];
        self::assertSame('volume', $command['type']);
        self::assertSame(0.6, $command['payload']['volume']);
        try {
            $this->ack($service, $owner, $source, $command, 'applied', volume: 1.1);
            self::fail('Observed volume must remain bounded.');
        } catch (\App\Shared\Application\Exception\InvalidInputException $error) {
            self::assertStringContainsString('Volume', $error->getMessage());
        }
        self::assertCount(1, $this->pending($service, $owner, $source)['commands']);
        $snapshot = $this->ack($service, $owner, $source, $command, 'applied', volume: 0.59);
        $destinations = array_values(array_filter($snapshot['destinations'], static fn(array $destination): bool => $destination['incarnationId'] === $source['incarnationId']));
        self::assertSame(0.59, $destinations[0]['observedVolume']);
        self::assertSame('stopped', $snapshot['observedState']);
        self::assertSame(0.0, $snapshot['position']);
        self::assertSame([], $this->pending($service, $owner, $source)['commands']);
    }

    public function testExplicitStopSupersedesAnUnacknowledgedPlayWithoutReplayingIt(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $source = $this->endpoint($service, $owner);
        $snapshot = $this->activate($service, $owner, $source);
        $snapshot = $service->execute($owner, PlaybackOperation::Control, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $snapshot['revision'], 'action' => 'play']);
        $play = $this->pending($service, $owner, $source)['commands'][0];
        $snapshot = $service->execute($owner, PlaybackOperation::Control, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $snapshot['revision'], 'action' => 'stop']);
        $commands = $this->pending($service, $owner, $source)['commands'];
        self::assertCount(1, $commands);
        self::assertSame('stop', $commands[0]['type']);
        self::assertNotSame($play['id'], $commands[0]['id']);
        try {
            $this->ack($service, $owner, $source, $play, 'playing');
            self::fail('Late play must not override a newer stop.');
        } catch (ConflictException $error) {
            self::assertSame('Command outcome has already been recorded.', $error->getMessage());
        }
        $snapshot = $this->ack($service, $owner, $source, $commands[0], 'stopped');
        self::assertSame('stopped', $snapshot['observedState']);
    }

    public function testExpiredOrdinaryControlIsReconciledByStopBeforeAnotherPlaybackRequest(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $source = $this->endpoint($service, $owner);
        $snapshot = $this->activate($service, $owner, $source);
        $service->execute($owner, PlaybackOperation::Control, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $snapshot['revision'], 'action' => 'play']);
        $play = $this->pending($service, $owner, $source)['commands'][0];
        $this->manager->getConnection()->executeStatement("UPDATE playback_commands SET expires_at=CURRENT_TIMESTAMP-INTERVAL '1 second' WHERE id=:id", ['id' => $play['id']]);
        $snapshot = $this->pending($service, $owner, $source);
        self::assertSame('unknown', $snapshot['observedState']);
        self::assertSame('stopped', $snapshot['desiredState']);
        self::assertCount(1, $snapshot['commands']);
        self::assertSame('stop', $snapshot['commands'][0]['type']);
        self::assertGreaterThan($play['revision'], $snapshot['commands'][0]['revision']);
        try {
            $service->execute($owner, PlaybackOperation::Control, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $snapshot['revision'], 'action' => 'play']);
            self::fail('Uncertain execution must stop before another play.');
        } catch (ConflictException $error) {
            self::assertSame('A runner control is awaiting acknowledgement.', $error->getMessage());
        }
        $snapshot = $this->ack($service, $owner, $source, $snapshot['commands'][0], 'stopped');
        $service->execute($owner, PlaybackOperation::Control, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $snapshot['revision'], 'action' => 'play']);
        self::assertSame('play', $this->pending($service, $owner, $source)['commands'][0]['type']);
    }

    public function testExpiredVolumeNeverStopsPlaybackOrFabricatesObservedVolume(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $source = $this->endpoint($service, $owner, capabilities: ['song', 'video', 'volume']);
        $snapshot = $this->activate($service, $owner, $source);
        $service->execute($owner, PlaybackOperation::Control, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $snapshot['revision'], 'action' => 'volume', 'volume' => 0.5]);
        $command = $this->pending($service, $owner, $source)['commands'][0];
        $this->manager->getConnection()->executeStatement("UPDATE playback_commands SET expires_at=CURRENT_TIMESTAMP-INTERVAL '1 second' WHERE id=:id", ['id' => $command['id']]);
        $after = $this->pending($service, $owner, $source);
        self::assertSame([], $after['commands']);
        self::assertSame($snapshot['observedState'], $after['observedState']);
        self::assertSame($snapshot['desiredState'], $after['desiredState']);
        self::assertNull($after['destinations'][0]['observedVolume']);
        $service->execute($owner, PlaybackOperation::Control, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $after['revision'], 'action' => 'volume', 'volume' => 0.25]);
        self::assertSame(0.25, $this->pending($service, $owner, $source)['commands'][0]['payload']['volume']);
    }

    public function testExpiredSourcePresenceDoesNotAuthorizeStartingTheTarget(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $source = $this->endpoint($service, $owner);
        $target = $this->endpoint($service, $owner);
        $snapshot = $this->activate($service, $owner, $source);
        $this->manager->getConnection()->executeStatement("UPDATE playback_destination_incarnations SET expires_at=CURRENT_TIMESTAMP-INTERVAL '1 second' WHERE id=:id", ['id' => $source['incarnationId']]);
        $service->execute($owner, PlaybackOperation::Transfer, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $snapshot['revision'], 'targetIncarnationId' => $target['incarnationId']]);
        $snapshot = $this->ack($service, $owner, $target, $this->pending($service, $owner, $target)['commands'][0], 'prepared');
        self::assertSame('quiescing', $snapshot['transfer']['state']);
        self::assertSame($source['incarnationId'], $snapshot['activeIncarnationId']);
        self::assertSame([], $this->pending($service, $owner, $target)['commands']);
        self::assertSame('quiesce', $this->pending($service, $owner, $source)['commands'][0]['type']);
        $sourceRows = array_values(array_filter($snapshot['destinations'], static fn(array $destination): bool => $destination['incarnationId'] === $source['incarnationId']));
        self::assertFalse($sourceRows[0]['available']);
    }

    public function testLostStartAcknowledgementRetainsPausedSourceAndFencesLateReplay(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $source = $this->endpoint($service, $owner);
        $target = $this->endpoint($service, $owner);
        $snapshot = $this->activate($service, $owner, $source);
        $service->execute($owner, PlaybackOperation::Transfer, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $snapshot['revision'], 'targetIncarnationId' => $target['incarnationId']]);
        $this->ack($service, $owner, $target, $this->pending($service, $owner, $target)['commands'][0], 'prepared');
        $this->ack($service, $owner, $source, $this->pending($service, $owner, $source)['commands'][0], 'quiesced', 42.5);
        $start = $this->pending($service, $owner, $target)['commands'][0];
        $this->manager->getConnection()->executeStatement("UPDATE playback_transfers SET expires_at=CURRENT_TIMESTAMP-INTERVAL '1 second' WHERE user_id=:owner AND state='starting'", ['owner' => $owner->toString()]);
        $snapshot = $this->pending($service, $owner, $target);
        self::assertSame('uncertain', $snapshot['transfer']['state']);
        self::assertSame('paused', $snapshot['desiredState']);
        self::assertSame($source['incarnationId'], $snapshot['activeIncarnationId']);
        self::assertSame('stop', $snapshot['commands'][0]['type']);
        try {
            $this->ack($service, $owner, $target, $start, 'started');
            self::fail('A superseded startup cannot commit.');
        } catch (ConflictException $error) {
            self::assertSame('Command outcome has already been recorded.', $error->getMessage());
        }
        $snapshot = $this->ack($service, $owner, $target, $snapshot['commands'][0], 'stopped');
        self::assertNull($snapshot['transfer']);
        self::assertSame('paused', $snapshot['desiredState']);
        self::assertSame(42.5, $snapshot['position']);
    }

    public function testRequestIdentityRejectsChangedTargetAndControlsSerialize(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $source = $this->endpoint($service, $owner);
        $target = $this->endpoint($service, $owner);
        $snapshot = $this->activate($service, $owner, $source);
        $request = ['requestId' => new Uuid()->toString(), 'expectedRevision' => $snapshot['revision'], 'action' => 'pause'];
        $snapshot = $service->execute($owner, PlaybackOperation::Control, $request);
        self::assertSame($snapshot['revision'], $service->execute($owner, PlaybackOperation::Control, $request)['revision']);
        foreach ([array_replace($request, ['action' => 'play']), ['requestId' => new Uuid()->toString(), 'expectedRevision' => $snapshot['revision'], 'action' => 'play']] as $conflict) {
            try {
                $service->execute($owner, PlaybackOperation::Control, $conflict);
                self::fail('Conflicting control must wait.');
            } catch (ConflictException $error) {
                self::assertMatchesRegularExpression('/request identity|awaiting acknowledgement/', $error->getMessage());
            }
        }
        $snapshot = $this->ack($service, $owner, $source, $this->pending($service, $owner, $source)['commands'][0], 'paused');
        $snapshot = $service->execute($owner, PlaybackOperation::Transfer, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $snapshot['revision'], 'targetIncarnationId' => $target['incarnationId']]);
        self::assertSame('preparing', $snapshot['transfer']['state']);
    }

    public function testCommandInsertionFailureRollsBackTransferRevisionAndRequestIdentity(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $target = $this->endpoint($service, $owner);
        $snapshot = $service->execute($owner, PlaybackOperation::Snapshot, []);
        $connection = $this->manager->getConnection();
        $connection->executeStatement("CREATE FUNCTION pg_temp.reject_playback_command() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''command insertion refused''; END;'");
        $connection->executeStatement('CREATE TRIGGER reject_playback_command BEFORE INSERT ON playback_commands FOR EACH ROW EXECUTE FUNCTION pg_temp.reject_playback_command()');
        $request = ['requestId' => new Uuid()->toString(), 'expectedRevision' => $snapshot['revision'], 'targetIncarnationId' => $target['incarnationId']];
        try {
            $service->execute($owner, PlaybackOperation::Transfer, $request);
            self::fail('The injected insert failure must escape.');
        } catch (\Doctrine\DBAL\Exception $error) {
            self::assertStringContainsString('command insertion refused', $error->getMessage());
        }
        $connection->executeStatement('DROP TRIGGER reject_playback_command ON playback_commands');
        $after = $service->execute($owner, PlaybackOperation::Snapshot, []);
        self::assertSame($snapshot['revision'], $after['revision']);
        self::assertNull($after['transfer']);
        self::assertSame('preparing', $service->execute($owner, PlaybackOperation::Transfer, $request)['transfer']['state']);
    }

    public function testNewCoordinatorReplaysTheSamePersistedCommandIdentity(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $target = $this->endpoint($service, $owner);
        $service->execute($owner, PlaybackOperation::Transfer, ['requestId' => new Uuid()->toString(), 'expectedRevision' => 0, 'targetIncarnationId' => $target['incarnationId']]);
        $before = $this->pending($service, $owner, $target)['commands'];
        unset($service);
        $after = $this->pending($this->coordinator(), $owner, $target)['commands'];
        self::assertCount(1, $after);
        self::assertSame($before, $after);
        self::assertMatchesRegularExpression('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}[+-]\d{2}:\d{2}\z/', $after[0]['expiresAt']);
        $storedExpiry = $this->observer->fetchOne('SELECT expires_at FROM playback_commands WHERE id = ?', [$after[0]['id']]);
        self::assertEqualsWithDelta((float) new \DateTimeImmutable($storedExpiry)->format('U.u'), (float) new \DateTimeImmutable($after[0]['expiresAt'])->format('U.u'), .001);
    }

    public function testQueueBeforeFirstPlaybackIsRetainedWithoutInventingAnActiveRunner(): void
    {
        $owner = $this->createUser();
        $library = new \App\Library\Infrastructure\Doctrine\Entity\LibraryEntity('Music', 'music', '/playback/music', 'music', 'local');
        $album = new \App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity(new PublicId(), $library, 'Album', 'album');
        $song = new \App\Catalog\Infrastructure\Doctrine\Entity\SongEntity(new PublicId(), $album, 'Song', '/playback/music/song.flac', 1, 'audio/flac');
        foreach ([$library, $album, $song, new \App\Library\Infrastructure\Doctrine\Entity\UserLibraryAccessEntity($owner, $library, new \DateTimeImmutable())] as $entity) {
            $this->manager->persist($entity);
        }
        $this->manager->flush();
        $service = $this->coordinator();
        $request = ['requestId' => new Uuid()->toString(), 'expectedRevision' => 0, 'action' => 'queue',
            'queue' => [$song->getPublicId()->toString()], 'currentTrackIndex' => 0];
        $snapshot = $service->execute($owner, PlaybackOperation::Control, $request);
        self::assertSame($request['queue'], $snapshot['queue']);
        self::assertNull($snapshot['media']);
        self::assertNull($snapshot['activeIncarnationId']);
        self::assertSame('stopped', $snapshot['desiredState']);
        self::assertSame('retained', $snapshot['requestResult']['outcome']);
        self::assertSame($snapshot['requestResult'], $service->execute($owner, PlaybackOperation::Control, $request)['requestResult']);
        self::assertSame('retained', $this->observer->fetchOne('SELECT outcome FROM playback_commands WHERE request_id = ?', [$request['requestId']]));
    }

    public function testTypedVideoUsesLibraryPermissionAndPreservesTheExistingMusicQueue(): void
    {
        $owner = $this->createUser();
        $music = new \App\Library\Infrastructure\Doctrine\Entity\LibraryEntity('Music', 'music', '/playback/music', 'music', 'local');
        $videos = new \App\Library\Infrastructure\Doctrine\Entity\LibraryEntity('Movies', 'movies', '/playback/video', 'video', 'local');
        $album = new \App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity(new PublicId(), $music, 'Album', 'album');
        $song = new \App\Catalog\Infrastructure\Doctrine\Entity\SongEntity(new PublicId(), $album, 'Song', '/playback/music/song.flac', 1, 'audio/flac');
        $movie = new \App\Catalog\Infrastructure\Doctrine\Entity\MovieEntity(new PublicId(), $videos, 'Movie');
        $video = new \App\Catalog\Infrastructure\Doctrine\Entity\VideoEntity(new PublicId(), '/playback/video/movie.mkv', 'fixture-hash');
        foreach ([$music, $videos, $album, $song, $movie, $video, new \App\Catalog\Infrastructure\Doctrine\Entity\MovieVideoEntity($movie, $video), new \App\Library\Infrastructure\Doctrine\Entity\UserLibraryAccessEntity($owner, $music, new \DateTimeImmutable())] as $entity) {
            $this->manager->persist($entity);
        }
        $this->manager->flush();
        $service = $this->coordinator();
        $queue = [$song->getPublicId()->toString(), $song->getPublicId()->toString()];
        $snapshot = $service->execute($owner, PlaybackOperation::Control, ['requestId' => new Uuid()->toString(), 'expectedRevision' => 0, 'action' => 'load',
            'media' => ['kind' => 'song', 'publicId' => $queue[0]], 'queue' => $queue, 'currentTrackIndex' => 1, 'position' => 12.5, 'desiredState' => 'playing']);
        $videoRequest = ['requestId' => new Uuid()->toString(), 'expectedRevision' => $snapshot['revision'], 'action' => 'load', 'media' => ['kind' => 'video', 'publicId' => $video->getPublicId()->toString()], 'position' => 83.25, 'desiredState' => 'paused'];
        try {
            $service->execute($owner, PlaybackOperation::Control, $videoRequest);
            self::fail('A song grant cannot authorize a video library.');
        } catch (ConflictException $error) {
            self::assertSame('Playback media is unavailable.', $error->getMessage());
        }
        self::assertSame($snapshot['revision'], $service->execute($owner, PlaybackOperation::Snapshot, [])['revision']);
        $grantedLibrary = $this->manager->find(\App\Library\Infrastructure\Doctrine\Entity\LibraryEntity::class, $videos->getId());
        self::assertInstanceOf(\App\Library\Infrastructure\Doctrine\Entity\LibraryEntity::class, $grantedLibrary);
        $this->manager->persist(new \App\Library\Infrastructure\Doctrine\Entity\UserLibraryAccessEntity($owner, $grantedLibrary, new \DateTimeImmutable()));
        $this->manager->flush();
        $snapshot = $service->execute($owner, PlaybackOperation::Control, $videoRequest);
        self::assertSame($queue, $snapshot['queue']);
        self::assertSame(1, $snapshot['currentTrackIndex']);
        self::assertSame(['kind' => 'video', 'publicId' => $video->getPublicId()->toString()], $snapshot['media']);
        self::assertSame('paused', $snapshot['desiredState']);
        self::assertSame(83.25, $snapshot['position']);
        $target = $this->endpoint($service, $owner);
        $snapshot = $this->activate($service, $owner, $target);
        self::assertSame('paused', $snapshot['observedState']);
        self::assertSame(83.25, $snapshot['position']);
        self::assertSame($queue, $snapshot['queue']);
    }

    public function testCommittedUndeliveredIntentSurvivesAClosedProducerConnection(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $target = $this->endpoint($service, $owner);
        $service->execute($owner, PlaybackOperation::Transfer, ['requestId' => new Uuid()->toString(), 'expectedRevision' => 0, 'targetIncarnationId' => $target['incarnationId']]);
        $commandId = $this->observer->fetchOne('SELECT id FROM playback_commands WHERE user_id=:owner', ['owner' => $owner->toString()]);
        self::assertIsString($commandId);
        $this->manager->getConnection()->close();
        $fresh = PlaybackPersistenceFixture::coordinator(PlaybackPersistenceFixture::manager($this->observer));
        $replayed = $this->pending($fresh, $owner, $target);
        self::assertSame($commandId, $replayed['commands'][0]['id']);
        self::assertSame(1, $replayed['revision']);
    }

    public function testEnclosingRollbackIsInvisibleToObserverAndCannotDeliverCommands(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $target = $this->endpoint($service, $owner);
        $connection = $this->manager->getConnection();
        $connection->beginTransaction();
        $service->execute($owner, PlaybackOperation::Transfer, ['requestId' => new Uuid()->toString(), 'expectedRevision' => 0, 'targetIncarnationId' => $target['incarnationId']]);
        self::assertSame(0, (int) $this->observer->fetchOne('SELECT revision FROM listening_sessions WHERE user_id=:owner', ['owner' => $owner->toString()]));
        self::assertSame(0, (int) $this->observer->fetchOne('SELECT count(*) FROM playback_commands'));
        self::assertSame([], $this->pending($service, $owner, $target)['commands']);
        $connection->rollBack();
        self::assertSame(0, (int) $this->observer->fetchOne('SELECT count(*) FROM playback_transfers'));
        self::assertSame(0, (int) $this->observer->fetchOne('SELECT count(*) FROM playback_commands'));
        self::assertSame(0, $service->execute($owner, PlaybackOperation::Snapshot, [])['revision']);
    }

    public function testIndependentSimultaneousTransferRequestsCommitOnlyOneAttempt(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $target = $this->endpoint($service, $owner);
        $connection = $this->manager->getConnection();
        $connection->beginTransaction();
        new PlaybackStore($this->manager)->lockSession($owner->toString());
        $application = 'playback_race_' . bin2hex(random_bytes(6));
        $errors = tmpfile();
        self::assertIsResource($errors);
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/Fixtures/Session/playback-concurrent-transfer.php', $this->schemaName, $application, $owner->toString(), $target['incarnationId'], '0'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => $errors], $pipes);
        self::assertIsResource($process);
        try {
            stream_set_timeout($pipes[1], 10);
            self::assertSame("ready\n", fgets($pipes[1]));
            fwrite($pipes[0], "go\n");
            fflush($pipes[0]);
            $blocked = false;
            $deadline = microtime(true) + 8;
            do {
                $blocked = (bool) $this->observer->fetchOne("SELECT EXISTS(SELECT 1 FROM pg_stat_activity WHERE application_name=:application AND wait_event_type='Lock')", ['application' => $application]);
                if (!$blocked) {
                    usleep(10000);
                }
            } while (!$blocked && microtime(true) < $deadline);
            self::assertTrue($blocked, 'The second database connection must actually contend on the session writer.');
            $service->execute($owner, PlaybackOperation::Transfer, ['requestId' => new Uuid()->toString(), 'expectedRevision' => 0, 'targetIncarnationId' => $target['incarnationId']]);
            self::assertSame(0, (int) $this->observer->fetchOne('SELECT count(*) FROM playback_transfers'));
            $connection->commit();
            self::assertSame("conflict\n", fgets($pipes[1]));
            self::assertSame(1, (int) $this->observer->fetchOne('SELECT count(*) FROM playback_transfers'));
            self::assertSame(1, (int) $this->observer->fetchOne('SELECT count(*) FROM playback_commands'));
            self::assertSame(1, (int) $this->observer->fetchOne('SELECT revision FROM listening_sessions WHERE user_id=:owner', ['owner' => $owner->toString()]));
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            proc_close($process);
            fclose($errors);
        }
    }

    public function testReconnectUsesExplicitOldEndpointStopProofWithoutInheritingItsAuthority(): void
    {
        $owner = $this->createUser();
        $device = new Uuid();
        new DeviceDoctrineRepository($this->manager)->save(Device::create($owner, $device, 'Same tab'));
        $registration = ['deviceId' => $device->toString(), 'endpointId' => new Uuid()->toString()];
        $service = $this->coordinator();
        $old = $service->execute($owner, PlaybackOperation::Endpoint, $registration)['endpoint'];
        $snapshot = $this->activate($service, $owner, $old);
        $service->execute($owner, PlaybackOperation::Observe, ['incarnationId' => $old['incarnationId'], 'expectedRevision' => $snapshot['revision'], 'observedState' => 'stopped', 'position' => 71.625], $old['capability']);
        $new = $service->execute($owner, PlaybackOperation::Endpoint, $registration)['endpoint'];
        self::assertNotSame($old['incarnationId'], $new['incarnationId']);
        self::assertNotSame($old['capability'], $new['capability']);
        self::assertSame($old['epoch'] + 1, $new['epoch']);
        $destinations = $service->execute($owner, PlaybackOperation::Snapshot, [])['destinations'];
        self::assertCount(1, $destinations);
        self::assertSame($new['incarnationId'], $destinations[0]['incarnationId']);
        $snapshot = $service->execute($owner, PlaybackOperation::Transfer, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $snapshot['revision'], 'targetIncarnationId' => $new['incarnationId']]);
        self::assertNull($snapshot['transfer']['sourceIncarnationId']);
        self::assertSame(71.625, $snapshot['position']);
        $this->ack($service, $owner, $new, $this->pending($service, $owner, $new)['commands'][0], 'prepared');
        $start = $this->pending($service, $owner, $new)['commands'][0];
        self::assertSame('start', $start['type']);
        self::assertSame(71.625, $start['payload']['position']);
        self::assertSame([], $this->pending($service, $owner, $old)['commands']);
    }

    public function testAdditiveMigrationPreservesPopulatedSessionAndMapsOnlyItsOwnedDevice(): void
    {
        $connection = $this->manager->getConnection();
        $connection->beginTransaction();
        $schemaName = 'playback_migration_' . bin2hex(random_bytes(5));
        $connection->executeStatement('CREATE SCHEMA ' . $schemaName);
        $connection->executeStatement('SET LOCAL search_path TO ' . $schemaName . ', public');
        $owner = new Uuid()->toString();
        $device = new Uuid()->toString();
        $session = new Uuid()->toString();
        $publicId = new PublicId()->toString();
        $queue = [new PublicId()->toString(), new PublicId()->toString()];
        $connection->executeStatement('CREATE TABLE users(id uuid PRIMARY KEY)');
        $connection->executeStatement('CREATE TABLE devices(id uuid PRIMARY KEY,public_id text NOT NULL,user_id uuid NOT NULL,device_id uuid NOT NULL,name text,created_at timestamptz NOT NULL)');
        $connection->executeStatement("CREATE TABLE listening_sessions(id uuid PRIMARY KEY,public_id text NOT NULL,user_id uuid NOT NULL UNIQUE,active_device_id uuid,queue jsonb NOT NULL,current_track_index integer NOT NULL,position double precision NOT NULL,playback_state text NOT NULL,created_at timestamptz NOT NULL,updated_at timestamptz NOT NULL,last_used_at timestamptz)");
        $connection->executeStatement('INSERT INTO users VALUES(:id)', ['id' => $owner]);
        $connection->executeStatement("INSERT INTO devices VALUES(:id,:publicId,:owner,:device,'Preserved browser',CURRENT_TIMESTAMP)", ['id' => new Uuid()->toString(), 'publicId' => new PublicId()->toString(), 'owner' => $owner, 'device' => $device]);
        $connection->executeStatement("INSERT INTO listening_sessions VALUES(:id,:publicId,:owner,:device,CAST(:queue AS jsonb),1,83.125,'paused',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,NULL)", ['id' => $session, 'publicId' => $publicId, 'owner' => $owner, 'device' => $device, 'queue' => json_encode($queue, JSON_THROW_ON_ERROR)]);
        require_once dirname(__DIR__, 2) . '/migrations/Version20261101190000.php';
        $migration = new \DoctrineMigrations\Version20261101190000($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
        $row = $connection->fetchAssociative('SELECT * FROM listening_sessions');
        self::assertIsArray($row);
        self::assertSame($session, $row['id']);
        self::assertSame($publicId, $row['public_id']);
        self::assertSame($queue, json_decode($row['queue'], true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(83.125, (float) $row['position']);
        self::assertSame(['kind' => 'song', 'publicId' => $queue[1]], json_decode($row['media'], true, 512, JSON_THROW_ON_ERROR));
        self::assertNotNull($row['selected_destination_id']);
        self::assertNull($row['active_incarnation_id']);
        self::assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM playback_destination_incarnations'));
        $connection->rollBack();
    }
}
