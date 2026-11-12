<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Session;

use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Session\Application\Port\PlaybackOperation;
use App\Session\Application\Service\PlaybackCoordinator;
use App\Session\Domain\Model\Device\Device;
use App\Session\Infrastructure\Doctrine\Repository\DeviceDoctrineRepository;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Support\FinalSchemaBootstrap;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;

require_once __DIR__ . '/playback-concurrent-transfer.php';

/**
 * A disposable schema, a coordinator on it and the steps playback scenarios are told in: tabs that
 * register from a page, runners brought to a state, transfers advanced to a state, and a second
 * connection that sees only what was committed.
 */
trait PlaybackPersistenceScenarios
{
    private string $schemaName;
    private EntityManager $manager;
    private Connection $observer;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Requires disposable PostgreSQL.');
        }
        $this->schemaName = 'playback_scenarios_' . bin2hex(random_bytes(8));
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
     * What one tab registers with: the device and the endpoint stand for the tab, the page identity
     * for the document loaded in it.
     *
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function tabOnAPage(Uuid $owner, array $extra = []): array
    {
        $device = new Uuid();
        new DeviceDoctrineRepository($this->manager)->save(Device::create($owner, $device, 'Playback browser'));
        return ['deviceId' => $device->toString(), 'endpointId' => new Uuid()->toString(), 'pageId' => new Uuid()->toString()] + $extra;
    }

    /**
     * A tab on a page of its own, registered.
     *
     * @param array<string,mixed> $extra
     * @return array<string,mixed> The endpoint the registration answered with.
     */
    private function endpoint(PlaybackCoordinator $service, Uuid $owner, array $extra = []): array
    {
        return $service->execute($owner, PlaybackOperation::Endpoint, $this->tabOnAPage($owner, $extra))['endpoint'];
    }

    /**
     * The tab registers from a page it has not registered from before, as after a reload.
     *
     * @param array<string,mixed> $registration
     * @return array<string,mixed>
     */
    private function reload(PlaybackCoordinator $service, Uuid $owner, array $registration): array
    {
        return $service->execute($owner, PlaybackOperation::Endpoint, ['pageId' => new Uuid()->toString()] + $registration);
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
    private function ack(PlaybackCoordinator $service, Uuid $owner, array $endpoint, array $command, string $outcome, ?float $position = null): array
    {
        return $service->execute($owner, PlaybackOperation::Acknowledge, ['commandId' => $command['id'], 'attemptId' => $command['attemptId'],
            'revision' => (int) $command['revision'], 'incarnationId' => $endpoint['incarnationId'], 'epoch' => (int) $command['epoch'], 'outcome' => $outcome]
            + ($position === null ? [] : ['position' => $position]), $endpoint['capability']);
    }

    /**
     * The endpoint asks for its commands, which shows them to it, and answers the first one.
     *
     * @param array<string,mixed> $endpoint
     * @return array<string,mixed>
     */
    private function answer(PlaybackCoordinator $service, Uuid $owner, array $endpoint, string $outcome, ?float $position = null): array
    {
        return $this->ack($service, $owner, $endpoint, $this->pending($service, $owner, $endpoint)['commands'][0], $outcome, $position);
    }

    /**
     * One control sent by a tab, which is shown the commands addressed to itself in the answer.
     *
     * @param array<string,mixed> $from
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function controlFrom(PlaybackCoordinator $service, Uuid $owner, array $from, int $revision, string $action, array $payload = []): array
    {
        return $service->execute($owner, PlaybackOperation::Control, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $revision,
            'action' => $action, 'incarnationId' => $from['incarnationId']] + $payload, $from['capability']);
    }

    /**
     * The endpoint takes a session nobody plays and confirms that it plays, at 42.5 s.
     *
     * @param array<string,mixed> $runner
     * @return array<string,mixed>
     */
    private function playing(PlaybackCoordinator $service, Uuid $owner, array $runner): array
    {
        $snapshot = $service->execute($owner, PlaybackOperation::Snapshot, []);
        $service->execute($owner, PlaybackOperation::Transfer, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $snapshot['revision'], 'targetIncarnationId' => $runner['incarnationId']]);
        $this->answer($service, $owner, $runner, 'prepared');
        $started = $this->answer($service, $owner, $runner, 'started');
        $this->controlFrom($service, $owner, $runner, $started['revision'], 'play');
        return $this->answer($service, $owner, $runner, 'playing', 42.5);
    }

    /**
     * A runner that played and then confirmed a pause at 43 s.
     *
     * @param array<string,mixed> $runner
     * @return array<string,mixed>
     */
    private function paused(PlaybackCoordinator $service, Uuid $owner, array $runner): array
    {
        $playing = $this->playing($service, $owner, $runner);
        $this->controlFrom($service, $owner, $runner, $playing['revision'], 'pause');
        return $this->answer($service, $owner, $runner, 'paused', 43.0);
    }

    /**
     * A runner that played and then confirmed a stop; the session keeps the cursor of 42.5 s.
     *
     * @param array<string,mixed> $runner
     * @return array<string,mixed>
     */
    private function stopped(PlaybackCoordinator $service, Uuid $owner, array $runner): array
    {
        $playing = $this->playing($service, $owner, $runner);
        $this->controlFrom($service, $owner, $runner, $playing['revision'], 'stop');
        return $this->answer($service, $owner, $runner, 'stopped', 0.0);
    }

    /**
     * A transfer to a tab on a page, advanced to the given state. With a source, a tab that plays
     * hands over and quiesces at 50 s; without one the target starts playback in a session nobody plays.
     *
     * @return array{source:?array<string,mixed>,sourceRegistration:?array<string,mixed>,target:array<string,mixed>,targetRegistration:array<string,mixed>,request:array<string,mixed>,snapshot:array<string,mixed>}
     */
    private function transferBetweenPages(PlaybackCoordinator $service, Uuid $owner, bool $withSource, string $state): array
    {
        $sourceRegistration = $withSource ? $this->tabOnAPage($owner) : null;
        $source = $sourceRegistration === null ? null : $service->execute($owner, PlaybackOperation::Endpoint, $sourceRegistration)['endpoint'];
        $targetRegistration = $this->tabOnAPage($owner);
        $target = $service->execute($owner, PlaybackOperation::Endpoint, $targetRegistration)['endpoint'];
        $revision = $source === null ? $service->execute($owner, PlaybackOperation::Snapshot, [])['revision'] : $this->playing($service, $owner, $source)['revision'];
        $request = ['requestId' => new Uuid()->toString(), 'expectedRevision' => $revision, 'targetIncarnationId' => $target['incarnationId']];
        $snapshot = $service->execute($owner, PlaybackOperation::Transfer, $request);
        if ($state !== 'preparing') {
            $snapshot = $this->answer($service, $owner, $target, 'prepared');
            if ($source !== null && $state !== 'quiescing') {
                $snapshot = $this->answer($service, $owner, $source, 'quiesced', 50.0);
            }
        }
        if (in_array($state, ['starting', 'uncertain'], true)) {
            // The target is shown its start and does not answer it.
            self::assertSame(['start'], array_column($this->pending($service, $owner, $target)['commands'], 'type'));
        }
        if ($state === 'uncertain') {
            $this->expireTransfer($owner);
            $snapshot = $service->execute($owner, PlaybackOperation::Snapshot, []);
        }
        self::assertSame($state, $snapshot['transfer']['state']);
        return ['source' => $source, 'sourceRegistration' => $sourceRegistration, 'target' => $target, 'targetRegistration' => $targetRegistration,
            'request' => $request, 'snapshot' => $snapshot];
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

    /** @return list<array{string,string}> The type and the addressee of each command nobody has answered. */
    private function unanswered(Uuid $owner): array
    {
        return array_map(
            static fn(array $command): array => [$command['type'], $command['incarnation_id']],
            $this->observer->fetchAllAssociative('SELECT type,incarnation_id FROM playback_commands WHERE user_id=? AND acknowledged_at IS NULL ORDER BY created_at,id', [$owner->toString()]),
        );
    }

    /** @return array{observed_state:string,position:float,retired:bool,available:bool} */
    private function incarnation(string $incarnationId): array
    {
        $row = $this->observer->fetchAssociative(
            'SELECT observed_state,position,retired_at IS NOT NULL AS retired,(retired_at IS NULL AND expires_at>CURRENT_TIMESTAMP) AS available FROM playback_destination_incarnations WHERE id=?',
            [$incarnationId],
        );
        self::assertIsArray($row);
        return $row;
    }

    /**
     * Everything an operation could change, as another connection sees it once the operation has
     * committed. Instants a later statement of the same request may only renew are left out.
     *
     * @return array<string,mixed>
     */
    private function committed(Uuid $owner): array
    {
        $account = [$owner->toString()];
        return [
            'session' => $this->observer->fetchAssociative('SELECT revision,active_incarnation_id,selected_destination_id,desired_state,observed_state,position FROM listening_sessions WHERE user_id=?', $account),
            'commands' => $this->observer->fetchAllAssociative('SELECT id,incarnation_id,epoch,type,outcome,acknowledged_at FROM playback_commands WHERE user_id=? ORDER BY created_at,id', $account),
            'transfers' => $this->observer->fetchAllAssociative('SELECT id,source_incarnation_id,target_incarnation_id,state,revision FROM playback_transfers WHERE user_id=? ORDER BY created_at,id', $account),
            'incarnations' => $this->observer->fetchAllAssociative('SELECT id,retired_at,observed_state,position,expires_at FROM playback_destination_incarnations WHERE user_id=? ORDER BY id', $account),
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed>|null $endpoint The endpoint whose capability travels with the request, if any.
     */
    private function assertRefused(string $reason, PlaybackCoordinator $service, Uuid $owner, PlaybackOperation $operation, array $input, ?array $endpoint = null): void
    {
        try {
            $service->execute($owner, $operation, $input, $endpoint['capability'] ?? null);
            self::fail($operation->value . ' was accepted.');
        } catch (ConflictException $error) {
            self::assertSame($reason, $error->getMessage());
        }
    }
}
