<?php

declare(strict_types=1);

/**
 * What a relayed playback snapshot and a control cost.
 *
 * `PlaybackSnapshotCostTest` uses the fixture below to count statements. Run as a program in
 * the migrated disposable functional container, it prints timings as JSON:
 *
 *     php tests/Fixtures/Session/playback-snapshot-cost.php
 *
 * Each timed scenario runs 200 unmeasured iterations and then 2,000 measured ones, and reports
 * the median and the 95th percentile in milliseconds. Times are evidence for a comparison on
 * one host, not portable pass or fail thresholds.
 *
 * Every scenario is timed twice. Each coordinator operation locks the session row, so its commit
 * writes to the log and waits for the flush, and on most disks that wait is longer than all of the
 * operation's statements together. The first pass keeps the wait, which is what a deployment
 * pays. The second sets `synchronous_commit` to off for the session, which leaves the statements
 * and the PHP around them, the part a change to the store can move.
 */

namespace App\Tests\Fixtures\Session;

use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Session\Application\Port\PlaybackOperation;
use App\Session\Application\Service\PlaybackCoordinator;
use App\Session\Domain\Model\Device\Device;
use App\Session\Infrastructure\Doctrine\Repository\DeviceDoctrineRepository;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Doctrine\Platform\BaanderDriverMiddleware;
use App\Tests\Support\FinalSchemaBootstrap;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Psr\Log\AbstractLogger;

require_once __DIR__ . '/playback-concurrent-transfer.php';

/** Every statement a connection sends to the server, in order. Transaction boundaries carry no SQL and are not listed; savepoints do and are. */
final class PlaybackStatementLog extends AbstractLogger
{
    /** @var list<string> */
    public array $statements = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (isset($context['sql']) && is_string($context['sql'])) {
            $this->statements[] = $context['sql'];
        }
    }

    /**
     * @param callable():mixed $operation
     * @return list<string> The statements the operation sent.
     */
    public function during(callable $operation): array
    {
        $this->statements = [];
        $operation();
        return $this->statements;
    }
}

final class PlaybackCostFixture
{
    /** A connection like the application's, with every statement it sends recorded in the log. */
    public static function countingConnection(string $schema, PlaybackStatementLog $log): Connection
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!is_string($url) || $url === '') {
            throw new \LogicException('Requires disposable PostgreSQL.');
        }
        $params = new DsnParser(['postgresql' => 'pdo_pgsql'])->parse($url);
        $params['driverOptions'] = [\Pdo\Pgsql::ATTR_DISABLE_PREPARES => true] + ($params['driverOptions'] ?? []);
        $configuration = new Configuration()->setMiddlewares([new BaanderDriverMiddleware(), new Middleware($log)]);
        $connection = DriverManager::getConnection($params, $configuration);
        $connection->executeStatement('SET search_path TO ' . $connection->quoteIdentifier($schema) . ', public');
        return $connection;
    }

    public static function owner(EntityManager $manager): Uuid
    {
        $owner = new UserEntity(new PublicId(), 'Playback cost owner', new Uuid()->toString() . '@baander.app', 'unused-password', '');
        $manager->persist($owner);
        $manager->flush();
        return $owner->getId();
    }

    /** @return array<string,mixed> A registered endpoint with its capability. */
    public static function endpoint(PlaybackCoordinator $service, EntityManager $manager, Uuid $owner): array
    {
        $device = new Uuid();
        new DeviceDoctrineRepository($manager)->save(Device::create($owner, $device, 'Playback cost browser'));
        return $service->execute($owner, PlaybackOperation::Endpoint, ['deviceId' => $device->toString(), 'endpointId' => new Uuid()->toString()])['endpoint'];
    }

    /**
     * The snapshot the relay builds for a socket bound to an endpoint.
     *
     * @param array<string,mixed> $endpoint
     * @return array<string,mixed>
     */
    public static function boundSnapshot(PlaybackCoordinator $service, Uuid $owner, array $endpoint): array
    {
        return $service->execute($owner, PlaybackOperation::Snapshot, ['incarnationId' => $endpoint['incarnationId']], $endpoint['capability']);
    }

    /** @return array<string,mixed> The snapshot the relay builds for a socket that has registered no endpoint. */
    public static function unboundSnapshot(PlaybackCoordinator $service, Uuid $owner): array
    {
        return $service->execute($owner, PlaybackOperation::Snapshot, []);
    }

    /**
     * A control sent by an endpoint's own tab. The answer carries the command it issued.
     *
     * @param array<string,mixed> $endpoint
     * @return array<string,mixed>
     */
    public static function control(PlaybackCoordinator $service, Uuid $owner, array $endpoint, string $action, int $revision): array
    {
        return $service->execute($owner, PlaybackOperation::Control, [
            'requestId' => new Uuid()->toString(), 'expectedRevision' => $revision, 'action' => $action, 'incarnationId' => $endpoint['incarnationId'],
        ], $endpoint['capability']);
    }

    /**
     * @param array<string,mixed> $endpoint
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public static function acknowledge(PlaybackCoordinator $service, Uuid $owner, array $endpoint, array $command, string $outcome, ?float $position = null): array
    {
        return $service->execute($owner, PlaybackOperation::Acknowledge, [
            'commandId' => $command['id'], 'attemptId' => $command['attemptId'], 'revision' => (int) $command['revision'],
            'incarnationId' => $endpoint['incarnationId'], 'epoch' => (int) $command['epoch'], 'outcome' => $outcome,
        ] + ($position === null ? [] : ['position' => $position]), $endpoint['capability']);
    }

    /**
     * An account whose one endpoint is the runner and plays.
     *
     * @return array{owner:Uuid,runner:array<string,mixed>,snapshot:array<string,mixed>}
     */
    public static function playingRunner(PlaybackCoordinator $service, EntityManager $manager): array
    {
        $owner = self::owner($manager);
        $runner = self::endpoint($service, $manager, $owner);
        $snapshot = self::unboundSnapshot($service, $owner);
        $service->execute($owner, PlaybackOperation::Transfer, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $snapshot['revision'], 'targetIncarnationId' => $runner['incarnationId']]);
        self::acknowledge($service, $owner, $runner, self::boundSnapshot($service, $owner, $runner)['commands'][0], 'prepared');
        $started = self::acknowledge($service, $owner, $runner, self::boundSnapshot($service, $owner, $runner)['commands'][0], 'started');
        $playing = self::control($service, $owner, $runner, 'play', $started['revision']);
        return ['owner' => $owner, 'runner' => $runner, 'snapshot' => self::acknowledge($service, $owner, $runner, $playing['commands'][0], 'playing', 42.5)];
    }

    /**
     * An account whose runner acknowledged a pause and rests, with nothing pending.
     *
     * @return array{owner:Uuid,runner:array<string,mixed>,snapshot:array<string,mixed>}
     */
    public static function pausedRunner(PlaybackCoordinator $service, EntityManager $manager): array
    {
        ['owner' => $owner, 'runner' => $runner, 'snapshot' => $playing] = self::playingRunner($service, $manager);
        $pausing = self::control($service, $owner, $runner, 'pause', $playing['revision']);
        return ['owner' => $owner, 'runner' => $runner, 'snapshot' => self::acknowledge($service, $owner, $runner, $pausing['commands'][0], 'paused', 43.0)];
    }

    /**
     * @param list<float> $milliseconds
     * @return array{iterations:int,median_ms:float,p95_ms:float}
     */
    public static function summary(array $milliseconds): array
    {
        sort($milliseconds);
        $count = count($milliseconds);
        $at = static fn(float $quantile): float => $milliseconds[(int) min($count - 1, floor($quantile * $count))];
        return ['iterations' => $count, 'median_ms' => round($at(0.5), 4), 'p95_ms' => round($at(0.95), 4)];
    }

    /**
     * @param callable(int):mixed $operation Receives the iteration number.
     * @param callable(mixed):void|null $afterwards Runs unmeasured after each iteration, with the operation's result.
     * @return array{iterations:int,median_ms:float,p95_ms:float}
     */
    public static function time(callable $operation, int $warmUp, int $iterations, ?callable $afterwards = null): array
    {
        $milliseconds = [];
        for ($iteration = -$warmUp; $iteration < $iterations; ++$iteration) {
            $started = hrtime(true);
            $result = $operation($iteration + $warmUp);
            $elapsed = (hrtime(true) - $started) / 1_000_000;
            if ($iteration >= 0) {
                $milliseconds[] = $elapsed;
            }
            if ($afterwards !== null) {
                $afterwards($result);
            }
        }
        return self::summary($milliseconds);
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $schema = 'playback_cost_' . bin2hex(random_bytes(8));
    $connection = PlaybackPersistenceFixture::connect('public');
    $connection->executeStatement('CREATE SCHEMA ' . $schema);
    try {
        $connection->executeStatement('SET search_path TO ' . $schema . ', public');
        FinalSchemaBootstrap::bootstrap($connection);
        $manager = PlaybackPersistenceFixture::manager($connection);
        $service = PlaybackPersistenceFixture::coordinator($manager);
        [$warmUp, $iterations, $accounts] = [200, 2000, 50];

        $playing = PlaybackCostFixture::playingRunner($service, $manager);
        $paused = PlaybackCostFixture::pausedRunner($service, $manager);
        $many = array_map(static fn(): array => PlaybackCostFixture::pausedRunner($service, $manager), range(1, $accounts));
        // A browser renews its registration every ten seconds and nothing here does, so the runners
        // are kept registered for the whole run: a lapsed resting runner would be released by the
        // first snapshot that saw the lapse, and every later iteration would measure another path.
        $connection->executeStatement("UPDATE playback_destination_incarnations SET expires_at=CURRENT_TIMESTAMP+INTERVAL '1 day'");
        foreach (['listening_sessions', 'playback_destinations', 'playback_destination_incarnations', 'playback_transfers', 'playback_commands'] as $table) {
            $connection->executeStatement('ANALYZE ' . $table);
        }
        $stillRuns = static function (array $account) use ($service): void {
            $snapshot = PlaybackCostFixture::unboundSnapshot($service, $account['owner']);
            if ($snapshot['activeIncarnationId'] !== $account['runner']['incarnationId'] || $snapshot['revision'] !== $account['snapshot']['revision']) {
                throw new \RuntimeException('A measured session changed during its measurement.');
            }
        };
        $measure = static function () use ($service, $manager, $playing, $paused, $many, $accounts, $warmUp, $iterations, $stillRuns): array {
            $results = [
                'unbound socket, runner playing, one account' => PlaybackCostFixture::time(
                    static fn(): array => PlaybackCostFixture::unboundSnapshot($service, $playing['owner']),
                    $warmUp,
                    $iterations,
                ),
                'bound socket, runner resting paused, one account' => PlaybackCostFixture::time(
                    static fn(): array => PlaybackCostFixture::boundSnapshot($service, $paused['owner'], $paused['runner']),
                    $warmUp,
                    $iterations,
                ),
                'bound socket, runner resting paused, ' . $accounts . ' accounts served in turn by one entity manager' => PlaybackCostFixture::time(
                    static fn(int $iteration): array => PlaybackCostFixture::boundSnapshot($service, $many[$iteration % $accounts]['owner'], $many[$iteration % $accounts]['runner']),
                    $warmUp,
                    $iterations,
                ),
            ];
            array_map($stillRuns, [$playing, $paused, ...$many]);
            // Each control issues one pause to a resting runner. The runner answers it, unmeasured,
            // before the next one, so every measured control finds the same confirmed paused
            // session, and the answer renews the runner's registration. Each pass starts on an
            // account of its own, because every control and answer adds to the session's commands.
            $controlled = PlaybackCostFixture::pausedRunner($service, $manager);
            $revision = $controlled['snapshot']['revision'];
            $results['control that issues one command to the runner'] = PlaybackCostFixture::time(
                static function () use ($service, $controlled, &$revision): array {
                    return PlaybackCostFixture::control($service, $controlled['owner'], $controlled['runner'], 'pause', $revision);
                },
                $warmUp,
                $iterations,
                static function (array $issued) use ($service, $controlled, &$revision): void {
                    if (array_column($issued['commands'], 'type') !== ['pause']) {
                        throw new \RuntimeException('A measured control issued no command to the runner.');
                    }
                    $revision = PlaybackCostFixture::acknowledge($service, $controlled['owner'], $controlled['runner'], $issued['commands'][0], 'paused', 43.0)['revision'];
                },
            );
            return $results;
        };

        $timings = [];
        foreach (['commits wait for the flush' => 'RESET synchronous_commit', 'commits do not wait for the flush' => 'SET synchronous_commit TO off'] as $pass => $setting) {
            $connection->executeStatement($setting);
            $timings[$pass] = $measure();
        }
        echo json_encode([
            'server' => $connection->fetchOne('SHOW server_version'),
            'php' => PHP_VERSION,
            'warm_up' => $warmUp,
            'timings' => $timings,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
    } finally {
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        $connection->executeStatement('DROP SCHEMA ' . $schema . ' CASCADE');
        $connection->close();
    }
}
