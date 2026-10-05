<?php

declare(strict_types=1);

namespace App\Tests\Functional\Scheduler;

use App\Auth\Domain\Model\User;
use App\Scheduler\Application\Port\SchedulerManualOccurrenceRecorderInterface;
use App\Scheduler\Infrastructure\Doctrine\DoctrineSchedulerManualOccurrenceRecorder;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Response;

/** Real admin firewall and durable requests, with committed scheduler data in an isolated schema. */
final class ManualTriggerTest extends TestCase
{
    private ?Connection $scheduler = null;
    private ?Connection $observer = null;
    private ?string $schema = null;

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to disposable PostgreSQL.');
        }
        parent::setUp();
        $this->client->setServerParameter('HTTP_HOST', 'baander.app');
        $this->client->setServerParameter('SERVER_NAME', 'baander.app');
        $this->scheduler = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url));
        $this->schema = 'scheduler_trigger_test_' . bin2hex(random_bytes(8));
        $this->scheduler->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->scheduler->executeStatement('SET search_path TO ' . $this->schema);
        // Recorder failures close its connection; fixture observations retain their own schema session.
        $this->observer = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url));
        $this->observer->executeStatement('SET search_path TO ' . $this->schema);

        require_once dirname(__DIR__, 3) . '/migrations/Version_60000000_20260619CreateMissingEntityTables.php';
        $jobs = new \DoctrineMigrations\Version620260619CreateMissingEntityTables($this->scheduler, new NullLogger());
        $jobs->up(new Schema());
        foreach ($jobs->getSql() as $query) {
            if (str_contains($query->getStatement(), 'scheduled_jobs')) {
                $this->scheduler->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        }
        require_once dirname(__DIR__, 3) . '/migrations/Version20261002230000.php';
        $occurrences = new \DoctrineMigrations\Version20261002230000($this->scheduler, new NullLogger());
        $occurrences->up(new Schema());
        foreach ($occurrences->getSql() as $query) {
            $this->scheduler->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }

        $recorder = new DoctrineSchedulerManualOccurrenceRecorder($this->scheduler);
        static::getContainer()->set(DoctrineSchedulerManualOccurrenceRecorder::class, $recorder);
        self::assertSame($recorder, static::getContainer()->get(SchedulerManualOccurrenceRecorderInterface::class));
    }

    protected function tearDown(): void
    {
        try {
            if ($this->observer !== null && $this->schema !== null) {
                $this->observer->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
            }
        } finally {
            $this->scheduler?->close();
            $this->observer?->close();
            parent::tearDown();
        }
    }

    public function testAnonymousRequestIsRejectedBeforeRecording(): void
    {
        $job = $this->job();
        $this->assertJsonResponse($this->anonymousRequest('POST', $this->path($job)), 401);
        self::assertSame(0, $this->countOccurrences());
    }

    public function testOrdinaryUserCannotTriggerAJob(): void
    {
        $user = $this->createTestUser('scheduler-user-' . bin2hex(random_bytes(8)) . '@baander.app');
        $this->assertJsonResponse($this->trigger($this->job(), $user, Uuid::generate()->toString()), 403);
        self::assertSame(0, $this->countOccurrences());
    }

    public function testAdminRetryReturnsOneCommittedOccurrenceEvenAfterJobDeletion(): void
    {
        $admin = $this->admin();
        $job = $this->job();
        $requestId = Uuid::generate()->toString();
        $first = $this->trigger($job, $admin, $requestId);
        $receipt = $this->assertJsonResponse($first, 202);
        self::assertSame($requestId, $first->headers->get('Idempotency-Key'));
        self::assertSame(['data' => ['occurrenceId' => $requestId, 'jobId' => $job->toString()]], $receipt);
        self::assertSame(1, $this->countOccurrences());
        self::assertSame('manual', $this->database()->fetchOne('SELECT origin FROM scheduler_occurrences WHERE id = :id', ['id' => $requestId]), 'An independent connection observes committed intent before the HTTP receipt.');

        $this->database()->executeStatement('DELETE FROM scheduled_jobs WHERE id = :id', ['id' => $job->toString()]);
        $retry = $this->trigger($job, $admin, $requestId);
        self::assertSame($receipt, $this->assertJsonResponse($retry, 202));
        self::assertSame($requestId, $retry->headers->get('Idempotency-Key'));
        self::assertSame(1, $this->countOccurrences());
    }

    public function testAdminMalformedKeyCannotRecordAnOccurrence(): void
    {
        $this->assertJsonResponse($this->trigger($this->job(), $this->admin(), 'invalid-request'), 400);
        self::assertSame(0, $this->countOccurrences());
    }

    public function testAdminCannotReuseAnotherJobsRequestIdentity(): void
    {
        $admin = $this->admin();
        $requestId = Uuid::generate()->toString();
        $this->assertJsonResponse($this->trigger($this->job(), $admin, $requestId), 202);
        $this->assertJsonResponse($this->trigger($this->job(), $admin, $requestId), 409);
        self::assertSame(1, $this->countOccurrences());
    }

    public function testAdminNewRequestForMissingJobReturnsNotFound(): void
    {
        $this->assertJsonResponse($this->trigger(Uuid::generate(), $this->admin(), Uuid::generate()->toString()), 404);
        self::assertSame(0, $this->countOccurrences());
    }

    private function database(): Connection
    {
        return $this->observer ?? throw new \LogicException('Scheduler observer is not initialized.');
    }

    private function countOccurrences(): int
    {
        return (int) $this->database()->fetchOne('SELECT count(*) FROM scheduler_occurrences');
    }

    private function job(): Uuid
    {
        $id = Uuid::generate();
        $this->database()->executeStatement(<<<'SQL'
            INSERT INTO scheduled_jobs (id, revision, name, expression, job_type, command, status, parameters, created_at, updated_at, run_count)
            VALUES (:id, :revision, :name, '* * * * *', 'console', 'app:manual-trigger-fixture', 'paused', CAST(:parameters AS JSON), clock_timestamp(), clock_timestamp(), 0)
            SQL, ['id' => $id->toString(), 'revision' => Uuid::generate()->toString(), 'name' => 'Manual trigger ' . $id->toString(), 'parameters' => '{"address":"scheduler@baander.app"}']);
        return $id;
    }

    private function admin(): User
    {
        $user = User::createByOperator(new Email('scheduler-admin-' . bin2hex(random_bytes(8)) . '@baander.app'), password_hash('password', PASSWORD_BCRYPT), 'Scheduler administrator', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->userRepository->save($user);
        return $user;
    }

    private function path(Uuid $job): string
    {
        return '/api/admin/scheduler/jobs/' . $job->toString() . '/trigger';
    }

    private function trigger(Uuid $job, User $user, string $requestId): Response
    {
        $this->client->request('POST', $this->path($job), server: [
            'HTTP_HOST' => 'baander.app',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_BAANDER_TEST_USER_ID' => $user->getId()->toString(),
            'HTTP_IDEMPOTENCY_KEY' => $requestId,
        ]);
        return $this->client->getResponse();
    }
}
