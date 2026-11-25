<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Health;

use App\Auth\Application\Port\SelfRegistrationInterface;
use App\Shared\Application\DTO\DeploymentProfile;
use App\Shared\Infrastructure\Health\HealthCheckResult;
use App\Shared\Infrastructure\Health\HealthCheckService;
use App\Shared\Infrastructure\Health\HealthStatus;
use App\Shared\Infrastructure\Health\HealthAlertTable;
use App\Shared\Infrastructure\Health\MessengerWorkerHealth;
use App\Shared\Infrastructure\Health\WorkerProfileHealth;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Shared\Infrastructure\Swoole\WorkerMemoryTable;
use App\Tests\Fixtures\Redis\ArrayRedis;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Redis;
use Symfony\Component\Clock\MockClock;

final class HealthCheckServiceTest extends TestCase
{
    private const int GIGABYTE = 1_073_741_824;

    private string|false $memoryLimit = false;

    protected function setUp(): void
    {
        $this->memoryLimit = ini_get('memory_limit');
    }

    protected function tearDown(): void
    {
        if ($this->memoryLimit !== false) {
            ini_set('memory_limit', $this->memoryLimit);
        }
    }

    #[DataProvider('workerMemoryUse')]
    public function testMemoryStatusFollowsTheWorstWorkerAgainstTheLimit(float $share, HealthStatus $expected): void
    {
        ini_set('memory_limit', '1G');
        $table = $this->memoryTable();
        $table->record(0, (int) (0.1 * self::GIGABYTE), time());
        $table->record(2, (int) ($share * self::GIGABYTE), time());

        $memory = $this->memory($this->service(workerMemory: $table));

        self::assertSame($expected, $memory->status);
        self::assertSame(2, $memory->details['workerId']);
        self::assertSame(round($share * 1024, 2), $memory->details['usageMb']);
        self::assertSame(1024.0, $memory->details['limitMb']);
    }

    /** @return iterable<string,array{float,HealthStatus}> */
    public static function workerMemoryUse(): iterable
    {
        yield 'half the limit' => [0.5, HealthStatus::Healthy];
        yield 'just under ninety percent' => [0.89, HealthStatus::Healthy];
        yield 'ninety-two percent' => [0.92, HealthStatus::Unhealthy];
    }

    public function testOneWorkerNearTheLimitMakesMemoryUnhealthyAndIsNamed(): void
    {
        ini_set('memory_limit', '1G');
        $table = $this->memoryTable();
        $table->record(0, (int) (0.30 * self::GIGABYTE), time());
        $table->record(3, (int) (0.95 * self::GIGABYTE), time());

        $memory = $this->memory($this->service(workerMemory: $table));

        self::assertSame(HealthStatus::Unhealthy, $memory->status);
        self::assertSame(3, $memory->details['workerId']);
        self::assertSame(972.8, $memory->details['usageMb']);
    }

    public function testAnExitedWorkersStaleRowIsIgnored(): void
    {
        ini_set('memory_limit', '1G');
        $table = $this->memoryTable();
        $table->record(0, (int) (0.30 * self::GIGABYTE), time());
        $table->record(3, (int) (0.95 * self::GIGABYTE), time() - 3 * WorkerMemoryTable::UPDATE_INTERVAL_SECONDS - 1);

        $memory = $this->memory($this->service(workerMemory: $table));

        self::assertSame(HealthStatus::Healthy, $memory->status);
        self::assertSame(0, $memory->details['workerId']);
    }

    public function testNoMemoryLimitMakesTheCheckNotAvailable(): void
    {
        ini_set('memory_limit', '-1');
        $table = $this->memoryTable();
        $table->record(0, (int) (0.95 * self::GIGABYTE), time());

        $memory = $this->memory($this->service(workerMemory: $table));

        self::assertSame(HealthStatus::NotAvailable, $memory->status);
        self::assertNull($memory->details['limitMb']);
    }

    public function testOutsideTheServerTheCallingProcessIsMeasured(): void
    {
        ini_set('memory_limit', '1G');

        $memory = $this->memory($this->service());

        self::assertSame(HealthStatus::Healthy, $memory->status);
        self::assertNull($memory->details['workerId']);
        self::assertEqualsWithDelta(memory_get_usage(true) / 1_048_576, $memory->details['usageMb'], 4.0);
        self::assertSame(1024.0, $memory->details['limitMb']);
    }

    #[DataProvider('externalKeyValues')]
    public function testExternalKeysUseInjectedConfigurationWithoutGetenv(string $key, int $expectedWarnings): void
    {
        $variables = ['DISCOGS_TOKEN', 'LASTFM_API_KEY', 'SPOTIFY_CLIENT_ID'];
        $requiredVariables = ['DATABASE_URL', 'REDIS_URL', 'APP_SECRET', 'APP_URL', 'APP_DOMAIN', 'REDIS_PASSWORD'];
        $optionalVariables = ['MAILER_DSN', 'OAUTH_PRIVATE_KEY_PATH', 'OAUTH_PUBLIC_KEY_PATH', 'MESSENGER_TRANSPORT_DSN'];
        $previous = [];
        $environmentVariables = [...$variables, ...$requiredVariables, ...$optionalVariables];
        foreach ($environmentVariables as $variable) {
            $previous[$variable] = getenv($variable);
            putenv($variable);
        }
        try {
            foreach ($environmentVariables as $variable) {
                self::assertFalse(getenv($variable));
            }
            $service = $this->service(array_fill_keys(['Discogs', 'Last.fm', 'Spotify'], $key));
            $configuration = $service->checkConfiguration();
            $environmentResults = [];
            foreach ($configuration as $result) {
                if ($result->component === 'env') {
                    $environmentResults[$result->details['var']] = $result;
                }
            }
            self::assertCount(count($requiredVariables) + count($optionalVariables), $environmentResults);
            foreach ($requiredVariables as $variable) {
                self::assertArrayHasKey($variable, $environmentResults);
                self::assertSame(HealthStatus::Unhealthy, $environmentResults[$variable]->status);
                self::assertSame('error', $environmentResults[$variable]->details['severity']);
            }
            foreach ($optionalVariables as $variable) {
                self::assertArrayHasKey($variable, $environmentResults);
                self::assertSame(HealthStatus::NotAvailable, $environmentResults[$variable]->status);
                self::assertSame('warning', $environmentResults[$variable]->details['severity']);
            }
            $results = array_filter($configuration, static fn (HealthCheckResult $result): bool => $result->component === 'api_keys') |> array_values(...);
            self::assertCount($expectedWarnings, $results);
            foreach ($results as $index => $result) {
                self::assertSame(HealthStatus::NotAvailable, $result->status);
                self::assertSame($variables[$index], $result->details['var']);
                self::assertStringNotContainsString($key, json_encode($result->toArray(), JSON_THROW_ON_ERROR));
            }
        } finally {
            foreach ($previous as $variable => $value) {
                putenv($value === false ? $variable : $variable . '=' . $value);
            }
        }
    }

    /** @return iterable<string,array{string,int}> */
    public static function externalKeyValues(): iterable
    {
        yield 'short injected keys warn' => ['tiny', 3];
        yield 'valid injected keys pass' => ['valid-injected-api-key', 0];
        yield 'empty injected keys remain optional' => ['', 0];
    }

    /** @return iterable<string, array{string, bool, bool}> */
    public static function registrationMailers(): iterable
    {
        yield 'open on a null mailer' => ['null://null', true, true];
        yield 'closed on a null mailer' => ['null://null', false, false];
        yield 'open on a mail server' => ['smtp://mail.baander.app:587', true, false];
    }

    #[DataProvider('registrationMailers')]
    public function testOpenRegistrationOnAMailerThatDiscardsMailWarns(string $mailerDsn, bool $open, bool $warns): void
    {
        $registration = $this->createStub(SelfRegistrationInterface::class);
        $registration->method('isOpen')->willReturn($open);

        $results = $this->registrationResults($this->service(mailerDsn: $mailerDsn, selfRegistration: $registration));

        self::assertCount($warns ? 1 : 0, $results);
        if ($warns) {
            self::assertSame(HealthStatus::NotAvailable, $results[0]->status);
            self::assertSame('warning', $results[0]->details['severity']);
            self::assertSame('MAILER_DSN', $results[0]->details['var']);
            self::assertStringContainsString('Self-registration is open', $results[0]->details['message']);
        }
    }

    public function testAnUnreadableRegistrationSettingLeavesTheConfigurationChecksQuiet(): void
    {
        $registration = $this->createStub(SelfRegistrationInterface::class);
        $registration->method('isOpen')->willThrowException(new \RuntimeException('The database is unavailable.'));

        self::assertSame([], $this->registrationResults($this->service(mailerDsn: 'null://null', selfRegistration: $registration)));
    }

    /** @return iterable<string, array{string|false, bool}> */
    public static function urlWrapperSettings(): iterable
    {
        yield 'on' => ['1', true];
        yield 'off, as ini_get reports it' => ['', false];
        yield 'off, written as a number' => ['0', false];
        yield 'unreadable' => [false, false];
    }

    #[DataProvider('urlWrapperSettings')]
    public function testUrlWrappersLeftOnAreAConfigurationError(string|false $setting, bool $reported): void
    {
        $results = HealthCheckService::checkUrlWrappers($setting);

        self::assertCount($reported ? 1 : 0, $results);
        if ($reported) {
            self::assertSame('php_settings', $results[0]->component);
            self::assertSame(HealthStatus::Unhealthy, $results[0]->status);
            self::assertSame('error', $results[0]->details['severity']);
            self::assertSame('allow_url_fopen', $results[0]->details['var']);
        }
    }

    public function testTheImagesPhpSettingsRaiseNoConfigurationProblem(): void
    {
        self::assertSame([], array_filter(
            $this->service()->checkConfiguration(),
            static fn (HealthCheckResult $result): bool => $result->component === 'php_settings',
        ));
    }

    public function testReadinessLeavesOutTheWorkerItsProfileAndMemoryWhileTheFullCheckStillListsThem(): void
    {
        $redis = new ArrayRedis();
        $clock = new MockClock('2026-10-02T10:00:00Z');
        $factory = new RedisClientFactory('redis://127.0.0.1:6379', connectionFactory: static fn (): Redis => $redis);
        $messenger = new MessengerWorkerHealth($factory, $clock, new HealthAlertTable());
        $messenger->record('idle');
        $clock->sleep(300);
        $result = $this->createStub(Result::class);
        $result->method('fetchOne')->willReturn(1);
        $connection = $this->createStub(Connection::class);
        $connection->method('executeQuery')->willReturn($result);
        $connection->method('fetchAllAssociative')->willReturn([[
            'namespace' => 'workers.baander.app', 'boot_id' => str_repeat('a', 32),
            'deployment_profile_fingerprint' => new DeploymentProfile(videoEncoderSlots: 4, audioEncoderSlots: 12)->fingerprint(),
        ]]);
        ini_set('memory_limit', '1G');
        $table = $this->memoryTable();
        $table->record(1, (int) (0.95 * self::GIGABYTE), time());
        $service = $this->service(connection: $connection, redis: $factory, messenger: $messenger, workerMemory: $table);

        $statuses = static fn (array $results): array => array_combine(
            array_map(static fn (HealthCheckResult $result): string => $result->component, $results),
            array_map(static fn (HealthCheckResult $result): HealthStatus => $result->status, $results),
        );

        self::assertSame(
            ['postgresql' => HealthStatus::Healthy, 'redis' => HealthStatus::Healthy],
            $statuses($service->checkReadiness()),
        );
        self::assertSame(HealthStatus::Unhealthy, $statuses($service->check())['messenger']);
        self::assertSame(HealthStatus::Unhealthy, $statuses($service->check())['memory']);
        self::assertSame(HealthStatus::Unhealthy, $statuses($service->check())['worker_profile']);
        foreach ($service->check() as $check) {
            self::assertSame(
                !in_array($check->component, ['messenger', 'worker_profile', 'memory'], true),
                HealthCheckService::affectsContainerHealth($check),
                $check->component,
            );
        }
    }

    /** @return list<HealthCheckResult> */
    private function registrationResults(HealthCheckService $service): array
    {
        return array_filter(
            $service->checkConfiguration(),
            static fn (HealthCheckResult $result): bool => $result->component === 'registration',
        ) |> array_values(...);
    }

    private function memory(HealthCheckService $service): HealthCheckResult
    {
        $results = array_filter($service->check(), static fn (HealthCheckResult $result): bool => $result->component === 'memory') |> array_values(...);
        self::assertCount(1, $results);

        return $results[0];
    }

    private function memoryTable(): WorkerMemoryTable
    {
        $table = new WorkerMemoryTable();
        $table->boot();

        return $table;
    }

    /** @param array<string,string> $apiKeys */
    private function service(
        array $apiKeys = [],
        ?Connection $connection = null,
        ?RedisClientFactory $redis = null,
        ?MessengerWorkerHealth $messenger = null,
        ?WorkerMemoryTable $workerMemory = null,
        string $mailerDsn = 'smtp://mail.baander.app:587',
        ?SelfRegistrationInterface $selfRegistration = null,
    ): HealthCheckService {
        $redis ??= $this->createStub(RedisClientFactory::class);
        $connection ??= $this->createStub(Connection::class);

        return new HealthCheckService(
            connection: $connection,
            redisClientFactory: $redis,
            appEnv: 'prod',
            appSecret: str_repeat('secure-', 6),
            appUrl: 'https://baander.app',
            oauthPrivateKeyPath: '/missing-baander-oauth/private.key',
            oauthPublicKeyPath: '/missing-baander-oauth/public.key',
            vapidPublicKey: '',
            vapidPrivateKey: '',
            mailerDsn: $mailerDsn,
            selfRegistration: $selfRegistration ?? $this->createStub(SelfRegistrationInterface::class),
            messengerWorkerHealth: $messenger ?? new MessengerWorkerHealth($redis, new MockClock(), new HealthAlertTable()),
            workerProfileHealth: new WorkerProfileHealth($connection, new DeploymentProfile()),
            workerMemory: $workerMemory ?? new WorkerMemoryTable(),
            apiKeys: $apiKeys,
        );
    }
}
