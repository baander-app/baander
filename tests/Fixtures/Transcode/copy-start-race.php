<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Transcode;

use App\Shared\Application\DTO\DeploymentProfile;
use App\Shared\Application\Port\SleeperInterface;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Doctrine\Platform\BaanderDriverMiddleware;
use App\Shared\Infrastructure\Doctrine\Type\CustomTypesRegistrar;
use App\Transcode\Application\DTO\CopyPresentationInput;
use App\Transcode\Application\DTO\MediaWorkerIdentity;
use App\Transcode\Application\Exception\TranscodeSessionLimitException;
use App\Transcode\Application\Service\CopyPresentationService;
use App\Transcode\Application\Service\MediaExecutionPolicy;
use App\Transcode\Application\Service\TranscodeSessionShare;
use App\Transcode\Application\Settings\TranscodeSettingDefinitions;
use App\Transcode\Infrastructure\Doctrine\Repository\TranscodeJobRepository;
use App\Transcode\Infrastructure\Doctrine\Repository\TranscodeSessionRepository;
use App\Transcode\Infrastructure\Doctrine\TranscodeRuntimeState;
use App\Transcode\Infrastructure\Doctrine\TranscodeSessionShareStore;
use App\Transcode\Infrastructure\Storage\SegmentFileResolver;
use App\Transcode\Infrastructure\Storage\TranscodeFileStorage;
use App\Transcode\Infrastructure\Transcode\TranscodeJobService;
use App\Transcode\Infrastructure\Transcode\TranscodeSessionService;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\EventDispatcher\EventDispatcher;

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

/**
 * The production copy start on a connection and ORM identity map of its own, as one delivery
 * negotiation makes it, on the default transcode deployment with its eight video slots.
 */
final class CopyStartRace
{
    private const int VIDEO_SLOTS = 8;

    public static function connect(?string $schema = null, ?string $application = null): Connection
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!is_string($url) || $url === '') {
            throw new \LogicException('OUTBOX_TEST_DATABASE_URL must name a disposable PostgreSQL database.');
        }
        CustomTypesRegistrar::register();
        $configuration = new Configuration();
        $configuration->setMiddlewares([new BaanderDriverMiddleware()]);
        $connection = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url), $configuration);
        if ($schema !== null) {
            $connection->executeStatement('SET search_path TO ' . $connection->quoteIdentifier($schema) . ', public');
        }
        $connection->executeStatement("SET lock_timeout = '10s'");
        if ($application !== null) {
            $connection->executeQuery("SELECT set_config('application_name', ?, false)", [$application])->free();
        }

        return $connection;
    }

    /** @param TransactionPortInterface|null $transaction the start's transaction, a plain one by default */
    public static function service(Connection $connection, ?TransactionPortInterface $transaction = null): CopyPresentationService
    {
        $paths = glob(dirname(__DIR__, 3) . '/src/*/Infrastructure/Doctrine/Entity');
        if ($paths === false) {
            throw new \LogicException('Doctrine fixture metadata paths could not be read.');
        }
        $configuration = ORMSetup::createAttributeMetadataConfig($paths, isDevMode: true);
        $configuration->setNamingStrategy(new UnderscoreNamingStrategy());
        $configuration->enableNativeLazyObjects(true);
        $manager = new EntityManager($connection, $configuration);
        $plain = self::transaction($connection);
        $runtime = new TranscodeRuntimeState($connection, $plain);
        $storage = new TranscodeFileStorage(new SegmentFileResolver('/fixture/copy-start-race'));
        $profile = new DeploymentProfile(videoEncoderSlots: self::VIDEO_SLOTS);
        $share = new TranscodeSessionShare(new TranscodeSessionShareStore($connection), $runtime, $plain, self::settings(), $profile, new NativeClock());

        return new CopyPresentationService(
            new MediaExecutionPolicy($profile),
            new TranscodeJobService(new TranscodeJobRepository($manager), $storage, new EventDispatcher(), $runtime, $plain, new NullLogger()),
            new TranscodeSessionService(new TranscodeSessionRepository($manager)),
            $storage,
            $runtime,
            $transaction ?? $plain,
            self::worker($connection, $runtime),
            $share,
        );
    }

    /** A copy of a film nobody plays yet, for a new client. */
    public static function input(Uuid $user): CopyPresentationInput
    {
        return new CopyPresentationInput($user, Uuid::generate(), 'source-hash', 0, 1, 'mp4', 1280, 720, Uuid::generate()->toString());
    }

    private static function transaction(Connection $connection): TransactionPortInterface
    {
        return new class ($connection) implements TransactionPortInterface {
            public function __construct(private readonly Connection $connection)
            {
            }

            public function run(callable $operation): mixed
            {
                return $this->connection->transactional(static fn (): mixed => $operation());
            }
        };
    }

    /** The server settings at their defaults. */
    private static function settings(): SystemSettingsPortInterface
    {
        return new class implements SystemSettingsPortInterface {
            public function get(string $key): int
            {
                return $key === TranscodeSettingDefinitions::SESSIONS_PER_USER ? 6 : throw new \LogicException($key);
            }
        };
    }

    /** Instead of waiting, a worker accepts the oldest pending execution while a video slot is free. */
    private static function worker(Connection $connection, TranscodeRuntimeState $runtime): SleeperInterface
    {
        return new class ($connection, $runtime, self::VIDEO_SLOTS) implements SleeperInterface {
            public function __construct(private readonly Connection $connection, private readonly TranscodeRuntimeState $runtime, private readonly int $slots)
            {
            }

            public function sleep(float $seconds): void
            {
                if ((int) $this->connection->fetchOne("SELECT count(*) FROM transcode_runtime_state WHERE outcome IN ('accepted', 'ready')") >= $this->slots) {
                    return;
                }
                $job = $this->connection->fetchOne("SELECT job_id FROM transcode_runtime_state WHERE outcome = 'pending' ORDER BY updated_at, job_id LIMIT 1");
                $claim = is_string($job) ? $this->runtime->claim(Uuid::fromString($job), new MediaWorkerIdentity('baander.app:workers', str_repeat('c', 32), 'media', 1)) : null;
                if ($claim !== null) {
                    $this->runtime->observe($claim, 'accepted');
                }
            }
        };
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    [, $schema, $application, $user] = array_map(strval(...), $_SERVER['argv']);
    $service = CopyStartRace::service(CopyStartRace::connect($schema, $application));
    fwrite(STDOUT, "ready\n");
    fflush(STDOUT);
    if (trim((string) fgets(STDIN)) !== 'go') {
        throw new \LogicException('The parent must release the child explicitly.');
    }
    try {
        $service->start(CopyStartRace::input(Uuid::fromString($user)));
        fwrite(STDOUT, 'admitted');
    } catch (TranscodeSessionLimitException) {
        fwrite(STDOUT, 'refused');
    }
}
