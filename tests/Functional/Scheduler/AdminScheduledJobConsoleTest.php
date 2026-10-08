<?php

declare(strict_types=1);

namespace App\Tests\Functional\Scheduler;

use App\Auth\Application\Command\OAuth\PurgeExpiredOAuthCodesCommand;
use App\Auth\Domain\Model\User;
use App\Scheduler\Application\Port\ScheduledJobPortInterface;
use App\Scheduler\Domain\Model\ScheduledJob;
use App\Scheduler\Domain\ValueObject\ScheduleStatus;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** The scheduler page's routes and the app:scheduler:* commands reach the same port with the same outcomes. */
final class AdminScheduledJobConsoleTest extends TestCase
{
    private const string PATH = '/api/admin/scheduler/jobs';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client->setServerParameter('HTTP_HOST', 'baander.app');
        $this->admin = $this->createAdminUser();
    }

    public function testAnInvalidCronExpressionGetsTheSameMessageOnBothPaths(): void
    {
        $body = [
            'name' => 'Nightly purge',
            'expression' => 'not a cron',
            'jobType' => 'messenger',
            'command' => PurgeExpiredOAuthCodesCommand::class,
        ];
        $error = $this->assertJsonResponse($this->authenticatedRequest('POST', self::PATH, $this->admin, $body), 422, 'error')['error'];
        self::assertIsArray($error);
        self::assertIsArray($error['details']['expression'] ?? null);

        $create = $this->command('app:scheduler:create');
        $exitCode = $create->execute([
            '--name' => $body['name'],
            '--expression' => $body['expression'],
            '--type' => $body['jobType'],
            '--command' => $body['command'],
        ], ['capture_stderr_separately' => true]);

        self::assertSame(Command::INVALID, $exitCode);
        $stderr = $this->flatten($create->getErrorOutput());
        self::assertStringContainsString((string) $error['message'], $stderr);
        foreach ($error['details']['expression'] as $message) {
            self::assertStringContainsString('expression: ' . $message, $stderr);
        }
        self::assertSame([], $this->jobsNamed('Nightly purge'));
    }

    public function testAnUnschedulableCommandGetsTheSameMessageOnBothPaths(): void
    {
        $body = [
            'name' => 'Rogue job',
            'expression' => '0 3 * * *',
            'jobType' => 'console',
            'command' => 'app:rogue',
        ];
        $error = $this->assertJsonResponse($this->authenticatedRequest('POST', self::PATH, $this->admin, $body), 422, 'error')['error'];

        $create = $this->command('app:scheduler:create');
        $exitCode = $create->execute([
            '--name' => $body['name'],
            '--expression' => $body['expression'],
            '--type' => $body['jobType'],
            '--command' => $body['command'],
        ], ['capture_stderr_separately' => true]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertSame('Command "app:rogue" is not registered as a schedulable console command.', $error['message']);
        self::assertStringContainsString((string) $error['message'], $this->flatten($create->getErrorOutput()));
        self::assertSame([], $this->jobsNamed('Rogue job'));
    }

    public function testPausingAPausedJobSucceedsOnBothPaths(): void
    {
        $id = $this->createdOverHttp('Pausable purge');

        foreach ([1, 2] as $attempt) {
            $job = $this->assertJsonResponse($this->authenticatedRequest('POST', self::PATH . '/' . $id . '/pause', $this->admin), 200, 'data')['data'];
            self::assertSame('paused', $job['status'], 'attempt ' . $attempt);
        }
        $pause = $this->command('app:scheduler:pause');
        self::assertSame(Command::SUCCESS, $pause->execute(['id' => $id]), $pause->getDisplay());
        self::assertSame(ScheduleStatus::Paused, $this->job($id)?->getStatus());
    }

    public function testPausingADisabledJobIsAConflictOnBothPaths(): void
    {
        $id = $this->createdOverHttp('Disabled purge');
        $disable = $this->command('app:scheduler:disable');
        self::assertSame(Command::SUCCESS, $disable->execute(['id' => $id]), $disable->getDisplay());

        $error = $this->assertJsonResponse($this->authenticatedRequest('POST', self::PATH . '/' . $id . '/pause', $this->admin), 409, 'error')['error'];
        $pause = $this->command('app:scheduler:pause');

        self::assertSame(Command::FAILURE, $pause->execute(['id' => $id], ['capture_stderr_separately' => true]));
        self::assertSame('A disabled job cannot be paused. Enable it first.', $error['message']);
        self::assertStringContainsString((string) $error['message'], $this->flatten($pause->getErrorOutput()));
        self::assertSame(ScheduleStatus::Disabled, $this->job($id)?->getStatus());
    }

    public function testCommandsListsTheSameCatalogAsTheCreateDialog(): void
    {
        $catalog = $this->assertJsonResponse($this->authenticatedRequest('GET', self::PATH . '/commands', $this->admin), 200, 'data')['data'];
        $commands = $this->command('app:scheduler:commands');

        self::assertSame(Command::SUCCESS, $commands->execute(['--json' => true], ['capture_stderr_separately' => true]));
        self::assertSame($catalog, json_decode($commands->getDisplay(), true, 64, JSON_THROW_ON_ERROR));
        self::assertArrayHasKey(PurgeExpiredOAuthCodesCommand::class, $catalog['messenger']);
    }

    public function testConsoleCommandsManageAJobThroughTheSamePort(): void
    {
        $create = $this->command('app:scheduler:create');
        self::assertSame(Command::SUCCESS, $create->execute([
            '--name' => 'Console purge',
            '--expression' => '0 3 * * *',
            '--type' => 'messenger',
            '--command' => PurgeExpiredOAuthCodesCommand::class,
            '--description' => 'Purges expired authorization codes',
        ]), $create->getDisplay());
        $jobs = $this->jobsNamed('Console purge');
        self::assertCount(1, $jobs);
        $id = $jobs[0];

        $show = $this->command('app:scheduler:show');
        self::assertSame(Command::SUCCESS, $show->execute(['id' => $id, '--json' => true], ['capture_stderr_separately' => true]));
        self::assertSame(
            $this->assertJsonResponse($this->authenticatedRequest('GET', self::PATH . '/' . $id, $this->admin), 200, 'data')['data'],
            json_decode($show->getDisplay(), true, 32, JSON_THROW_ON_ERROR),
        );

        $update = $this->command('app:scheduler:update');
        self::assertSame(Command::SUCCESS, $update->execute(['id' => $id, '--expression' => '30 4 * * *']), $update->getDisplay());
        $updated = $this->job($id);
        self::assertNotNull($updated);
        self::assertSame('30 4 * * *', $updated->getExpression());
        self::assertSame('Console purge', $updated->getName());
        self::assertSame('Purges expired authorization codes', $updated->getDescription());

        foreach (['disable' => ScheduleStatus::Disabled, 'enable' => ScheduleStatus::Active, 'pause' => ScheduleStatus::Paused, 'resume' => ScheduleStatus::Active] as $action => $status) {
            $tester = $this->command('app:scheduler:' . $action);
            self::assertSame(Command::SUCCESS, $tester->execute(['id' => $id]), $tester->getDisplay());
            self::assertSame($status, $this->job($id)?->getStatus(), $action);
        }

        $list = $this->command('app:scheduler:list');
        self::assertSame(Command::SUCCESS, $list->execute(['--json' => true], ['capture_stderr_separately' => true]));
        self::assertSame(
            $this->assertJsonResponse($this->authenticatedRequest('GET', self::PATH, $this->admin), 200, 'data')['data'],
            json_decode($list->getDisplay(), true, 32, JSON_THROW_ON_ERROR),
        );

        $delete = $this->command('app:scheduler:delete');
        self::assertSame(Command::INVALID, $delete->execute(['id' => $id], ['interactive' => false]));
        self::assertNotNull($this->job($id));
        self::assertSame(Command::SUCCESS, $delete->execute(['id' => $id, '--force' => true], ['interactive' => false]), $delete->getDisplay());
        self::assertNull($this->job($id));
        $this->assertJsonResponse($this->authenticatedRequest('GET', self::PATH . '/' . $id, $this->admin), 404);
        self::assertSame(Command::FAILURE, $show->execute(['id' => $id]));
    }

    private function createdOverHttp(string $name): string
    {
        $job = $this->assertJsonResponse($this->authenticatedRequest('POST', self::PATH, $this->admin, [
            'name' => $name,
            'expression' => '0 3 * * *',
            'jobType' => 'messenger',
            'command' => PurgeExpiredOAuthCodesCommand::class,
        ]), 201, 'data')['data'];

        return (string) $job['id'];
    }

    private function job(string $id): ?ScheduledJob
    {
        return $this->port()->getById(Uuid::fromString($id));
    }

    /** @return list<string> */
    private function jobsNamed(string $name): array
    {
        $ids = [];
        foreach ($this->port()->findAll() as $job) {
            if ($job->getName() === $name) {
                $ids[] = $job->getId()->toString();
            }
        }

        return $ids;
    }

    private function port(): ScheduledJobPortInterface
    {
        $port = static::getContainer()->get(ScheduledJobPortInterface::class);
        self::assertInstanceOf(ScheduledJobPortInterface::class, $port);

        return $port;
    }

    private function command(string $name): CommandTester
    {
        return new CommandTester((new Application($this->client->getKernel()))->find($name));
    }

    /** The console wraps long messages; compare them as one line. */
    private function flatten(string $output): string
    {
        return (string) preg_replace('/\s+/', ' ', str_replace('[ERROR]', '', $output));
    }
}
