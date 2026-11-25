<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\QoL\Application\Port\AllowedQualityTiersPortInterface;
use App\Shared\Application\DTO\DeploymentProfile;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\HandlerFailure;
use App\Shared\Application\Port\SleeperInterface;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Transcode\CopyStartRace;
use App\Transcode\Application\Command\CreateTranscodeSessionCommand;
use App\Transcode\Application\CommandHandler\CreateTranscodeSessionHandler;
use App\Transcode\Application\DTO\CopyPresentation;
use App\Transcode\Application\DTO\CopyPresentationInput;
use App\Transcode\Application\DTO\DesiredTranscodeState;
use App\Transcode\Application\DTO\MediaWorkerIdentity;
use App\Transcode\Application\DTO\PlaybackEndReason;
use App\Transcode\Application\DTO\TranscodeSessionDto;
use App\Transcode\Application\Exception\TranscodeStartupUnavailableException;
use App\Transcode\Application\Port\EncodePresentationPort;
use App\Transcode\Application\Port\TranscodePublicViewInterface;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Shared\Infrastructure\Doctrine\Platform\BaanderDriverMiddleware;
use App\Shared\Infrastructure\Doctrine\Type\CustomTypesRegistrar;
use App\Transcode\Application\Service\CopyPresentationService;
use App\Transcode\Application\Service\MediaExecutionPolicy;
use App\Transcode\Application\Service\TranscodeSessionShare;
use App\Transcode\Application\Settings\TranscodeSettingDefinitions;
use App\Transcode\Domain\Model\TranscodeSession;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\PresentationSelection;
use App\Transcode\Infrastructure\Auth\AuthorizedPlayback;
use App\Transcode\Infrastructure\Doctrine\Repository\TranscodeJobRepository;
use App\Transcode\Infrastructure\Doctrine\Repository\TranscodeSessionRepository;
use App\Transcode\Infrastructure\Doctrine\TranscodeRuntimeState;
use App\Transcode\Infrastructure\Doctrine\TranscodeSessionShareStore;
use App\Transcode\Infrastructure\Transcode\TranscodeJobService;
use App\Transcode\Infrastructure\Transcode\TranscodeSessionService;
use App\Transcode\Interface\Controller\TranscodeSessionController;
use App\Transcode\Interface\Request\CreateTranscodeSessionRequest;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;

require_once dirname(__DIR__) . '/Fixtures/Transcode/copy-start-race.php';

/**
 * A user's share of transcode sessions and the reclaim of silent sessions, through the real start
 * handler, signed start, session endpoint and copy start of delivery negotiation, on migrated
 * PostgreSQL.
 *
 * Each test copies the transcode tables into a schema of its own inside the rolled-back transaction,
 * so the count of occupied video slots sees only the test's rows.
 */
final class TranscodeSessionSharePersistenceTest extends TestCase
{
    use OwnershipPersistenceHarness;

    private const array TABLES = ['videos', 'transcode_jobs', 'transcode_sessions', 'transcode_runtime_state', 'transcode_control_receipts'];

    public function testAStartPastTheShareEndsTheUsersLongestSilentSessionAndIsRefusedWhileEveryOtherPlays(): void
    {
        $connection = $this->isolate();
        $user = Uuid::generate();
        $stack = $this->stack($user, 8);
        $finished = $this->seed($stack, $user, $this->video(), 'finished', '360p', '20 minutes', 'complete');
        $silent = $this->seed($stack, $user, $this->video(), 'silent', '360p', '5 minutes');
        $playing = [];
        foreach (range(1, 4) as $index) {
            $playing[] = $this->seed($stack, $user, $this->video(), 'playing-' . $index, '360p', '10 seconds');
        }

        $started = $stack['playback']->start($this->video(), Uuid::generate()->toString(), qualityTier: '360p');

        self::assertCount(1, $started);
        self::assertSame('cancelled', $this->sessionState($connection, $finished), 'The finished film was silent longest.');
        self::assertSame('complete', $this->outcome($connection, $finished), 'A finished execution holds no slot and is not cancelled.');
        self::assertSame(0, (int) $connection->fetchOne('SELECT reference_count FROM transcode_jobs j JOIN transcode_sessions s ON s.job_id = j.id WHERE s.id = ?', [$finished->toString()]));
        self::assertSame(PlaybackEndReason::Expired, $stack['playback']->endReason(...$this->playbackOf($connection, $finished)));
        self::assertSame('active', $this->sessionState($connection, $silent));
        self::assertSame(6, $this->held($connection, $user));

        $connection->executeStatement('UPDATE transcode_sessions SET updated_at = clock_timestamp() WHERE user_id = ?', [$user->toString()]);
        $refusal = $this->refusal(fn () => $stack['playback']->start($this->video(), Uuid::generate()->toString(), qualityTier: '360p'));

        self::assertSame(['reason' => 'transcode_session_limit', 'limit' => 6], $refusal->details);
        self::assertSame(6, $this->held($connection, $user));
        self::assertSame(7, (int) $connection->fetchOne('SELECT count(*) FROM transcode_sessions'), 'The refused start created no session.');
        foreach ([$silent, ...$playing] as $session) {
            self::assertSame('active', $this->sessionState($connection, $session));
        }
    }

    public function testWhileAVideoSlotIsFreeAStartLeavesOtherUsersSilentSessionsAlone(): void
    {
        $connection = $this->isolate();
        $user = Uuid::generate();
        $stack = $this->stack($user, 3);
        $other = $this->seed($stack, Uuid::generate(), $this->video(), 'other', '720p', '3 hours');

        self::assertCount(1, $stack['playback']->start($this->video(), Uuid::generate()->toString(), qualityTier: '360p'));
        self::assertSame('active', $this->sessionState($connection, $other));
        self::assertSame('accepted', $this->outcome($connection, $other));
    }

    public function testAStartThatFindsNoFreeVideoSlotEndsTheSessionSilentForSeventyMinutesOnly(): void
    {
        $connection = $this->isolate();
        $first = Uuid::generate();
        $stack = $this->stack($first, 2);
        $owner = Uuid::generate();
        $abandoned = $this->seed($stack, $owner, $this->video(), 'abandoned', '720p', '71 minutes');
        // A paused player renews its sign of life once an hour when it signs its stream URL.
        $paused = $this->seed($stack, $owner, $this->video(), 'paused', '720p', '65 minutes');

        self::assertCount(1, $stack['playback']->start($this->video(), Uuid::generate()->toString(), qualityTier: '360p'));
        self::assertSame('cancelled', $this->sessionState($connection, $abandoned));
        self::assertSame('cancelled', $this->outcome($connection, $abandoned), 'The worker reads the cancellation and gives up the slot.');
        self::assertSame(PlaybackEndReason::Expired, $this->stack($owner, 2)['playback']->endReason(...$this->playbackOf($connection, $abandoned)));
        self::assertSame('active', $this->sessionState($connection, $paused));

        $second = Uuid::generate();
        try {
            $this->stack($second, 2)['playback']->start($this->video(), Uuid::generate()->toString(), qualityTier: '360p');
            self::fail('A start that finds every slot held by sessions that reported playback within 70 minutes waits and is refused.');
        } catch (\Throwable $failure) {
            self::assertInstanceOf(TranscodeStartupUnavailableException::class, HandlerFailure::cause($failure));
        }
        self::assertSame('active', $this->sessionState($connection, $paused));
        self::assertSame('accepted', $this->outcome($connection, $paused));
        self::assertSame(1, $this->held($connection, $first));
    }

    public function testSessionsOfOneClientCountOneByOne(): void
    {
        $connection = $this->isolate();
        $user = Uuid::generate();
        $stack = $this->stack($user, 8);
        $request = Uuid::generate()->toString();
        [$film, $series] = [$this->video(), $this->video()];
        foreach (['360p', '480p', '720p', '1080p'] as $tier) {
            $this->seed($stack, $user, $film, $request, $tier, '5 seconds');
        }
        foreach (['360p', '720p'] as $tier) {
            $this->seed($stack, $user, $series, $request, $tier, '5 seconds');
        }

        $refusal = $this->refusal(fn () => $stack['playback']->start($this->video(), $request, qualityTier: '360p'));

        self::assertSame('transcode_session_limit', $refusal->details['reason']);
        self::assertSame(6, $this->held($connection, $user));
    }

    public function testASessionStartedThroughTheSessionEndpointCountsLikeASignedStart(): void
    {
        $connection = $this->isolate();
        $user = Uuid::generate();
        $stack = $this->stack($user, 8);
        foreach (range(1, 5) as $ignored) {
            self::assertCount(1, $stack['playback']->start($this->video(), Uuid::generate()->toString(), qualityTier: '360p'));
        }
        $response = $stack['controller']->create($this->sessionRequest($connection, $this->video()));
        self::assertSame(201, $response->getStatusCode());
        self::assertSame(6, $this->held($connection, $user));

        self::assertSame('transcode_session_limit', $this->refusal(fn () => $stack['playback']->start($this->video(), Uuid::generate()->toString(), qualityTier: '360p'))->details['reason']);
        self::assertSame('transcode_session_limit', $this->refusal(fn () => $stack['controller']->create($this->sessionRequest($connection, $this->video())))->details['reason']);
        self::assertSame(6, $this->held($connection, $user));
    }

    public function testAThreeTierStartThatWouldPassTheShareCreatesNoSessionAndEndsNone(): void
    {
        $connection = $this->isolate();
        $user = Uuid::generate();
        $stack = $this->stack($user, 8);
        $silent = $this->seed($stack, $user, $this->video(), 'silent', '360p', '5 minutes');
        foreach (range(1, 4) as $index) {
            $this->seed($stack, $user, $this->video(), 'playing-' . $index, '360p', '5 seconds');
        }
        $film = $this->video(1080);

        // Five held, one of them silent, and three tiers to start: seven would remain.
        $refusal = $this->refusal(fn () => $stack['playback']->start($film, Uuid::generate()->toString()));

        self::assertSame('transcode_session_limit', $refusal->details['reason']);
        self::assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM transcode_sessions WHERE video_id = ?', [$film->toString()]));
        self::assertSame('active', $this->sessionState($connection, $silent));
        self::assertSame(5, $this->held($connection, $user));
    }

    public function testATierRefusedAfterAnotherStartTookTheRoomCancelsTheSessionsTheStartCreated(): void
    {
        $connection = $this->isolate();
        $user = Uuid::generate();
        $raced = false;
        $stack = null;
        // While the first tier waits for its worker, another start of the same user fills the share.
        $stack = $this->stack($user, 8, function () use (&$raced, &$stack, $user): void {
            if ($raced) {
                return;
            }
            $raced = true;
            foreach (range(1, 3) as $index) {
                $this->seed($stack, $user, $this->video(), 'other-start-' . $index, '360p', '1 second');
            }
        });
        foreach (range(1, 2) as $index) {
            $this->seed($stack, $user, $this->video(), 'playing-' . $index, '360p', '5 seconds');
        }
        $film = $this->video(1080);

        $refusal = $this->refusal(fn () => $stack['playback']->start($film, Uuid::generate()->toString()));

        self::assertTrue($raced);
        self::assertSame('transcode_session_limit', $refusal->details['reason']);
        $created = $connection->fetchAllAssociative('SELECT s.state, r.outcome FROM transcode_sessions s JOIN transcode_runtime_state r ON r.session_id = s.id WHERE s.video_id = ?', [$film->toString()]);
        self::assertSame([['state' => 'cancelled', 'outcome' => 'cancelled']], $created, 'The first tier was admitted, then cancelled with its execution.');
        self::assertSame(5, $this->held($connection, $user));
    }

    /**
     * Two starts at once, one below the share: a start of the same user waits for the first one's
     * lock and then finds the share full; a start of another user does not wait.
     */
    #[DataProvider('contenders')]
    public function testConcurrentStartsOfOneUserTakeTurnsAndLeaveExactlyTheShare(bool $sameUser): void
    {
        CustomTypesRegistrar::register();
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        self::assertIsString($url);
        $params = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
        $configuration = new Configuration();
        $configuration->setMiddlewares([new BaanderDriverMiddleware()]);
        $writer = DriverManager::getConnection($params, $configuration);
        $observer = DriverManager::getConnection($params, $configuration);
        $schema = 'transcode_share_race_' . bin2hex(random_bytes(6));
        $process = null;
        $output = tmpfile();
        self::assertIsResource($output);
        try {
            $writer->executeStatement('CREATE SCHEMA ' . $schema);
            foreach (self::TABLES as $table) {
                $writer->executeStatement(sprintf('CREATE TABLE %1$s.%2$s (LIKE public.%2$s INCLUDING ALL)', $schema, $table));
            }
            foreach ([$writer, $observer] as $connection) {
                $connection->executeStatement(sprintf('SET search_path TO %s, public', $schema));
            }
            $user = Uuid::generate();
            $runtime = new TranscodeRuntimeState($writer, self::transaction($writer));
            $stack = ['connection' => $writer, 'runtime' => $runtime];
            foreach (range(1, 5) as $index) {
                $this->seed($stack, $user, Uuid::generate(), 'playing-' . $index, '360p', '5 seconds');
            }
            $share = self::share($writer, $runtime, new DeploymentProfile());

            $writer->beginTransaction();
            $share->lock($user);
            $share->makeRoom($user, Uuid::generate(), 'first-start');
            $this->seed($stack, $user, Uuid::generate(), 'first-start', '360p', '0 seconds');
            $applicationName = 'transcode_share_race_' . bin2hex(random_bytes(6));
            $process = proc_open(
                [PHP_BINARY, '-r', self::CONTENDER, '--', dirname(__DIR__, 2) . '/vendor/autoload.php', $schema, $applicationName, ($sameUser ? $user : Uuid::generate())->toString()],
                [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output],
                $pipes,
            );
            self::assertIsResource($process);
            if ($sameUser) {
                $deadline = microtime(true) + 3;
                do {
                    $waiting = (int) $observer->fetchOne("SELECT count(*) FROM pg_stat_activity WHERE application_name = :name AND wait_event_type = 'Lock' AND wait_event = 'advisory'", ['name' => $applicationName]);
                    if ($waiting === 1) {
                        break;
                    }
                    usleep(1000);
                } while (microtime(true) < $deadline);
                self::assertSame(1, $waiting, 'The second start must wait for the user\'s lock.');
                self::assertSame(5, $this->held($observer, $user));
                $writer->commit();
            }
            $status = proc_close($process);
            $process = null;
            rewind($output);
            $result = (string) stream_get_contents($output);
            self::assertSame(0, $status, $result);
            self::assertSame($sameUser ? 'refused' : 'admitted', $result);
            if (!$sameUser) {
                $writer->commit();
            }
            self::assertSame(6, $this->held($observer, $user));
        } finally {
            if (is_resource($process)) {
                proc_terminate($process, 9);
                proc_close($process);
            }
            fclose($output);
            if ($writer->isTransactionActive()) {
                $writer->rollBack();
            }
            $writer->executeStatement('DROP SCHEMA IF EXISTS ' . $schema . ' CASCADE');
            $writer->close();
            $observer->close();
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function contenders(): iterable
    {
        yield 'the same user' => [true];
        yield 'another user' => [false];
    }

    public function testACopyStartPastTheShareEndsTheUsersLongestSilentSessionAndIsRefusedWhileEveryOtherPlays(): void
    {
        $connection = $this->isolate();
        $user = Uuid::generate();
        $stack = $this->stack($user, 8);
        $silent = $this->seed($stack, $user, $this->video(), 'silent', '360p', '5 minutes');
        foreach (range(1, 5) as $index) {
            $this->seed($stack, $user, $this->video(), 'playing-' . $index, '360p', '10 seconds');
        }

        $this->copy($stack, $user, $this->video());

        self::assertSame('cancelled', $this->sessionState($connection, $silent));
        self::assertSame('cancelled', $this->outcome($connection, $silent), 'The worker reads the cancellation and gives up the slot.');
        self::assertSame(6, $this->held($connection, $user));

        $connection->executeStatement('UPDATE transcode_sessions SET updated_at = clock_timestamp() WHERE user_id = ?', [$user->toString()]);
        $refusal = $this->refusal(fn () => $this->copy($stack, $user, $this->video()));

        self::assertSame(['reason' => 'transcode_session_limit', 'limit' => 6], $refusal->details);
        self::assertSame(6, $this->held($connection, $user));
        self::assertSame(7, (int) $connection->fetchOne('SELECT count(*) FROM transcode_sessions'), 'The refused copy start created no session.');
    }

    public function testCopyAndEncodeSessionsCountTowardOneShare(): void
    {
        $connection = $this->isolate();
        $user = Uuid::generate();
        $stack = $this->stack($user, 8);
        $copied = $this->sessionId($connection, $this->copy($stack, $user, $this->video())->sessionId);
        foreach (range(1, 2) as $ignored) {
            $this->copy($stack, $user, $this->video());
        }
        self::assertCount(1, $stack['playback']->start($this->video(), Uuid::generate()->toString(), qualityTier: '360p'));
        $encoded = Uuid::fromString((string) $connection->fetchOne(
            "SELECT s.id FROM transcode_sessions s JOIN transcode_jobs j ON j.id = s.job_id WHERE s.user_id = ? AND j.quality_tier_name = '360p'",
            [$user->toString()],
        ));
        foreach (range(1, 2) as $ignored) {
            self::assertCount(1, $stack['playback']->start($this->video(), Uuid::generate()->toString(), qualityTier: '360p'));
        }
        self::assertSame(6, $this->held($connection, $user));

        $this->silence($connection, $copied, '5 minutes');
        self::assertCount(1, $stack['playback']->start($this->video(), Uuid::generate()->toString(), qualityTier: '360p'));
        self::assertSame('cancelled', $this->sessionState($connection, $copied), 'A silent copy session gives way to an encode start.');

        $this->silence($connection, $encoded, '5 minutes');
        $this->copy($stack, $user, $this->video());
        self::assertSame('cancelled', $this->sessionState($connection, $encoded), 'A silent encode session gives way to a copy start.');
        self::assertSame(6, $this->held($connection, $user));

        self::assertSame('transcode_session_limit', $this->refusal(fn () => $this->copy($stack, $user, $this->video()))->details['reason']);
        self::assertSame('transcode_session_limit', $this->refusal(fn () => $stack['playback']->start($this->video(), Uuid::generate()->toString(), qualityTier: '360p'))->details['reason']);
        self::assertSame(6, $this->held($connection, $user));
    }

    /**
     * A copy runs in a video slot under the transcode capability and in a shared copy slot under remux.
     *
     * @return iterable<string, array{bool}>
     */
    public static function copyDeployments(): iterable
    {
        yield 'transcode' => [false];
        yield 'remux' => [true];
    }

    #[DataProvider('copyDeployments')]
    public function testWhileASlotIsFreeACopyStartLeavesOtherUsersSilentSessionsAlone(bool $remux): void
    {
        $connection = $this->isolate();
        $user = Uuid::generate();
        $stack = $this->stack($user, 2, remux: $remux);
        $other = $this->seed($stack, Uuid::generate(), $this->video(), 'other', 'copy', '3 hours');

        $this->copy($stack, $user, $this->video());

        self::assertSame('active', $this->sessionState($connection, $other));
        self::assertSame('accepted', $this->outcome($connection, $other));
    }

    #[DataProvider('copyDeployments')]
    public function testACopyStartThatFindsNoFreeSlotEndsTheSessionSilentForSeventyMinutesOnly(bool $remux): void
    {
        $connection = $this->isolate();
        $first = Uuid::generate();
        $stack = $this->stack($first, 2, remux: $remux);
        $owner = Uuid::generate();
        $abandoned = $this->seed($stack, $owner, $this->video(), 'abandoned', 'copy', '71 minutes');
        // A paused player renews its sign of life once an hour when it signs its stream URL.
        $paused = $this->seed($stack, $owner, $this->video(), 'paused', 'copy', '65 minutes');

        $this->copy($stack, $first, $this->video());

        self::assertSame('cancelled', $this->sessionState($connection, $abandoned));
        self::assertSame('cancelled', $this->outcome($connection, $abandoned), 'The worker reads the cancellation and gives up the slot.');
        self::assertSame('active', $this->sessionState($connection, $paused));

        $second = Uuid::generate();
        try {
            $this->copy($this->stack($second, 2, remux: $remux), $second, $this->video());
            self::fail('A copy start that finds every slot held by sessions that reported playback within 70 minutes waits and is refused.');
        } catch (TranscodeStartupUnavailableException) {
        }
        self::assertSame('active', $this->sessionState($connection, $paused));
        self::assertSame('accepted', $this->outcome($connection, $paused));
        self::assertSame(1, $this->held($connection, $first));
    }

    /** The share ends a finished copy without cancelling its execution, which stays complete. */
    public function testAFinishedCopyThatGaveWayStartsAgainForItsClientWithANewSession(): void
    {
        $connection = $this->isolate();
        $user = Uuid::generate();
        $stack = $this->stack($user, 8);
        [$film, $client] = [$this->video(), Uuid::generate()->toString()];
        $first = $this->copy($stack, $user, $film, $client);
        $finished = $this->sessionId($connection, $first->sessionId);
        $connection->executeStatement("UPDATE transcode_runtime_state SET outcome = 'complete' WHERE session_id = ?", [$finished->toString()]);
        $this->silence($connection, $finished, '20 minutes');
        $paused = $this->seed($stack, $user, $this->video(), 'paused', '360p', '5 minutes');
        foreach (range(1, 4) as $index) {
            $this->seed($stack, $user, $this->video(), 'playing-' . $index, '360p', '10 seconds');
        }
        self::assertCount(1, $stack['playback']->start($this->video(), Uuid::generate()->toString(), qualityTier: '360p'));
        self::assertSame('cancelled', $this->sessionState($connection, $finished));
        self::assertSame('complete', $this->outcome($connection, $finished));

        $again = $this->copy($stack, $user, $film, $client);

        self::assertFalse($again->sessionId->equals($first->sessionId), 'The ended session is not handed back.');
        self::assertSame($first->jobId->toString(), $again->jobId->toString());
        self::assertSame('cancelled', $this->sessionState($connection, $paused), 'The new session made room like any other start.');
        self::assertSame(6, $this->held($connection, $user));
    }

    /**
     * Two copy starts of one user at once, one below the share, each on a connection and ORM identity
     * map of its own as two delivery negotiations run: the second waits for the first one's lock and
     * then finds the share full.
     */
    public function testConcurrentCopyStartsOfOneUserLeaveExactlyTheShare(): void
    {
        $writer = CopyStartRace::connect();
        $observer = CopyStartRace::connect();
        $schema = 'transcode_copy_race_' . bin2hex(random_bytes(6));
        $contender = null;
        try {
            $writer->executeStatement('CREATE SCHEMA ' . $schema);
            foreach (self::TABLES as $table) {
                $writer->executeStatement(sprintf('CREATE TABLE %1$s.%2$s (LIKE public.%2$s INCLUDING ALL)', $schema, $table));
            }
            foreach ([$writer, $observer] as $connection) {
                $connection->executeStatement(sprintf('SET search_path TO %s, public', $schema));
            }
            $user = Uuid::generate();
            $stack = ['connection' => $writer, 'runtime' => new TranscodeRuntimeState($writer, self::transaction($writer))];
            foreach (range(1, 5) as $index) {
                $this->seed($stack, $user, Uuid::generate(), 'playing-' . $index, '360p', '5 seconds');
            }
            $application = 'transcode_copy_race_' . bin2hex(random_bytes(6));
            $contender = $this->contender($schema, $application, $user);
            // The first start has created its session and not yet committed when the second one starts.
            $first = CopyStartRace::service($writer, self::beforeCommit($writer, function () use ($contender, $observer, $application, $user): void {
                self::assertSame(3, fwrite($contender['input'], "go\n"));
                fflush($contender['input']);
                $this->awaitAdvisoryWait($observer, $application, $contender['process']);
                self::assertSame(5, $this->held($observer, $user));
            }));

            $first->start(CopyStartRace::input($user));

            self::assertSame('refused', $this->outcomeOf($contender));
            self::assertSame(6, $this->held($observer, $user));
        } finally {
            if ($contender !== null && is_resource($contender['process'])) {
                proc_terminate($contender['process'], 9);
                proc_close($contender['process']);
            }
            if ($writer->isTransactionActive()) {
                $writer->rollBack();
            }
            $writer->executeStatement('DROP SCHEMA IF EXISTS ' . $schema . ' CASCADE');
            $writer->close();
            $observer->close();
        }
    }

    /** A start's room check from a separate PHP process on its own connection; prints whether it was admitted. */
    private const string CONTENDER = <<<'PHP'
require $argv[1];
\App\Shared\Infrastructure\Doctrine\Type\CustomTypesRegistrar::register();
$params = (new \Doctrine\DBAL\Tools\DsnParser(['postgresql' => 'pdo_pgsql']))->parse(getenv('OUTBOX_TEST_DATABASE_URL'));
$configuration = new \Doctrine\DBAL\Configuration();
$configuration->setMiddlewares([new \App\Shared\Infrastructure\Doctrine\Platform\BaanderDriverMiddleware()]);
$connection = \Doctrine\DBAL\DriverManager::getConnection($params, $configuration);
$connection->executeStatement('SET search_path TO ' . $argv[2] . ', public');
$connection->executeQuery("SELECT set_config('application_name', :name, false)", ['name' => $argv[3]])->free();
$connection->executeStatement("SET lock_timeout TO '5s'");
$connection->executeStatement("SET statement_timeout TO '8s'");
$transaction = new class ($connection) implements \App\Shared\Application\Port\TransactionPortInterface {
    public function __construct(private readonly \Doctrine\DBAL\Connection $connection) {}
    public function run(callable $operation): mixed { return $this->connection->transactional(static fn (): mixed => $operation()); }
};
$settings = new class implements \App\Shared\Application\Port\SystemSettingsPortInterface {
    public function get(string $key): bool|int|string { return $key === \App\Transcode\Application\Settings\TranscodeSettingDefinitions::SESSIONS_PER_USER ? 6 : throw new \LogicException($key); }
};
$share = new \App\Transcode\Application\Service\TranscodeSessionShare(
    new \App\Transcode\Infrastructure\Doctrine\TranscodeSessionShareStore($connection),
    new \App\Transcode\Infrastructure\Doctrine\TranscodeRuntimeState($connection, $transaction),
    $transaction,
    $settings,
    new \App\Shared\Application\DTO\DeploymentProfile(),
    new \Symfony\Component\Clock\NativeClock(),
);
$user = \App\Shared\Domain\Model\Uuid::fromString($argv[4]);
try {
    $transaction->run(static function () use ($share, $user): void {
        $share->lock($user);
        $share->makeRoom($user, \App\Shared\Domain\Model\Uuid::generate(), 'second-start');
    });
    echo 'admitted';
} catch (\App\Transcode\Application\Exception\TranscodeSessionLimitException) {
    echo 'refused';
}
PHP;

    private function isolate(): Connection
    {
        $connection = $this->manager->getConnection();
        $schema = 'transcode_share_' . bin2hex(random_bytes(6));
        $connection->executeStatement('CREATE SCHEMA ' . $schema);
        foreach (self::TABLES as $table) {
            $connection->executeStatement(sprintf('CREATE TABLE %1$s.%2$s (LIKE public.%2$s INCLUDING ALL)', $schema, $table));
        }
        $connection->executeStatement(sprintf('SET LOCAL search_path TO %s, public', $schema));

        return $connection;
    }

    private function video(int $height = 720): Uuid
    {
        $video = Uuid::generate();
        $this->manager->getConnection()->executeStatement(
            'INSERT INTO videos (id, public_id, path, hash, width, height, created_at, updated_at)'
            . " VALUES (:id, :public, '/media/share.mkv', :hash, :width, :height, clock_timestamp(), clock_timestamp())",
            ['id' => $video->toString(), 'public' => new PublicId()->toString(), 'hash' => bin2hex(random_bytes(16)), 'width' => intdiv($height * 16, 9), 'height' => $height],
        );

        return $video;
    }

    /**
     * A session another start admitted, its execution in the given outcome and its last sign of life the given time ago.
     *
     * @param array{connection: Connection, runtime: TranscodeRuntimeState} $stack
     */
    private function seed(array $stack, Uuid $user, Uuid $video, string $request, string $tier, string $silentFor, string $outcome = 'accepted'): Uuid
    {
        ['connection' => $connection, 'runtime' => $runtime] = $stack;
        [$job, $session] = [Uuid::generate(), Uuid::generate()];
        $connection->executeStatement(
            'INSERT INTO transcode_jobs (id, public_id, video_id, quality_tier_name, status, reference_count, output_directory, owner_user_id, request_id, created_at, updated_at)'
            . " VALUES (:job, :public, :video, :tier, :status, 1, '/fixture/share', :user, :request, clock_timestamp(), clock_timestamp())",
            ['job' => $job->toString(), 'public' => new PublicId()->toString(), 'video' => $video->toString(), 'tier' => $tier,
                'status' => $outcome === 'complete' ? 'completed' : 'in_progress', 'user' => $user->toString(), 'request' => $request],
        );
        $connection->executeStatement(
            'INSERT INTO transcode_sessions (id, public_id, user_id, job_id, video_id, state, created_at, updated_at)'
            . " VALUES (:session, :public, :user, :job, :video, 'active', clock_timestamp(), clock_timestamp())",
            ['session' => $session->toString(), 'public' => new PublicId()->toString(), 'user' => $user->toString(), 'job' => $job->toString(), 'video' => $video->toString()],
        );
        $connection->transactional(static fn (): mixed => $runtime->admit($job, $session, $request, new DesiredTranscodeState(AudioProfile::streamingStereo())));
        $connection->executeStatement('UPDATE transcode_runtime_state SET outcome = ? WHERE job_id = ?', [$outcome, $job->toString()]);
        $connection->executeStatement('UPDATE transcode_sessions SET updated_at = clock_timestamp() - CAST(? AS interval) WHERE id = ?', [$silentFor, $session->toString()]);

        return $session;
    }

    /**
     * The production start paths for one admin user, with a worker that accepts a pending execution
     * while fewer executions than the slots of the video kind run: the video slots, or under remux the
     * shared copy slots.
     *
     * @param (\Closure(): void)|null $whileWaiting runs each time a start waits for its worker
     * @return array{connection: Connection, runtime: TranscodeRuntimeState, playback: AuthorizedPlayback, controller: TranscodeSessionController, copy: CopyPresentationService}
     */
    private function stack(Uuid $user, int $slots, ?\Closure $whileWaiting = null, bool $remux = false): array
    {
        $connection = $this->manager->getConnection();
        $transaction = self::transaction($connection);
        $runtime = new TranscodeRuntimeState($connection, $transaction);
        $storage = $this->createStub(TranscodeStoragePortInterface::class);
        $storage->method('forGeneration')->willReturnSelf();
        $storage->method('resolveJobDirectory')->willReturn('/fixture/share/generation-1');
        $jobs = new TranscodeJobService(new TranscodeJobRepository($this->manager), $storage, new EventDispatcher(), $runtime, $transaction, new NullLogger());
        $sessions = new TranscodeSessionService(new TranscodeSessionRepository($this->manager));
        $worker = new MediaWorkerIdentity('baander.app:workers', str_repeat('c', 32), 'media', 1);
        $sleeper = $this->createStub(SleeperInterface::class);
        $sleeper->method('sleep')->willReturnCallback(static function () use ($connection, $runtime, $worker, $slots, $whileWaiting): void {
            $whileWaiting?->__invoke();
            if ((int) $connection->fetchOne("SELECT count(*) FROM transcode_runtime_state WHERE outcome IN ('accepted', 'ready')") >= $slots) {
                return;
            }
            $job = $connection->fetchOne("SELECT job_id FROM transcode_runtime_state WHERE outcome = 'pending' ORDER BY updated_at, job_id LIMIT 1");
            $claim = is_string($job) ? $runtime->claim(Uuid::fromString($job), $worker) : null;
            if ($claim !== null) {
                self::assertTrue($runtime->observe($claim, 'accepted'));
            }
        });
        $presentations = $this->createStub(EncodePresentationPort::class);
        $presentations->method('select')->willReturn(new PresentationSelection('encode', 'source', 0, 1, 'mp4', 'configuration'));
        $profile = $remux ? new DeploymentProfile('remux', 2, 4, 1, sharedCopySlots: $slots) : new DeploymentProfile(videoEncoderSlots: $slots);
        $share = self::share($connection, $runtime, $profile);
        $handler = new CreateTranscodeSessionHandler(new MediaExecutionPolicy($profile), $jobs, $sessions, $storage, $runtime, $transaction, $sleeper, $presentations, self::settings(), $share);
        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([CreateTranscodeSessionCommand::class => [$handler]]))]);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new SecurityUser($user->toString(), 'viewer@baander.app', '', ['ROLE_ADMIN']));
        $security->method('isGranted')->willReturn(true);
        $tiers = $this->createStub(AllowedQualityTiersPortInterface::class);
        $tiers->method('qualifiedTierNames')->willReturn(['360p', '480p', '720p', '1080p', '1440p', '4K']);
        $playback = new AuthorizedPlayback($security, $connection, $bus, new NativeClock(), $tiers, $share, $profile);
        $publicView = $this->createStub(TranscodePublicViewInterface::class);
        $publicView->method('videoId')->willReturnCallback(static function (PublicId $video) use ($connection): ?Uuid {
            $id = $connection->fetchOne('SELECT id FROM videos WHERE public_id = ?', [$video->toString()]);

            return is_string($id) ? Uuid::fromString($id) : null;
        });
        $publicView->method('session')->willReturnCallback(static fn (TranscodeSession $session): TranscodeSessionDto => TranscodeSessionDto::fromModel($session, new PublicId(), new PublicId(), new PublicId()));
        $controller = new TranscodeSessionController($security, $bus, $sessions, $playback, $publicView);
        $copy = new CopyPresentationService(new MediaExecutionPolicy($profile), $jobs, $sessions, $storage, $runtime, $transaction, $sleeper, $share);

        return ['connection' => $connection, 'runtime' => $runtime, 'playback' => $playback, 'controller' => $controller, 'copy' => $copy];
    }

    /**
     * A copy start as delivery negotiation makes it for a film the client can play once repackaged.
     *
     * @param array{copy: CopyPresentationService} $stack
     */
    private function copy(array $stack, Uuid $user, Uuid $video, ?string $client = null): CopyPresentation
    {
        return $stack['copy']->start(new CopyPresentationInput($user, $video, 'source-hash', 0, 1, 'mp4', 1280, 720, $client ?? Uuid::generate()->toString()));
    }

    /** The start's transaction, which runs the given step after the start's work and before its commit. */
    private static function beforeCommit(Connection $connection, \Closure $step): TransactionPortInterface
    {
        return new class ($connection, $step) implements TransactionPortInterface {
            public function __construct(private readonly Connection $connection, private ?\Closure $step)
            {
            }

            public function run(callable $operation): mixed
            {
                return $this->connection->transactional(function () use ($operation): mixed {
                    $result = $operation();
                    [$step, $this->step] = [$this->step, null];
                    $step?->__invoke();

                    return $result;
                });
            }
        };
    }

    /**
     * A copy start of the user in a separate PHP process, loaded and waiting for "go" on its input.
     *
     * @return array{process: resource, input: resource, output: resource, errors: resource}
     */
    private function contender(string $schema, string $application, Uuid $user): array
    {
        $errors = tmpfile();
        self::assertIsResource($errors);
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/Fixtures/Transcode/copy-start-race.php', $schema, $application, $user->toString()],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => $errors],
            $pipes,
        );
        self::assertIsResource($process);
        $contender = ['process' => $process, 'input' => $pipes[0], 'output' => $pipes[1], 'errors' => $errors];
        [$read, $write, $except] = [[$pipes[1]], [], []];
        self::assertSame(1, stream_select($read, $write, $except, 30), 'The contender must finish loading before the first start.');
        if (fgets($pipes[1]) !== "ready\n") {
            rewind($errors);
            self::fail('The contender did not load: ' . stream_get_contents($errors));
        }

        return $contender;
    }

    /** @param resource $process */
    private function awaitAdvisoryWait(Connection $observer, string $application, $process): void
    {
        $deadline = microtime(true) + 8;
        while (microtime(true) < $deadline && proc_get_status($process)['running']) {
            $waiting = (int) $observer->fetchOne("SELECT count(*) FROM pg_stat_activity WHERE application_name = :name AND wait_event_type = 'Lock' AND wait_event = 'advisory'", ['name' => $application]);
            if ($waiting === 1) {
                return;
            }
            usleep(1000);
        }
        self::fail('The second copy start must wait for the user\'s lock.');
    }

    /** @param array{process: resource, input: resource, output: resource, errors: resource} $contender */
    private function outcomeOf(array $contender): string
    {
        fclose($contender['input']);
        [$read, $write, $except] = [[$contender['output']], [], []];
        self::assertSame(1, stream_select($read, $write, $except, 15), 'The released contender must finish.');
        $outcome = (string) stream_get_contents($contender['output']);
        fclose($contender['output']);
        rewind($contender['errors']);
        $errors = (string) stream_get_contents($contender['errors']);
        fclose($contender['errors']);
        self::assertSame(0, proc_close($contender['process']), $errors . $outcome);

        return $outcome;
    }

    private function sessionId(Connection $connection, PublicId $session): Uuid
    {
        return Uuid::fromString((string) $connection->fetchOne('SELECT id FROM transcode_sessions WHERE public_id = ?', [$session->toString()]));
    }

    private function silence(Connection $connection, Uuid $session, string $silentFor): void
    {
        $connection->executeStatement('UPDATE transcode_sessions SET updated_at = clock_timestamp() - CAST(? AS interval) WHERE id = ?', [$silentFor, $session->toString()]);
    }

    private static function transaction(Connection $connection): TransactionPortInterface
    {
        return new class ($connection) implements TransactionPortInterface {
            public function __construct(private readonly Connection $connection) {}
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
            public function get(string $key): int|string
            {
                return match ($key) {
                    TranscodeSettingDefinitions::AAC_IMPLEMENTATION => 'bac',
                    TranscodeSettingDefinitions::SESSIONS_PER_USER => 6,
                    default => throw new \LogicException($key),
                };
            }
        };
    }

    private static function share(Connection $connection, TranscodeRuntimeState $runtime, DeploymentProfile $profile): TranscodeSessionShare
    {
        return new TranscodeSessionShare(new TranscodeSessionShareStore($connection), $runtime, self::transaction($connection), self::settings(), $profile, new NativeClock());
    }

    private function sessionRequest(Connection $connection, Uuid $video): CreateTranscodeSessionRequest
    {
        return new CreateTranscodeSessionRequest(
            videoId: (string) $connection->fetchOne('SELECT public_id FROM videos WHERE id = ?', [$video->toString()]),
            qualityTier: '360p',
            audioProfile: 'streaming_5.1',
            priority: 'high',
            requestId: Uuid::generate()->toString(),
        );
    }

    /** @param \Closure(): mixed $start */
    private function refusal(\Closure $start): ConflictException
    {
        try {
            $start();
        } catch (\Throwable $failure) {
            $cause = HandlerFailure::cause($failure);
            self::assertInstanceOf(ConflictException::class, $cause, (string) $cause);

            return $cause;
        }
        self::fail('The start was admitted.');
    }

    /** The sessions that count toward the user's share. */
    private function held(Connection $connection, Uuid $user): int
    {
        return (int) $connection->fetchOne(
            'SELECT count(*) FROM transcode_sessions s JOIN transcode_runtime_state r ON r.job_id = s.job_id AND r.session_id = s.id'
            . " WHERE s.user_id = ? AND s.state IN ('pending', 'preparing', 'active', 'paused') AND r.outcome IN ('pending', 'accepted', 'ready', 'complete')",
            [$user->toString()],
        );
    }

    /** @phpstan-impure */
    private function sessionState(Connection $connection, Uuid $session): string
    {
        return (string) $connection->fetchOne('SELECT state FROM transcode_sessions WHERE id = ?', [$session->toString()]);
    }

    /** @phpstan-impure */
    private function outcome(Connection $connection, Uuid $session): string
    {
        return (string) $connection->fetchOne('SELECT r.outcome FROM transcode_runtime_state r JOIN transcode_sessions s ON s.job_id = r.job_id WHERE s.id = ?', [$session->toString()]);
    }

    /** @return array{Uuid, string, list<PublicId>} the video, client and jobs the session's client names when it refreshes */
    private function playbackOf(Connection $connection, Uuid $session): array
    {
        $row = $connection->fetchAssociative('SELECT s.video_id, j.request_id, j.public_id FROM transcode_sessions s JOIN transcode_jobs j ON j.id = s.job_id WHERE s.id = ?', [$session->toString()]);
        self::assertIsArray($row);

        return [Uuid::fromString($row['video_id']), $row['request_id'], [PublicId::fromString($row['public_id'])]];
    }
}
