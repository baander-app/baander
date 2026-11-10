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
        self::assertSame(['requestId' => $request['requestId'], 'commandId' => $command['id'], 'outcome' => null, 'rejectionReason' => null], $pending['requestResult']);
        $acknowledged = $this->ack($service, $owner, $source, $command, 'stopped');
        self::assertGreaterThan($pending['revision'], $acknowledged['revision']);
        self::assertArrayNotHasKey('requestResult', $service->execute($owner, PlaybackOperation::Snapshot, []));
        $target = $this->endpoint($service, $owner);
        $service->execute($owner, PlaybackOperation::Transfer, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $acknowledged['revision'], 'targetIncarnationId' => $target['incarnationId']]);
        $replay = $service->execute($owner, PlaybackOperation::Control, $request);
        self::assertSame(['requestId' => $request['requestId'], 'commandId' => $command['id'], 'outcome' => 'stopped', 'rejectionReason' => null], $replay['requestResult']);
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
        // The runner never asked for this one, so it expires as a command that changed nothing there.
        $expired = $service->execute($owner, PlaybackOperation::Control, $request);
        self::assertSame('never_effective', $expired['requestResult']['outcome']);
        self::assertSame($acknowledged['desiredState'], $expired['desiredState']);
        self::assertSame([], $this->pending($service, $owner, $source)['commands']);

        // One the runner was shown and did not answer may have been applied, and stays superseded.
        $request['requestId'] = new Uuid()->toString();
        $request['expectedRevision'] = $expired['revision'];
        $shown = $service->execute($owner, PlaybackOperation::Control, $request);
        $disclosed = $this->pending($service, $owner, $source)['commands'];
        self::assertSame(['queue'], array_column($disclosed, 'type'));
        $this->observer->executeStatement("UPDATE playback_commands SET expires_at=CURRENT_TIMESTAMP-INTERVAL '1 second' WHERE id=:id", ['id' => $shown['requestResult']['commandId']]);
        self::assertSame('superseded', $service->execute($owner, PlaybackOperation::Control, $request)['requestResult']['outcome']);
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
        // A source that plays has no idle proof, so its lapsed presence releases nothing.
        $snapshot = $this->playing($service, $owner, $source);
        $this->expireRegistration($source['incarnationId']);
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
        $stopped = $service->execute($owner, PlaybackOperation::Observe, ['incarnationId' => $old['incarnationId'], 'expectedRevision' => $snapshot['revision'], 'observedState' => 'stopped', 'position' => 71.625], $old['capability']);
        // The session never played, so the stop is the state it wants and the registered runner keeps its place.
        self::assertSame($old['incarnationId'], $stopped['activeIncarnationId']);
        self::assertSame('stopped', $stopped['desiredState']);
        self::assertSame('stopped', $stopped['observedState']);
        self::assertSame(71.625, $stopped['position']);
        self::assertSame($snapshot['revision'], $stopped['revision']);
        $registered = $service->execute($owner, PlaybackOperation::Endpoint, $registration);
        $new = $registered['endpoint'];
        self::assertNotSame($old['incarnationId'], $new['incarnationId']);
        self::assertNotSame($old['capability'], $new['capability']);
        self::assertSame($old['epoch'] + 1, $new['epoch']);
        // Registering the same endpoint again retired the old incarnation; its stop report releases the
        // session in the registration itself, and a later snapshot has nothing left to release.
        self::assertNull($registered['activeIncarnationId']);
        self::assertSame($stopped['revision'] + 1, $registered['revision']);
        $snapshot = $service->execute($owner, PlaybackOperation::Snapshot, []);
        self::assertCount(1, $snapshot['destinations']);
        self::assertSame($new['incarnationId'], $snapshot['destinations'][0]['incarnationId']);
        self::assertNull($snapshot['activeIncarnationId']);
        self::assertNull($snapshot['selectedDestinationId']);
        self::assertSame('stopped', $snapshot['desiredState']);
        self::assertSame('stopped', $snapshot['observedState']);
        self::assertSame(71.625, $snapshot['position']);
        self::assertSame($stopped['revision'] + 1, $snapshot['revision']);
        $snapshot = $service->execute($owner, PlaybackOperation::Transfer, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $snapshot['revision'], 'targetIncarnationId' => $new['incarnationId']]);
        self::assertNull($snapshot['transfer']['sourceIncarnationId']);
        self::assertSame(71.625, $snapshot['position']);
        $this->ack($service, $owner, $new, $this->pending($service, $owner, $new)['commands'][0], 'prepared');
        $start = $this->pending($service, $owner, $new)['commands'][0];
        self::assertSame('start', $start['type']);
        self::assertSame(71.625, $start['payload']['position']);
        self::assertSame([], $this->pending($service, $owner, $old)['commands']);
    }

    public function testADeliberatelyStoppedRunnerIsReleasedOnlyAfterItsRegistrationLapses(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $runner = $this->endpoint($service, $owner);
        $stopped = $this->playThenStop($service, $owner, $runner);
        self::assertSame($runner['incarnationId'], $stopped['activeIncarnationId']);
        self::assertSame('stopped', $stopped['desiredState']);
        self::assertSame('stopped', $stopped['observedState']);

        $other = $this->endpoint($service, $owner);
        $live = $this->pending($service, $owner, $other);
        self::assertSame($runner['incarnationId'], $live['activeIncarnationId']);
        self::assertSame($stopped['revision'], $live['revision']);
        self::assertNull($this->observer->fetchOne('SELECT retired_at FROM playback_destination_incarnations WHERE id=?', [$runner['incarnationId']]));

        $this->manager->getConnection()->executeStatement("UPDATE playback_destination_incarnations SET expires_at=CURRENT_TIMESTAMP-INTERVAL '1 second' WHERE id=:id", ['id' => $runner['incarnationId']]);
        $released = $this->pending($service, $owner, $other);
        self::assertNull($released['activeIncarnationId']);
        self::assertNull($released['activeDestinationId']);
        self::assertNull($released['selectedDestinationId']);
        self::assertSame('stopped', $released['desiredState']);
        self::assertSame('stopped', $released['observedState']);
        self::assertSame(42.5, $released['position']);
        self::assertSame($stopped['revision'] + 1, $released['revision']);
        self::assertNotNull($this->observer->fetchOne('SELECT retired_at FROM playback_destination_incarnations WHERE id=?', [$runner['incarnationId']]));
        self::assertSame($released['revision'], $this->pending($service, $owner, $other)['revision']);

        $transfer = $service->execute($owner, PlaybackOperation::Transfer, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $released['revision'], 'targetIncarnationId' => $other['incarnationId']]);
        self::assertNull($transfer['transfer']['sourceIncarnationId']);
        self::assertSame(42.5, $transfer['position']);
        self::assertSame([], $this->pending($service, $owner, $runner)['commands']);
    }

    public function testALapsedStoppedRunnerKeepsItsPlaceWithoutItsOwnStopReportOrWithWorkOutstanding(): void
    {
        $service = $this->coordinator();
        $connection = $this->manager->getConnection();
        $changes = [
            "UPDATE playback_destination_incarnations SET observed_state='playing' WHERE id=:incarnation",
            "UPDATE playback_destination_incarnations SET observed_state='unknown' WHERE id=:incarnation",
            "UPDATE playback_destinations SET kind='cast' WHERE id=(SELECT destination_id FROM playback_destination_incarnations WHERE id=:incarnation)",
            "UPDATE playback_commands SET acknowledged_at=NULL,outcome=NULL,expires_at=CURRENT_TIMESTAMP+INTERVAL '1 hour' WHERE incarnation_id=:incarnation AND type='stop'",
            "UPDATE listening_sessions SET observed_state='unknown' WHERE active_incarnation_id=:incarnation",
            "UPDATE listening_sessions SET desired_state='playing',playback_state='playing' WHERE active_incarnation_id=:incarnation",
        ];
        foreach ($changes as $change) {
            $owner = $this->createUser();
            $runner = $this->endpoint($service, $owner);
            $stopped = $this->playThenStop($service, $owner, $runner);
            $connection->executeStatement("UPDATE playback_destination_incarnations SET expires_at=CURRENT_TIMESTAMP-INTERVAL '1 second' WHERE id=:id", ['id' => $runner['incarnationId']]);
            $connection->executeStatement($change, ['incarnation' => $runner['incarnationId']]);
            $snapshot = $service->execute($owner, PlaybackOperation::Snapshot, []);
            self::assertSame($runner['incarnationId'], $snapshot['activeIncarnationId'], $change);
            self::assertSame($stopped['revision'], $snapshot['revision'], $change);
            self::assertNull($this->observer->fetchOne('SELECT retired_at FROM playback_destination_incarnations WHERE id=?', [$runner['incarnationId']]), $change);
        }
    }

    /**
     * @param array<string,mixed> $runner
     * @return array<string,mixed>
     */
    private function playThenStop(PlaybackCoordinator $service, Uuid $owner, array $runner): array
    {
        $snapshot = $this->playing($service, $owner, $runner);
        $service->execute($owner, PlaybackOperation::Control, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $snapshot['revision'], 'action' => 'stop']);
        return $this->ack($service, $owner, $runner, $this->pending($service, $owner, $runner)['commands'][0], 'stopped', 0.0);
    }

    /**
     * @param array<string,mixed> $runner
     * @return array<string,mixed>
     */
    private function playing(PlaybackCoordinator $service, Uuid $owner, array $runner): array
    {
        $snapshot = $this->activate($service, $owner, $runner);
        $service->execute($owner, PlaybackOperation::Control, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $snapshot['revision'], 'action' => 'play']);
        return $this->ack($service, $owner, $runner, $this->pending($service, $owner, $runner)['commands'][0], 'playing', 42.5);
    }

    /**
     * A tab that played, was told to pause, acknowledged it at 43 s and reported itself paused.
     *
     * @param list<string> $capabilities
     * @return array{registration:array<string,mixed>,tab:array<string,mixed>,pause:array<string,mixed>,paused:array<string,mixed>}
     */
    private function pausedTab(PlaybackCoordinator $service, Uuid $owner, array $capabilities = ['song', 'video']): array
    {
        $device = new Uuid();
        new DeviceDoctrineRepository($this->manager)->save(Device::create($owner, $device, 'Reloading tab'));
        $registration = ['deviceId' => $device->toString(), 'endpointId' => new Uuid()->toString(), 'capabilities' => $capabilities];
        $tab = $service->execute($owner, PlaybackOperation::Endpoint, $registration)['endpoint'];
        $playing = $this->playing($service, $owner, $tab);
        $pause = ['requestId' => new Uuid()->toString(), 'expectedRevision' => $playing['revision'], 'action' => 'pause', 'incarnationId' => $tab['incarnationId']];
        $sent = $service->execute($owner, PlaybackOperation::Control, $pause, $tab['capability']);
        $paused = $this->ack($service, $owner, $tab, $sent['commands'][0], 'paused', 43.0);
        $service->execute($owner, PlaybackOperation::Observe, ['incarnationId' => $tab['incarnationId'], 'expectedRevision' => $paused['revision'], 'observedState' => 'paused', 'position' => 43.0], $tab['capability']);
        return ['registration' => $registration, 'tab' => $tab, 'pause' => $pause, 'paused' => $paused];
    }

    private function expireRegistration(string $incarnationId): void
    {
        $this->manager->getConnection()->executeStatement("UPDATE playback_destination_incarnations SET expires_at=CURRENT_TIMESTAMP-INTERVAL '1 second' WHERE id=:id", ['id' => $incarnationId]);
    }

    private function expireCommands(Uuid $owner): void
    {
        $this->manager->getConnection()->executeStatement("UPDATE playback_commands SET expires_at=CURRENT_TIMESTAMP-INTERVAL '1 second' WHERE user_id=:owner AND acknowledged_at IS NULL", ['owner' => $owner->toString()]);
    }

    private function expireTransfer(Uuid $owner): void
    {
        $this->manager->getConnection()->executeStatement("UPDATE playback_transfers SET expires_at=CURRENT_TIMESTAMP-INTERVAL '1 second' WHERE user_id=:owner AND state NOT IN ('committed','failed')", ['owner' => $owner->toString()]);
    }

    /** @return list<array<string,mixed>> */
    private function unanswered(Uuid $owner): array
    {
        return $this->observer->fetchAllAssociative('SELECT type,incarnation_id,revision,disclosed_at FROM playback_commands WHERE user_id=? AND acknowledged_at IS NULL ORDER BY created_at,id', [$owner->toString()]);
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed>|null $endpoint
     */
    private function assertRefused(string $reason, PlaybackCoordinator $service, Uuid $owner, PlaybackOperation $operation, array $input, ?array $endpoint = null): void
    {
        try {
            $service->execute($owner, $operation, ['requestId' => new Uuid()->toString()] + $input + ($endpoint === null ? [] : ['incarnationId' => $endpoint['incarnationId']]), $endpoint['capability'] ?? null);
            self::fail($operation->value . ' was accepted.');
        } catch (ConflictException $error) {
            self::assertSame($reason, $error->getMessage());
        }
    }

    public function testAPlayAClosedStoppedRunnerWasNeverShownSettlesAndTheRunnerIsReleasedOnceItsRegistrationLapses(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $runner = $this->endpoint($service, $owner);
        $stopped = $this->playThenStop($service, $owner, $runner);
        $other = $this->endpoint($service, $owner);

        // The runner's tab has closed, so it never asks for its commands again; its registration still holds.
        $request = ['requestId' => new Uuid()->toString(), 'expectedRevision' => $stopped['revision'], 'action' => 'play', 'incarnationId' => $other['incarnationId']];
        $control = $service->execute($owner, PlaybackOperation::Control, $request, $other['capability']);
        self::assertSame($runner['incarnationId'], $control['activeIncarnationId']);
        self::assertSame('playing', $control['desiredState']);
        self::assertNull($control['requestResult']['outcome']);
        self::assertSame([], $control['commands']);

        $this->expireCommands($owner);
        $settled = $this->pending($service, $owner, $other);
        self::assertSame($runner['incarnationId'], $settled['activeIncarnationId']);
        self::assertSame('stopped', $settled['desiredState']);
        self::assertSame('stopped', $settled['observedState']);
        self::assertSame($control['revision'] + 1, $settled['revision']);
        self::assertSame([], $this->unanswered($owner));
        self::assertSame(
            ['outcome' => 'never_effective', 'disclosed_at' => null],
            $this->observer->fetchAssociative('SELECT outcome,disclosed_at FROM playback_commands WHERE id=?', [$request['requestId']]),
        );
        self::assertSame('never_effective', $service->execute($owner, PlaybackOperation::Control, $request, $other['capability'])['requestResult']['outcome']);
        // A runner that is still registered keeps its place.
        self::assertSame($settled['revision'], $this->pending($service, $owner, $other)['revision']);

        $this->expireRegistration($runner['incarnationId']);
        $released = $this->pending($service, $owner, $other);
        self::assertNull($released['activeIncarnationId']);
        self::assertNull($released['selectedDestinationId']);
        self::assertSame('stopped', $released['desiredState']);
        self::assertSame('stopped', $released['observedState']);
        self::assertSame(42.5, $released['position']);
        self::assertSame($settled['revision'] + 1, $released['revision']);

        $play = $service->execute($owner, PlaybackOperation::Control, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $released['revision'], 'action' => 'play', 'incarnationId' => $other['incarnationId']], $other['capability']);
        self::assertSame('retained', $play['requestResult']['outcome']);
        $transfer = $service->execute($owner, PlaybackOperation::Transfer, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $play['revision'], 'targetIncarnationId' => $other['incarnationId']]);
        self::assertNull($transfer['transfer']['sourceIncarnationId']);
        self::assertSame(42.5, $transfer['position']);
    }

    public function testAPlayTheRunnerWasShownKeepsTheSessionUnknownThroughLaterStopExpiriesAndALapsedRegistration(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $device = new Uuid();
        new DeviceDoctrineRepository($this->manager)->save(Device::create($owner, $device, 'Cut-off tab'));
        $registration = ['deviceId' => $device->toString(), 'endpointId' => new Uuid()->toString()];
        $runner = $service->execute($owner, PlaybackOperation::Endpoint, $registration)['endpoint'];
        $stopped = $this->playThenStop($service, $owner, $runner);
        $other = $this->endpoint($service, $owner);

        $service->execute($owner, PlaybackOperation::Control, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $stopped['revision'], 'action' => 'play', 'incarnationId' => $other['incarnationId']], $other['capability']);
        // The runner is shown the play and never answers: it may be playing behind a lost connection.
        self::assertSame('play', $this->pending($service, $owner, $runner)['commands'][0]['type']);

        $this->expireCommands($owner);
        $snapshot = $this->pending($service, $owner, $other);
        self::assertSame($runner['incarnationId'], $snapshot['activeIncarnationId']);
        self::assertSame('stopped', $snapshot['desiredState']);
        self::assertSame('unknown', $snapshot['observedState']);
        self::assertSame('unknown', $this->observer->fetchOne('SELECT observed_state FROM playback_destination_incarnations WHERE id=?', [$runner['incarnationId']]));

        // The stops that follow are never shown to the runner. None of them may pass as harmless.
        for ($expiry = 0; $expiry < 2; ++$expiry) {
            $outstanding = $this->unanswered($owner);
            self::assertCount(1, $outstanding);
            self::assertSame('stop', $outstanding[0]['type']);
            self::assertNull($outstanding[0]['disclosed_at']);
            $this->expireCommands($owner);
            $next = $this->pending($service, $owner, $other);
            self::assertSame($runner['incarnationId'], $next['activeIncarnationId']);
            self::assertSame('unknown', $next['observedState']);
            self::assertGreaterThan($snapshot['revision'], $next['revision']);
            $snapshot = $next;
        }

        $this->expireRegistration($runner['incarnationId']);
        $lapsed = $this->pending($service, $owner, $other);
        self::assertSame($runner['incarnationId'], $lapsed['activeIncarnationId']);
        self::assertSame($snapshot['revision'], $lapsed['revision']);

        $registered = $service->execute($owner, PlaybackOperation::Endpoint, $registration);
        self::assertSame($runner['incarnationId'], $registered['activeIncarnationId']);
        self::assertSame('unknown', $registered['observedState']);
        $this->assertRefused('A runner control is awaiting acknowledgement.', $service, $owner, PlaybackOperation::Control, ['expectedRevision' => $registered['revision'], 'action' => 'play'], $registered['endpoint']);
        $this->assertRefused('A runner control is awaiting acknowledgement.', $service, $owner, PlaybackOperation::Transfer, ['expectedRevision' => $registered['revision'], 'targetIncarnationId' => $other['incarnationId']]);

        $outstanding = $this->unanswered($owner);
        self::assertCount(1, $outstanding);
        self::assertSame(['stop', $runner['incarnationId']], [$outstanding[0]['type'], $outstanding[0]['incarnation_id']]);
        self::assertSame(0, (int) $this->observer->fetchOne("SELECT count(*) FROM playback_commands WHERE user_id=? AND outcome='never_effective'", [$owner->toString()]));
        self::assertSame($runner['incarnationId'], $this->pending($service, $owner, $other)['activeIncarnationId']);
    }

    public function testAStopAConfirmedStoppedRunnerNeverAnswersSettlesAndDoesNotHoldItsRelease(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $runner = $this->endpoint($service, $owner);
        $stopped = $this->playThenStop($service, $owner, $runner);
        $other = $this->endpoint($service, $owner);

        $request = ['requestId' => new Uuid()->toString(), 'expectedRevision' => $stopped['revision'], 'action' => 'stop', 'incarnationId' => $other['incarnationId']];
        $service->execute($owner, PlaybackOperation::Control, $request, $other['capability']);
        // Even a stop the runner was shown cannot start anything on a runner that had confirmed its stop.
        self::assertSame('stop', $this->pending($service, $owner, $runner)['commands'][0]['type']);

        $this->expireCommands($owner);
        $this->expireRegistration($runner['incarnationId']);
        $released = $this->pending($service, $owner, $other);
        self::assertNull($released['activeIncarnationId']);
        self::assertSame('stopped', $released['desiredState']);
        self::assertSame('stopped', $released['observedState']);
        self::assertSame([], $this->unanswered($owner));
        self::assertSame('never_effective', $this->observer->fetchOne('SELECT outcome FROM playback_commands WHERE id=?', [$request['requestId']]));
    }

    public function testAStopSupersedingAPlayTheRunnerWasShownLeavesItUnknownUntilItAnswers(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $runner = $this->endpoint($service, $owner);
        $stopped = $this->playThenStop($service, $owner, $runner);

        $played = $service->execute($owner, PlaybackOperation::Control, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $stopped['revision'], 'action' => 'play']);
        self::assertSame('play', $this->pending($service, $owner, $runner)['commands'][0]['type']);
        $stopping = $service->execute($owner, PlaybackOperation::Control, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $played['revision'], 'action' => 'stop']);
        self::assertSame('stopped', $stopping['desiredState']);
        self::assertSame('unknown', $stopping['observedState']);
        self::assertSame('unknown', $this->observer->fetchOne('SELECT observed_state FROM playback_destination_incarnations WHERE id=?', [$runner['incarnationId']]));

        // The stop is never shown and expires: the play may have run, so nothing settles and nothing is released.
        $this->expireCommands($owner);
        $this->expireRegistration($runner['incarnationId']);
        $unknown = $service->execute($owner, PlaybackOperation::Snapshot, []);
        self::assertSame($runner['incarnationId'], $unknown['activeIncarnationId']);
        self::assertSame('unknown', $unknown['observedState']);
        self::assertCount(1, $this->unanswered($owner));

        // The runner's own answer ends it.
        $answered = $this->ack($service, $owner, $runner, $this->pending($service, $owner, $runner)['commands'][0], 'stopped');
        self::assertSame('stopped', $answered['observedState']);
        self::assertSame('stopped', $this->observer->fetchOne('SELECT observed_state FROM playback_destination_incarnations WHERE id=?', [$runner['incarnationId']]));
    }

    public function testAStopSupersedingAPauseTheRunnerWasShownKeepsItConfirmedAndStillSettles(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $runner = $this->endpoint($service, $owner);
        $stopped = $this->playThenStop($service, $owner, $runner);
        $other = $this->endpoint($service, $owner);

        $paused = $service->execute($owner, PlaybackOperation::Control, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $stopped['revision'], 'action' => 'pause']);
        self::assertSame('pause', $this->pending($service, $owner, $runner)['commands'][0]['type']);
        // A pause cannot start anything, so superseding it leaves the runner's confirmed stop standing.
        $stop = ['requestId' => new Uuid()->toString(), 'expectedRevision' => $paused['revision'], 'action' => 'stop'];
        $stopping = $service->execute($owner, PlaybackOperation::Control, $stop);
        self::assertSame('stopped', $stopping['desiredState']);
        self::assertSame('stopped', $stopping['observedState']);
        self::assertSame('stopped', $this->observer->fetchOne('SELECT observed_state FROM playback_destination_incarnations WHERE id=?', [$runner['incarnationId']]));
        self::assertSame('never_effective', $this->observer->fetchOne('SELECT outcome FROM playback_commands WHERE id=?', [$paused['requestResult']['commandId']]));

        // The runner has gone: the stop expires unanswered, settles, and the lapsed runner is released.
        $this->expireCommands($owner);
        $this->expireRegistration($runner['incarnationId']);
        $released = $this->pending($service, $owner, $other);
        self::assertNull($released['activeIncarnationId']);
        self::assertSame('stopped', $released['desiredState']);
        self::assertSame('stopped', $released['observedState']);
        self::assertSame([], $this->unanswered($owner));
        self::assertSame('never_effective', $this->observer->fetchOne('SELECT outcome FROM playback_commands WHERE id=?', [$stop['requestId']]));
    }

    public function testAPausedRunnerIsReleasedAfterAPlayItWasNeverShownIsStoppedBeforeItExpires(): void
    {
        $service = $this->coordinator();
        foreach (['snapshot', 'registration', 'control', 'transfer'] as $releasing) {
            $owner = $this->createUser();
            ['tab' => $tab, 'paused' => $paused] = $this->pausedTab($service, $owner);
            $other = $this->endpoint($service, $owner);

            // The paused tab has closed. Another tab asks it to play and then, before that expires, to stop.
            $play = ['requestId' => new Uuid()->toString(), 'expectedRevision' => $paused['revision'], 'action' => 'play', 'incarnationId' => $other['incarnationId']];
            $playing = $service->execute($owner, PlaybackOperation::Control, $play, $other['capability']);
            $stop = ['requestId' => new Uuid()->toString(), 'expectedRevision' => $playing['revision'], 'action' => 'stop', 'incarnationId' => $other['incarnationId']];
            $stopping = $service->execute($owner, PlaybackOperation::Control, $stop, $other['capability']);
            self::assertSame('stopped', $stopping['desiredState'], $releasing);
            self::assertSame('paused', $stopping['observedState'], $releasing);
            // The play never left the server, so replacing it settles it; it is no command the runner may have seen.
            self::assertSame(
                ['outcome' => 'never_effective', 'settled_revision' => $playing['revision'], 'disclosed_at' => null],
                $this->observer->fetchAssociative('SELECT outcome,settled_revision,disclosed_at FROM playback_commands WHERE id=?', [$play['requestId']]),
                $releasing,
            );

            // The stop expires unanswered too, and the session is confirmed paused again.
            $this->expireCommands($owner);
            $settled = $this->pending($service, $owner, $other);
            self::assertSame($tab['incarnationId'], $settled['activeIncarnationId'], $releasing);
            self::assertSame('paused', $settled['desiredState'], $releasing);
            self::assertSame('paused', $settled['observedState'], $releasing);
            self::assertSame($stopping['revision'] + 1, $settled['revision'], $releasing);
            self::assertSame(
                ['outcome' => 'never_effective', 'settled_revision' => $settled['revision']],
                $this->observer->fetchAssociative('SELECT outcome,settled_revision FROM playback_commands WHERE id=?', [$stop['requestId']]),
                $releasing,
            );
            self::assertSame($settled['revision'], $this->pending($service, $owner, $other)['revision'], $releasing);

            $this->expireRegistration($tab['incarnationId']);
            $released = match ($releasing) {
                'snapshot' => $this->pending($service, $owner, $other),
                'registration' => $this->registerAnotherTab($service, $owner),
                'control' => $service->execute($owner, PlaybackOperation::Control, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $settled['revision'], 'action' => 'play']),
                'transfer' => $service->execute($owner, PlaybackOperation::Transfer, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $settled['revision'], 'targetIncarnationId' => $other['incarnationId']]),
            };

            self::assertNull($released['activeIncarnationId'], $releasing);
            self::assertSame(43.0, $released['position'], $releasing);
            self::assertNotNull($this->observer->fetchOne('SELECT retired_at FROM playback_destination_incarnations WHERE id=?', [$tab['incarnationId']]), $releasing);
            if ($releasing === 'control') {
                self::assertSame('retained', $released['requestResult']['outcome']);
            }
            if ($releasing === 'transfer') {
                self::assertNull($released['transfer']['sourceIncarnationId']);
            }
        }
    }

    public function testAPausedRunnerIsReleasedAfterAQueueEditOrVolumeChangeItWasNeverShownExpires(): void
    {
        $service = $this->coordinator();
        foreach (['queue' => ['queue' => [], 'currentTrackIndex' => 0], 'volume' => ['volume' => 0.5]] as $action => $payload) {
            foreach ([false, true] as $shown) {
                $owner = $this->createUser();
                ['tab' => $tab, 'paused' => $paused] = $this->pausedTab($service, $owner, ['song', 'video', 'volume']);
                $other = $this->endpoint($service, $owner);
                $case = $action . ($shown ? ' the runner was shown' : ' the runner was never shown');

                // The paused tab has closed; another client edits the queue or changes the volume.
                $request = ['requestId' => new Uuid()->toString(), 'expectedRevision' => $paused['revision'], 'action' => $action, 'incarnationId' => $other['incarnationId']] + $payload;
                $edited = $service->execute($owner, PlaybackOperation::Control, $request, $other['capability']);
                if ($shown) {
                    self::assertSame($action, $this->pending($service, $owner, $tab)['commands'][0]['type'], $case);
                }
                $this->expireCommands($owner);
                $expired = $this->pending($service, $owner, $other);
                self::assertSame($tab['incarnationId'], $expired['activeIncarnationId'], $case);
                self::assertSame('paused', $expired['desiredState'], $case);
                self::assertSame('paused', $expired['observedState'], $case);
                self::assertSame($edited['revision'] + 1, $expired['revision'], $case);
                self::assertSame(
                    $shown ? ['outcome' => 'superseded', 'settled_revision' => null] : ['outcome' => 'never_effective', 'settled_revision' => $expired['revision']],
                    $this->observer->fetchAssociative('SELECT outcome,settled_revision FROM playback_commands WHERE id=?', [$request['requestId']]),
                    $case,
                );

                $this->expireRegistration($tab['incarnationId']);
                $snapshot = $this->pending($service, $owner, $other);

                // A command the runner was shown may have been applied; it still stands against the proof.
                self::assertSame($shown ? $tab['incarnationId'] : null, $snapshot['activeIncarnationId'], $case);
                self::assertSame($shown, $this->observer->fetchOne('SELECT retired_at FROM playback_destination_incarnations WHERE id=?', [$tab['incarnationId']]) === null, $case);
                self::assertSame(43.0, $snapshot['position'], $case);
            }
        }
    }

    public function testAPausedRunnerThatHasGoneIsReleasedByWhicheverOperationFirstSeesTheLapse(): void
    {
        $service = $this->coordinator();
        foreach (['snapshot', 'registration', 'play', 'stop then play', 'transfer'] as $releasing) {
            $owner = $this->createUser();
            // The tab asked for its own pause, as a player does that has reached the end of its media,
            // and acknowledged it at its cursor. Then it closed.
            ['tab' => $tab, 'paused' => $paused] = $this->pausedTab($service, $owner);
            $other = $this->endpoint($service, $owner);
            $this->expireRegistration($tab['incarnationId']);
            $control = fn(string $action, int $revision): array => $service->execute($owner, PlaybackOperation::Control, [
                'requestId' => new Uuid()->toString(), 'expectedRevision' => $revision, 'action' => $action, 'incarnationId' => $other['incarnationId'],
            ], $other['capability']);

            $released = match ($releasing) {
                'snapshot' => $this->pending($service, $owner, $other),
                'registration' => $this->registerAnotherTab($service, $owner),
                'play' => $control('play', $paused['revision']),
                'stop then play' => $control('stop', $paused['revision']),
                'transfer' => $service->execute($owner, PlaybackOperation::Transfer, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $paused['revision'], 'targetIncarnationId' => $other['incarnationId']]),
            };

            self::assertNull($released['activeIncarnationId'], $releasing);
            self::assertSame(43.0, $released['position'], $releasing);
            self::assertNotNull($this->observer->fetchOne('SELECT retired_at FROM playback_destination_incarnations WHERE id=?', [$tab['incarnationId']]), $releasing);
            self::assertNotContains($tab['incarnationId'], array_column($this->unanswered($owner), 'incarnation_id'), $releasing);
            if ($releasing === 'transfer') {
                self::assertNull($released['transfer']['sourceIncarnationId']);
                continue;
            }
            // Nothing was addressed to the gone runner, and the other endpoint can take playback over.
            if ($releasing !== 'play') {
                $released = $control('play', $released['revision']);
            }
            self::assertSame('retained', $released['requestResult']['outcome'], $releasing);
            self::assertSame('playing', $released['desiredState'], $releasing);
            $transfer = $service->execute($owner, PlaybackOperation::Transfer, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $released['revision'], 'targetIncarnationId' => $other['incarnationId']]);
            self::assertNull($transfer['transfer']['sourceIncarnationId'], $releasing);
            self::assertSame([], $this->pending($service, $owner, $tab)['commands'], $releasing);
        }
    }

    public function testARunnerThatOnlyReportedItselfPausedWhilePlayingWasWantedStaysHeldOnceItHasGone(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $runner = $this->endpoint($service, $owner);
        $playing = $this->playing($service, $owner, $runner);
        // The runner reports that it is paused, without having been asked to pause. While the session
        // wants it playing it may resume without another command, so this report is not its last word.
        $reported = $service->execute($owner, PlaybackOperation::Observe, ['incarnationId' => $runner['incarnationId'], 'expectedRevision' => $playing['revision'], 'observedState' => 'paused', 'position' => 120.0], $runner['capability']);
        self::assertSame('playing', $reported['desiredState']);
        self::assertSame('paused', $reported['observedState']);
        self::assertSame(120.0, $reported['position']);
        $other = $this->endpoint($service, $owner);
        $this->expireRegistration($runner['incarnationId']);
        $held = function (array $snapshot, string $step) use ($runner): void {
            self::assertSame($runner['incarnationId'], $snapshot['activeIncarnationId'], $step);
            self::assertNull($this->observer->fetchOne('SELECT retired_at FROM playback_destination_incarnations WHERE id=?', [$runner['incarnationId']]), $step);
        };

        $held($this->pending($service, $owner, $other), 'snapshot');
        $held($this->registerAnotherTab($service, $owner), 'registration');
        $this->assertRefused('The runner is unavailable; silence has not been confirmed.', $service, $owner, PlaybackOperation::Control, ['expectedRevision' => $reported['revision'], 'action' => 'play'], $other);

        // A stop is accepted and addressed to the gone runner. It expires as a command that changed
        // nothing, and the session then wants the paused state the runner last reported.
        $stop = ['requestId' => new Uuid()->toString(), 'expectedRevision' => $reported['revision'], 'action' => 'stop', 'incarnationId' => $other['incarnationId']];
        $stopping = $service->execute($owner, PlaybackOperation::Control, $stop, $other['capability']);
        $held($stopping, 'stop');
        self::assertSame([$runner['incarnationId']], array_column($this->unanswered($owner), 'incarnation_id'));
        $this->expireCommands($owner);
        $settled = $this->pending($service, $owner, $other);
        $held($settled, 'stop expiry');
        self::assertSame('paused', $settled['desiredState']);
        self::assertSame('paused', $settled['observedState']);
        self::assertSame([], $this->unanswered($owner));
        self::assertSame('never_effective', $this->observer->fetchOne('SELECT outcome FROM playback_commands WHERE id=?', [$stop['requestId']]));

        // The session now reads paused, yet nothing shows the runner ever agreed to stay paused: it
        // last acknowledged a play. Every operation keeps it.
        $held($this->pending($service, $owner, $other), 'snapshot after the stop');
        $held($this->registerAnotherTab($service, $owner), 'registration after the stop');
        $this->assertRefused('The runner is unavailable; silence has not been confirmed.', $service, $owner, PlaybackOperation::Control, ['expectedRevision' => $settled['revision'], 'action' => 'play'], $other);
        $transfer = $service->execute($owner, PlaybackOperation::Transfer, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $settled['revision'], 'targetIncarnationId' => $other['incarnationId']]);
        $held($transfer, 'transfer');
        self::assertSame($runner['incarnationId'], $transfer['transfer']['sourceIncarnationId']);
    }

    public function testAPausedRunnerThatWasShownAPlayAfterItsPauseStaysHeldThroughEveryOperation(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        ['registration' => $registration, 'tab' => $tab, 'paused' => $paused] = $this->pausedTab($service, $owner);
        $other = $this->endpoint($service, $owner);

        // After its acknowledged pause the runner is shown a play and never answers it.
        $service->execute($owner, PlaybackOperation::Control, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $paused['revision'], 'action' => 'play', 'incarnationId' => $other['incarnationId']], $other['capability']);
        self::assertSame(['play'], array_column($this->pending($service, $owner, $tab)['commands'], 'type'));
        $this->expireCommands($owner);
        $unknown = $this->pending($service, $owner, $other);
        self::assertSame('unknown', $unknown['observedState']);
        self::assertSame('unknown', $this->observer->fetchOne('SELECT observed_state FROM playback_destination_incarnations WHERE id=?', [$tab['incarnationId']]));
        $this->expireRegistration($tab['incarnationId']);
        $held = function (array $snapshot, string $step) use ($tab): void {
            self::assertSame($tab['incarnationId'], $snapshot['activeIncarnationId'], $step);
            self::assertSame('unknown', $snapshot['observedState'], $step);
        };

        $held($this->pending($service, $owner, $other), 'snapshot');
        $held($this->registerAnotherTab($service, $owner), 'registration of another tab');
        $held($service->execute($owner, PlaybackOperation::Endpoint, $registration), 'registration of the same tab');
        $this->assertRefused('A runner control is awaiting acknowledgement.', $service, $owner, PlaybackOperation::Control, ['expectedRevision' => $unknown['revision'], 'action' => 'play'], $other);
        $this->assertRefused('A runner control is awaiting acknowledgement.', $service, $owner, PlaybackOperation::Transfer, ['expectedRevision' => $unknown['revision'], 'targetIncarnationId' => $other['incarnationId']]);
        // A stop replaces the stop already owed; the runner was shown a play, so nothing settles.
        $stopping = $service->execute($owner, PlaybackOperation::Control, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $unknown['revision'], 'action' => 'stop', 'incarnationId' => $other['incarnationId']], $other['capability']);
        $held($stopping, 'stop');
        $this->expireCommands($owner);
        $held($this->pending($service, $owner, $other), 'stop expiry');
        self::assertCount(1, $this->unanswered($owner));
    }

    /**
     * Hands a session over to another tab, which answers its start once it stands in the wanted state.
     *
     * @param array<string,mixed> $source
     * @param array<string,mixed> $target
     * @return array<string,mixed>
     */
    private function handOver(PlaybackCoordinator $service, Uuid $owner, array $source, array $target, int $revision, float $cursor): array
    {
        $service->execute($owner, PlaybackOperation::Transfer, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $revision, 'targetIncarnationId' => $target['incarnationId']]);
        $this->ack($service, $owner, $target, $this->pending($service, $owner, $target)['commands'][0], 'prepared');
        $this->ack($service, $owner, $source, $this->pending($service, $owner, $source)['commands'][0], 'quiesced', $cursor);
        return $this->ack($service, $owner, $target, $this->pending($service, $owner, $target)['commands'][0], 'started');
    }

    public function testATabThatTookOverAPausedSessionAndHasGoneIsReleasedByWhicheverOperationFirstSeesTheLapse(): void
    {
        $service = $this->coordinator();
        foreach (['snapshot', 'registration', 'play', 'stop then play', 'transfer'] as $releasing) {
            $owner = $this->createUser();
            ['tab' => $first, 'paused' => $paused] = $this->pausedTab($service, $owner);
            $second = $this->endpoint($service, $owner);
            // The second tab answers a start that wanted the paused state: it stands loaded and paused.
            $committed = $this->handOver($service, $owner, $first, $second, $paused['revision'], 43.0);
            self::assertSame($second['incarnationId'], $committed['activeIncarnationId'], $releasing);
            self::assertSame('paused', $committed['desiredState'], $releasing);
            self::assertSame('paused', $committed['observedState'], $releasing);
            self::assertSame('paused', $this->observer->fetchOne("SELECT payload->>'desiredState' FROM playback_commands WHERE incarnation_id=? AND type='start'", [$second['incarnationId']]), $releasing);
            // While it stays registered it keeps its place.
            self::assertSame($committed['revision'], $this->pending($service, $owner, $first)['revision'], $releasing);

            $this->expireRegistration($second['incarnationId']);
            $control = fn(string $action, int $revision): array => $service->execute($owner, PlaybackOperation::Control, [
                'requestId' => new Uuid()->toString(), 'expectedRevision' => $revision, 'action' => $action, 'incarnationId' => $first['incarnationId'],
            ], $first['capability']);
            $released = match ($releasing) {
                'snapshot' => $this->pending($service, $owner, $first),
                'registration' => $this->registerAnotherTab($service, $owner),
                'play' => $control('play', $committed['revision']),
                'stop then play' => $control('stop', $committed['revision']),
                'transfer' => $service->execute($owner, PlaybackOperation::Transfer, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $committed['revision'], 'targetIncarnationId' => $first['incarnationId']]),
            };

            self::assertNull($released['activeIncarnationId'], $releasing);
            self::assertSame(43.0, $released['position'], $releasing);
            self::assertNotNull($this->observer->fetchOne('SELECT retired_at FROM playback_destination_incarnations WHERE id=?', [$second['incarnationId']]), $releasing);
            self::assertNotContains($second['incarnationId'], array_column($this->unanswered($owner), 'incarnation_id'), $releasing);
            if ($releasing === 'transfer') {
                self::assertNull($released['transfer']['sourceIncarnationId']);
                continue;
            }
            if ($releasing !== 'play') {
                $released = $control('play', $released['revision']);
            }
            self::assertSame('retained', $released['requestResult']['outcome'], $releasing);
            $transfer = $service->execute($owner, PlaybackOperation::Transfer, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $released['revision'], 'targetIncarnationId' => $first['incarnationId']]);
            self::assertNull($transfer['transfer']['sourceIncarnationId'], $releasing);
        }
    }

    public function testATabThatAnsweredAStartThatWantedPlaybackIsNotReleasedOnThatAnswer(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $first = $this->endpoint($service, $owner);
        $playing = $this->playing($service, $owner, $first);
        $second = $this->endpoint($service, $owner);
        $committed = $this->handOver($service, $owner, $first, $second, $playing['revision'], 50.0);
        self::assertSame($second['incarnationId'], $committed['activeIncarnationId']);
        self::assertSame('playing', $committed['desiredState']);
        self::assertSame('playing', $this->observer->fetchOne("SELECT payload->>'desiredState' FROM playback_commands WHERE incarnation_id=? AND type='start'", [$second['incarnationId']]));

        // The tab reports itself paused without being asked, then goes away. A stop from another tab
        // expires unanswered, and the session reads paused with nothing later than the start.
        $reported = $service->execute($owner, PlaybackOperation::Observe, ['incarnationId' => $second['incarnationId'], 'expectedRevision' => $committed['revision'], 'observedState' => 'paused', 'position' => 60.0], $second['capability']);
        $service->execute($owner, PlaybackOperation::Control, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $reported['revision'], 'action' => 'stop', 'incarnationId' => $first['incarnationId']], $first['capability']);
        $this->expireCommands($owner);
        $settled = $this->pending($service, $owner, $first);
        self::assertSame('paused', $settled['desiredState']);
        self::assertSame('paused', $settled['observedState']);
        self::assertSame([], $this->unanswered($owner));
        // The start's own answer is the last one the runner gave, and no effective command follows it.
        self::assertSame('start', $this->observer->fetchOne("SELECT type FROM playback_commands WHERE incarnation_id=? AND outcome NOT IN ('never_effective','superseded') ORDER BY revision DESC,created_at DESC LIMIT 1", [$second['incarnationId']]));

        $this->expireRegistration($second['incarnationId']);
        foreach (['snapshot', 'registration'] as $operation) {
            $snapshot = $operation === 'snapshot' ? $this->pending($service, $owner, $first) : $this->registerAnotherTab($service, $owner);
            self::assertSame($second['incarnationId'], $snapshot['activeIncarnationId'], $operation);
        }
        self::assertNull($this->observer->fetchOne('SELECT retired_at FROM playback_destination_incarnations WHERE id=?', [$second['incarnationId']]));
        $this->assertRefused('The runner is unavailable; silence has not been confirmed.', $service, $owner, PlaybackOperation::Control, ['expectedRevision' => $settled['revision'], 'action' => 'play'], $first);
    }

    public function testATabThatTookOverAPausedSessionAndWasThenShownAPlayStaysHeld(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        ['tab' => $first, 'paused' => $paused] = $this->pausedTab($service, $owner);
        $second = $this->endpoint($service, $owner);
        $committed = $this->handOver($service, $owner, $first, $second, $paused['revision'], 43.0);

        // After its paused start the tab is shown a play and never answers it.
        $service->execute($owner, PlaybackOperation::Control, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $committed['revision'], 'action' => 'play', 'incarnationId' => $first['incarnationId']], $first['capability']);
        self::assertSame(['play'], array_column($this->pending($service, $owner, $second)['commands'], 'type'));
        $this->expireCommands($owner);
        $unknown = $this->pending($service, $owner, $first);
        self::assertSame('unknown', $unknown['observedState']);
        $this->expireRegistration($second['incarnationId']);

        foreach (['snapshot', 'registration'] as $operation) {
            $snapshot = $operation === 'snapshot' ? $this->pending($service, $owner, $first) : $this->registerAnotherTab($service, $owner);
            self::assertSame($second['incarnationId'], $snapshot['activeIncarnationId'], $operation);
            self::assertSame('unknown', $snapshot['observedState'], $operation);
        }
        $this->assertRefused('A runner control is awaiting acknowledgement.', $service, $owner, PlaybackOperation::Control, ['expectedRevision' => $unknown['revision'], 'action' => 'play'], $first);
        $this->assertRefused('A runner control is awaiting acknowledgement.', $service, $owner, PlaybackOperation::Transfer, ['expectedRevision' => $unknown['revision'], 'targetIncarnationId' => $first['incarnationId']]);
        self::assertNull($this->observer->fetchOne('SELECT retired_at FROM playback_destination_incarnations WHERE id=?', [$second['incarnationId']]));
    }

    public function testATabThatTookOverAPausedSessionAndThenAnsweredAPlayIsNotReleasedOnItsStart(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        ['tab' => $first, 'paused' => $paused] = $this->pausedTab($service, $owner);
        $second = $this->endpoint($service, $owner);
        $committed = $this->handOver($service, $owner, $first, $second, $paused['revision'], 43.0);

        // It plays on request, then only reports itself paused: the paused start is no longer its last answer.
        $service->execute($owner, PlaybackOperation::Control, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $committed['revision'], 'action' => 'play']);
        $playing = $this->ack($service, $owner, $second, $this->pending($service, $owner, $second)['commands'][0], 'playing', 44.0);
        $reported = $service->execute($owner, PlaybackOperation::Observe, ['incarnationId' => $second['incarnationId'], 'expectedRevision' => $playing['revision'], 'observedState' => 'paused', 'position' => 60.0], $second['capability']);
        $service->execute($owner, PlaybackOperation::Control, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $reported['revision'], 'action' => 'stop', 'incarnationId' => $first['incarnationId']], $first['capability']);
        $this->expireCommands($owner);
        $settled = $this->pending($service, $owner, $first);
        self::assertSame('paused', $settled['desiredState']);
        self::assertSame('paused', $settled['observedState']);

        $this->expireRegistration($second['incarnationId']);
        self::assertSame($second['incarnationId'], $this->pending($service, $owner, $first)['activeIncarnationId']);
        self::assertNull($this->observer->fetchOne('SELECT retired_at FROM playback_destination_incarnations WHERE id=?', [$second['incarnationId']]));
    }

    /** @return array<string,mixed> */
    private function registerAnotherTab(PlaybackCoordinator $service, Uuid $owner): array
    {
        $device = new Uuid();
        new DeviceDoctrineRepository($this->manager)->save(Device::create($owner, $device, 'Another tab'));
        return $service->execute($owner, PlaybackOperation::Endpoint, ['deviceId' => $device->toString(), 'endpointId' => new Uuid()->toString()]);
    }

    public function testATransferRightAfterTheLapseStartsItsTargetWithoutASource(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $runner = $this->endpoint($service, $owner);
        $stopped = $this->playThenStop($service, $owner, $runner);
        $other = $this->endpoint($service, $owner);
        // No snapshot runs between the lapse and the transfer.
        $this->expireRegistration($runner['incarnationId']);

        $transfer = $service->execute($owner, PlaybackOperation::Transfer, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $stopped['revision'], 'targetIncarnationId' => $other['incarnationId']]);
        self::assertNull($transfer['transfer']['sourceIncarnationId']);
        self::assertNull($transfer['activeIncarnationId']);
        self::assertNotNull($this->observer->fetchOne('SELECT retired_at FROM playback_destination_incarnations WHERE id=?', [$runner['incarnationId']]));
        $starting = $this->ack($service, $owner, $other, $this->pending($service, $owner, $other)['commands'][0], 'prepared');
        self::assertSame('starting', $starting['transfer']['state']);
        $committed = $this->ack($service, $owner, $other, $this->pending($service, $owner, $other)['commands'][0], 'started');
        self::assertNull($committed['transfer']);
        self::assertSame($other['incarnationId'], $committed['activeIncarnationId']);
        self::assertSame(42.5, $committed['position']);
        self::assertSame([], $this->pending($service, $owner, $runner)['commands']);
    }

    public function testAStaleRevisionIsRefusedBeforeAGoneRunnerIsReleased(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $runner = $this->endpoint($service, $owner);
        $stopped = $this->playThenStop($service, $owner, $runner);
        $other = $this->endpoint($service, $owner);
        $this->expireRegistration($runner['incarnationId']);

        $this->assertRefused('Playback revision conflict.', $service, $owner, PlaybackOperation::Control, ['expectedRevision' => $stopped['revision'] - 1, 'action' => 'play']);
        $this->assertRefused('Playback revision conflict.', $service, $owner, PlaybackOperation::Transfer, ['expectedRevision' => $stopped['revision'] - 1, 'targetIncarnationId' => $other['incarnationId']]);
        self::assertSame($runner['incarnationId'], $this->observer->fetchOne('SELECT active_incarnation_id FROM listening_sessions WHERE user_id=?', [$owner->toString()]));
        self::assertNull($this->observer->fetchOne('SELECT retired_at FROM playback_destination_incarnations WHERE id=?', [$runner['incarnationId']]));

        // The same control at the current revision releases the runner and is retained without one.
        $play = $service->execute($owner, PlaybackOperation::Control, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $stopped['revision'], 'action' => 'play']);
        self::assertNull($play['activeIncarnationId']);
        self::assertSame('retained', $play['requestResult']['outcome']);
        self::assertSame($stopped['revision'] + 2, $play['revision']);
    }

    public function testATransferFromAClosedStoppedSourceInsideItsRegistrationWindowFailsAtExpiryAndReleasesTheSource(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $runner = $this->endpoint($service, $owner);
        $stopped = $this->playThenStop($service, $owner, $runner);
        $other = $this->endpoint($service, $owner);

        $request = ['requestId' => new Uuid()->toString(), 'expectedRevision' => $stopped['revision'], 'targetIncarnationId' => $other['incarnationId']];
        $transfer = $service->execute($owner, PlaybackOperation::Transfer, $request);
        self::assertSame($runner['incarnationId'], $transfer['transfer']['sourceIncarnationId']);
        $quiescing = $this->ack($service, $owner, $other, $this->pending($service, $owner, $other)['commands'][0], 'prepared');
        self::assertSame('quiescing', $quiescing['transfer']['state']);

        // The closed source never quiesces. No start was ever issued, so nothing new can be playing.
        $this->expireTransfer($owner);
        $failed = $this->pending($service, $owner, $other);
        self::assertNull($failed['transfer']);
        self::assertSame([], $failed['commands']);
        self::assertSame($runner['incarnationId'], $failed['activeIncarnationId']);
        self::assertSame($runner['destinationId'], $failed['selectedDestinationId']);
        self::assertSame('stopped', $failed['desiredState']);
        self::assertSame('stopped', $failed['observedState']);
        self::assertSame('failed', $service->execute($owner, PlaybackOperation::Transfer, $request)['transferResult']['state']);
        self::assertSame([], $this->unanswered($owner));

        $this->expireRegistration($runner['incarnationId']);
        $released = $this->pending($service, $owner, $other);
        self::assertNull($released['activeIncarnationId']);
        self::assertSame('stopped', $released['observedState']);
        self::assertSame(42.5, $released['position']);
    }

    public function testAnExpiredTransferSettlesWhenItsTargetWasNeverShownTheStart(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $source = $this->endpoint($service, $owner);
        $target = $this->endpoint($service, $owner);
        $playing = $this->playing($service, $owner, $source);
        $service->execute($owner, PlaybackOperation::Transfer, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $playing['revision'], 'targetIncarnationId' => $target['incarnationId']]);
        $this->ack($service, $owner, $target, $this->pending($service, $owner, $target)['commands'][0], 'prepared');
        $this->ack($service, $owner, $source, $this->pending($service, $owner, $source)['commands'][0], 'quiesced', 50.0);

        // The target goes away before it asks for its start.
        $this->expireRegistration($target['incarnationId']);
        $this->expireTransfer($owner);
        $failed = $this->pending($service, $owner, $source);
        self::assertNull($failed['transfer']);
        self::assertSame($source['incarnationId'], $failed['activeIncarnationId']);
        self::assertSame($source['destinationId'], $failed['selectedDestinationId']);
        self::assertSame('paused', $failed['desiredState']);
        self::assertSame('paused', $failed['observedState']);
        self::assertSame(50.0, $failed['position']);
        self::assertSame([], $this->unanswered($owner));

        // The source is the runner again and takes controls.
        $service->execute($owner, PlaybackOperation::Control, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $failed['revision'], 'action' => 'play']);
        self::assertSame('play', $this->pending($service, $owner, $source)['commands'][0]['type']);
    }

    public function testAnExpiredTransferStaysUncertainOnceItsTargetWasShownTheStart(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $source = $this->endpoint($service, $owner);
        $target = $this->endpoint($service, $owner);
        $playing = $this->playing($service, $owner, $source);
        $service->execute($owner, PlaybackOperation::Transfer, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $playing['revision'], 'targetIncarnationId' => $target['incarnationId']]);
        $this->ack($service, $owner, $target, $this->pending($service, $owner, $target)['commands'][0], 'prepared');
        $this->ack($service, $owner, $source, $this->pending($service, $owner, $source)['commands'][0], 'quiesced', 50.0);
        self::assertSame('start', $this->pending($service, $owner, $target)['commands'][0]['type']);

        $this->expireRegistration($target['incarnationId']);
        $snapshot = null;
        for ($expiry = 0; $expiry < 3; ++$expiry) {
            $this->expireTransfer($owner);
            $snapshot = $this->pending($service, $owner, $source);
            self::assertSame('uncertain', $snapshot['transfer']['state']);
            self::assertSame($source['incarnationId'], $snapshot['activeIncarnationId']);
            self::assertSame('unknown', $snapshot['observedState']);
        }
        $outstanding = $this->unanswered($owner);
        self::assertCount(1, $outstanding);
        self::assertSame(['stop', $target['incarnationId']], [$outstanding[0]['type'], $outstanding[0]['incarnation_id']]);
        $this->assertRefused('Playback controls wait for transfer reconciliation.', $service, $owner, PlaybackOperation::Control, ['expectedRevision' => $snapshot['revision'], 'action' => 'stop'], $source);
    }

    public function testACommandIsRecordedAsShownOnlyWhenASnapshotIsBuiltForItsOwnEndpoint(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $runner = $this->endpoint($service, $owner);
        $stopped = $this->playThenStop($service, $owner, $runner);
        $other = $this->endpoint($service, $owner);
        $request = ['requestId' => new Uuid()->toString(), 'expectedRevision' => $stopped['revision'], 'action' => 'play', 'incarnationId' => $other['incarnationId']];
        $shown = fn(): mixed => $this->observer->fetchOne('SELECT disclosed_at FROM playback_commands WHERE id=?', [$request['requestId']]);

        $service->execute($owner, PlaybackOperation::Control, $request, $other['capability']);
        self::assertNull($shown());
        $service->execute($owner, PlaybackOperation::Snapshot, []);
        $this->pending($service, $owner, $other);
        $service->execute($owner, PlaybackOperation::Control, $request, $other['capability']);
        self::assertNull($shown());

        // A snapshot built inside a transaction that has not committed delivers nothing and records nothing.
        $connection = $this->manager->getConnection();
        $connection->beginTransaction();
        self::assertSame([], $this->pending($service, $owner, $runner)['commands']);
        $connection->commit();
        self::assertNull($shown());

        self::assertSame($request['requestId'], $this->pending($service, $owner, $runner)['commands'][0]['id']);
        $first = $shown();
        self::assertNotNull($first);
        $this->pending($service, $owner, $runner);
        self::assertSame($first, $shown());
    }

    public function testReRegisteringTheSameTabReleasesItsAcknowledgedPausedRunnerInTheRegistrationSnapshot(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        ['registration' => $registration, 'tab' => $tab, 'pause' => $pause, 'paused' => $paused] = $this->pausedTab($service, $owner);
        // The client reads the pause's result again under the same request identity; that adds no command.
        $replayed = $service->execute($owner, PlaybackOperation::Control, $pause, $tab['capability']);
        self::assertSame('paused', $replayed['requestResult']['outcome']);
        self::assertSame($paused['revision'], $replayed['revision']);
        self::assertSame($tab['incarnationId'], $replayed['activeIncarnationId']);

        $registered = $service->execute($owner, PlaybackOperation::Endpoint, $registration);
        $reloaded = $registered['endpoint'];
        self::assertNotSame($tab['incarnationId'], $reloaded['incarnationId']);
        self::assertNull($registered['activeIncarnationId']);
        self::assertNull($registered['activeDestinationId']);
        self::assertNull($registered['selectedDestinationId']);
        self::assertSame('paused', $registered['desiredState']);
        self::assertSame('paused', $registered['observedState']);
        self::assertSame(43.0, $registered['position']);
        self::assertSame($paused['revision'] + 1, $registered['revision']);

        // An immediate Play is accepted: it is retained without a runner and the tab then takes playback itself.
        $play = $service->execute($owner, PlaybackOperation::Control, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $registered['revision'], 'action' => 'play', 'incarnationId' => $reloaded['incarnationId']], $reloaded['capability']);
        self::assertSame('retained', $play['requestResult']['outcome']);
        $transfer = $service->execute($owner, PlaybackOperation::Transfer, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $play['revision'], 'targetIncarnationId' => $reloaded['incarnationId']]);
        self::assertNull($transfer['transfer']['sourceIncarnationId']);
        self::assertSame(43.0, $transfer['position']);
        self::assertSame([], $this->pending($service, $owner, $tab)['commands']);
    }

    public function testRegisteringAnotherTabKeepsALivePausedRunner(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        ['tab' => $tab, 'paused' => $paused] = $this->pausedTab($service, $owner);
        $device = new Uuid();
        new DeviceDoctrineRepository($this->manager)->save(Device::create($owner, $device, 'Second tab'));

        $registered = $service->execute($owner, PlaybackOperation::Endpoint, ['deviceId' => $device->toString(), 'endpointId' => new Uuid()->toString()]);

        self::assertSame($tab['incarnationId'], $registered['activeIncarnationId']);
        self::assertSame($paused['revision'], $registered['revision']);
        self::assertNull($this->observer->fetchOne('SELECT retired_at FROM playback_destination_incarnations WHERE id=?', [$tab['incarnationId']]));
    }

    public function testReRegisteringTheSameTabKeepsARunnerWithoutPauseProof(): void
    {
        $service = $this->coordinator();
        foreach (['playing', 'a later volume command', 'a pending command the runner was shown'] as $case) {
            $owner = $this->createUser();
            if ($case === 'playing') {
                $device = new Uuid();
                new DeviceDoctrineRepository($this->manager)->save(Device::create($owner, $device, 'Reloading tab'));
                $registration = ['deviceId' => $device->toString(), 'endpointId' => new Uuid()->toString()];
                $tab = $service->execute($owner, PlaybackOperation::Endpoint, $registration)['endpoint'];
                $before = $this->playing($service, $owner, $tab);
            } else {
                ['registration' => $registration, 'tab' => $tab, 'paused' => $paused] = $this->pausedTab($service, $owner, ['song', 'video', 'volume']);
                $control = ['requestId' => new Uuid()->toString(), 'expectedRevision' => $paused['revision'], 'incarnationId' => $tab['incarnationId']]
                    + ($case === 'a later volume command' ? ['action' => 'volume', 'volume' => 0.5] : ['action' => 'play']);
                $before = $service->execute($owner, PlaybackOperation::Control, $control, $tab['capability']);
                self::assertCount(1, $before['commands'], $case);
                if ($case === 'a later volume command') {
                    $before = $this->ack($service, $owner, $tab, $before['commands'][0], 'applied', volume: 0.5);
                }
            }

            $registered = $service->execute($owner, PlaybackOperation::Endpoint, $registration);

            self::assertSame($tab['incarnationId'], $registered['activeIncarnationId'], $case);
            self::assertSame($before['revision'], $registered['revision'], $case);
            self::assertNotSame($tab['incarnationId'], $registered['endpoint']['incarnationId'], $case);
        }
    }

    public function testAPausedRunnerIsReleasedAfterAPlayItWasNeverShownSettles(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        ['tab' => $tab, 'paused' => $paused] = $this->pausedTab($service, $owner);
        $other = $this->endpoint($service, $owner);

        $request = ['requestId' => new Uuid()->toString(), 'expectedRevision' => $paused['revision'], 'action' => 'play', 'incarnationId' => $other['incarnationId']];
        $service->execute($owner, PlaybackOperation::Control, $request, $other['capability']);
        $this->expireCommands($owner);
        $settled = $this->pending($service, $owner, $other);
        self::assertSame($tab['incarnationId'], $settled['activeIncarnationId']);
        self::assertSame('paused', $settled['desiredState']);
        self::assertSame('paused', $settled['observedState']);
        self::assertSame($paused['revision'] + 2, $settled['revision']);

        $this->expireRegistration($tab['incarnationId']);
        $released = $this->pending($service, $owner, $other);
        self::assertNull($released['activeIncarnationId']);
        self::assertSame('paused', $released['desiredState']);
        self::assertSame('paused', $released['observedState']);
        self::assertSame(43.0, $released['position']);
        self::assertSame($settled['revision'] + 1, $released['revision']);
    }

    public function testAPausedRunnerKeepsItsPlaceAfterAPlayItWasShownOrASupersededCommand(): void
    {
        $service = $this->coordinator();
        foreach (['shown', 'superseded'] as $case) {
            $owner = $this->createUser();
            ['tab' => $tab, 'paused' => $paused] = $this->pausedTab($service, $owner);
            $other = $this->endpoint($service, $owner);
            $request = ['requestId' => new Uuid()->toString(), 'expectedRevision' => $paused['revision'], 'action' => 'play', 'incarnationId' => $other['incarnationId']];
            $service->execute($owner, PlaybackOperation::Control, $request, $other['capability']);
            if ($case === 'shown') {
                $this->pending($service, $owner, $tab);
            }
            $this->expireCommands($owner);
            $this->pending($service, $owner, $other);
            if ($case === 'superseded') {
                // The same history, except that the command was shown to its runner and closed as superseded.
                $this->manager->getConnection()->executeStatement("UPDATE playback_commands SET outcome='superseded',settled_revision=NULL,disclosed_at=CURRENT_TIMESTAMP WHERE id=:id", ['id' => $request['requestId']]);
            }
            $this->expireRegistration($tab['incarnationId']);

            $snapshot = $this->pending($service, $owner, $other);

            self::assertSame($tab['incarnationId'], $snapshot['activeIncarnationId'], $case);
            self::assertNull($this->observer->fetchOne('SELECT retired_at FROM playback_destination_incarnations WHERE id=?', [$tab['incarnationId']]), $case);
        }
    }

    public function testRejectedTransferReasonSurvivesLaterTransfersAndExactReplay(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $source = $this->endpoint($service, $owner);
        $active = $this->activate($service, $owner, $source);
        $target = $this->endpoint($service, $owner);
        $request = ['requestId' => new Uuid()->toString(), 'expectedRevision' => $active['revision'], 'targetIncarnationId' => $target['incarnationId']];
        $pending = $service->execute($owner, PlaybackOperation::Transfer, $request);
        $command = $this->pending($service, $owner, $target)['commands'][0];
        $payload = ['commandId' => $command['id'], 'attemptId' => $command['attemptId'], 'revision' => $command['revision'],
            'incarnationId' => $target['incarnationId'], 'epoch' => $command['epoch'], 'outcome' => 'rejected', 'rejectionReason' => 'video_codec_unsupported'];
        $rejected = $service->execute($owner, PlaybackOperation::Acknowledge, $payload, $target['capability']);
        self::assertSame($source['incarnationId'], $rejected['activeIncarnationId']);
        self::assertNull($rejected['transfer']);
        self::assertSame('video_codec_unsupported', $this->observer->fetchOne('SELECT rejection_reason FROM playback_commands WHERE id=?', [$command['id']]));
        $service->execute($owner, PlaybackOperation::Acknowledge, $payload, $target['capability']);
        $later = $service->execute($owner, PlaybackOperation::Transfer, ['requestId' => new Uuid()->toString(),
            'expectedRevision' => $rejected['revision'], 'targetIncarnationId' => $target['incarnationId']]);
        $replay = $service->execute($owner, PlaybackOperation::Transfer, $request);
        self::assertSame(['requestId' => $request['requestId'], 'attemptId' => $pending['transfer']['attemptId'],
            'state' => 'failed', 'rejectionReason' => 'video_codec_unsupported'], $replay['transferResult']);
        self::assertSame($later['transfer']['attemptId'], $replay['transfer']['attemptId']);
        self::assertNotSame($later['transfer']['attemptId'], $replay['transferResult']['attemptId']);
        $payload['rejectionReason'] = 'unknown';
        $this->expectException(ConflictException::class);
        $service->execute($owner, PlaybackOperation::Acknowledge, $payload, $target['capability']);
    }

    public function testRejectedControlReasonIsReturnedOnRequestReplay(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $source = $this->endpoint($service, $owner);
        $active = $this->activate($service, $owner, $source);
        $request = ['requestId' => new Uuid()->toString(), 'expectedRevision' => $active['revision'], 'action' => 'play'];
        $service->execute($owner, PlaybackOperation::Control, $request);
        $command = $this->pending($service, $owner, $source)['commands'][0];
        $service->execute($owner, PlaybackOperation::Acknowledge, ['commandId' => $command['id'], 'attemptId' => $command['attemptId'],
            'revision' => $command['revision'], 'incarnationId' => $source['incarnationId'], 'epoch' => $command['epoch'],
            'outcome' => 'rejected', 'rejectionReason' => 'browser_rejected_source'], $source['capability']);
        $replay = $service->execute($owner, PlaybackOperation::Control, $request);
        self::assertSame('rejected', $replay['requestResult']['outcome']);
        self::assertSame('browser_rejected_source', $replay['requestResult']['rejectionReason']);
    }

    public function testDatabaseRejectsAReasonOnANonRejectedOutcome(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $target = $this->endpoint($service, $owner);
        $service->execute($owner, PlaybackOperation::Transfer, ['requestId' => new Uuid()->toString(), 'expectedRevision' => 0,
            'targetIncarnationId' => $target['incarnationId']]);
        $command = $this->pending($service, $owner, $target)['commands'][0];
        $this->expectException(\Doctrine\DBAL\Exception\DriverException::class);
        $this->observer->executeStatement("UPDATE playback_commands SET rejection_reason='unknown' WHERE id=?", [$command['id']]);
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
