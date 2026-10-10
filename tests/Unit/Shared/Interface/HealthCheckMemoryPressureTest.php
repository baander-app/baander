<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Interface;

use App\Shared\Application\Port\AdminAlertPortInterface;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Shared\Infrastructure\Health\HealthAlertService;
use App\Shared\Infrastructure\Health\HealthAlertTable;
use App\Shared\Infrastructure\Health\HealthCheckService;
use App\Shared\Infrastructure\Health\MessengerWorkerHealth;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Shared\Infrastructure\Swoole\WorkerMemoryTable;
use App\Shared\Interface\Console\HealthCheckCommand;
use App\Shared\Interface\Controller\HealthCheckController;
use App\Tests\Fixtures\Redis\ArrayRedis;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Redis;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A web worker near its memory limit alerts administrators and shows in /health, but
 * never makes the web container unready or unhealthy.
 */
final class HealthCheckMemoryPressureTest extends TestCase
{
    private const int GIGABYTE = 1_073_741_824;

    private string|false $memoryLimit = false;
    private HealthCheckService $service;

    protected function setUp(): void
    {
        $this->memoryLimit = ini_get('memory_limit');
        ini_set('memory_limit', '1G');
        $workerMemory = new WorkerMemoryTable();
        $workerMemory->boot();
        $workerMemory->record(0, (int) (0.30 * self::GIGABYTE), time());
        $workerMemory->record(1, (int) (0.95 * self::GIGABYTE), time());
        $this->service = $this->service($workerMemory);
    }

    protected function tearDown(): void
    {
        if ($this->memoryLimit !== false) {
            ini_set('memory_limit', $this->memoryLimit);
        }
    }

    public function testReadyAnswers200WhenOnlyMemoryIsUnhealthy(): void
    {
        $ready = (new HealthCheckController($this->service))->ready();

        self::assertSame(200, $ready->getStatusCode());
        $body = json_decode((string) $ready->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('ready', $body['status']);
        self::assertNotContains('memory', array_column($body['checks'], 'component'));
    }

    public function testHealthAnswers200AndStillListsMemoryAsUnhealthy(): void
    {
        $response = (new HealthCheckController($this->service))->health();

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('healthy', $body['status']);
        $memory = array_values(array_filter($body['checks'], static fn (array $check): bool => $check['component'] === 'memory'));
        self::assertCount(1, $memory);
        self::assertSame('unhealthy', $memory[0]['status']);
        self::assertSame(1, $memory[0]['details']['workerId']);
    }

    public function testTheContainerHealthcheckPassesAndNamesMemory(): void
    {
        $tester = new CommandTester(new HealthCheckCommand($this->service));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertMatchesRegularExpression('/memory\s+FAIL/', $display);
        self::assertStringContainsString('Unhealthy but not counted toward this container: memory.', $display);
    }

    public function testTheMonitorStillAlertsOnMemory(): void
    {
        $alerts = new class () implements AdminAlertPortInterface {
            /** @var list<string> */
            public array $titles = [];

            public function alertAdmins(string $title, string $body, string $eventType, ?array $referenceData = null): void
            {
                $this->titles[] = $title;
            }
        };
        $settings = new class () implements SystemSettingsPortInterface {
            public function get(string $key): bool
            {
                return true;
            }
        };

        (new HealthAlertService($this->service, $alerts, $settings, new HealthAlertTable(), new MockClock(), new NullLogger()))->checkAndAlert();

        self::assertSame(['memory health degraded'], $alerts->titles);
    }

    private function service(WorkerMemoryTable $workerMemory): HealthCheckService
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchOne')->willReturn(1);
        $connection = $this->createStub(Connection::class);
        $connection->method('executeQuery')->willReturn($result);
        $redis = new ArrayRedis();
        $factory = new RedisClientFactory('redis://127.0.0.1:6379', connectionFactory: static fn (): Redis => $redis);

        return new HealthCheckService(
            connection: $connection,
            redisClientFactory: $factory,
            appEnv: 'prod',
            appSecret: str_repeat('secure-', 6),
            appUrl: 'https://baander.app',
            oauthPrivateKeyPath: '/missing-baander-oauth/private.key',
            oauthPublicKeyPath: '/missing-baander-oauth/public.key',
            vapidPublicKey: '',
            vapidPrivateKey: '',
            messengerWorkerHealth: new MessengerWorkerHealth($factory, new MockClock(), new HealthAlertTable()),
            workerMemory: $workerMemory,
        );
    }
}
