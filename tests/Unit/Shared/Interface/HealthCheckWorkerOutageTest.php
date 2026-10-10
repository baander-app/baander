<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Interface;

use App\Shared\Infrastructure\Health\HealthCheckService;
use App\Shared\Infrastructure\Health\HealthAlertTable;
use App\Shared\Infrastructure\Health\MessengerWorkerHealth;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Shared\Interface\Console\HealthCheckCommand;
use App\Shared\Interface\Controller\HealthCheckController;
use App\Tests\Fixtures\Redis\ArrayRedis;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;
use Redis;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** A worker outage shows in /health but never makes the web container unready or unhealthy. */
final class HealthCheckWorkerOutageTest extends TestCase
{
    private ArrayRedis $redis;
    private MockClock $clock;
    private bool $databaseUp = true;

    protected function setUp(): void
    {
        $this->redis = new ArrayRedis();
        $this->clock = new MockClock('2026-10-02T10:00:00Z');
    }

    public function testReadyAnswers200WhenOnlyTheWorkerIsUnhealthy(): void
    {
        $controller = new HealthCheckController($this->service($this->staleWorker()));

        $ready = $controller->ready();
        $readyBody = json_decode((string) $ready->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(200, $ready->getStatusCode());
        self::assertSame('ready', $readyBody['status']);
        self::assertNotContains('messenger', array_column($readyBody['checks'], 'component'));

        $healthResponse = $controller->health();
        self::assertSame(200, $healthResponse->getStatusCode());
        $health = json_decode((string) $healthResponse->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('healthy', $health['status']);
        $messenger = array_values(array_filter($health['checks'], static fn (array $check): bool => $check['component'] === 'messenger'));
        self::assertCount(1, $messenger);
        self::assertSame('unhealthy', $messenger[0]['status']);
    }

    public function testReadyStillFailsWhenADependencyIsDown(): void
    {
        $this->databaseUp = false;
        $controller = new HealthCheckController($this->service($this->staleWorker()));

        self::assertSame(503, $controller->ready()->getStatusCode());
    }

    public function testTheContainerHealthcheckPassesWhenOnlyTheWorkerIsUnhealthy(): void
    {
        $tester = new CommandTester(new HealthCheckCommand($this->service($this->staleWorker())));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertMatchesRegularExpression('/messenger\s+FAIL/', $tester->getDisplay());
    }

    public function testTheContainerHealthcheckFailsWhenADependencyIsDown(): void
    {
        $this->databaseUp = false;
        $tester = new CommandTester(new HealthCheckCommand($this->service($this->staleWorker())));

        self::assertSame(Command::FAILURE, $tester->execute([]));
    }

    private function staleWorker(): MessengerWorkerHealth
    {
        $health = new MessengerWorkerHealth($this->redisFactory(), $this->clock, new HealthAlertTable());
        $health->record('idle');
        $this->clock->sleep(300);

        return $health;
    }

    private function redisFactory(): RedisClientFactory
    {
        $redis = $this->redis;

        return new RedisClientFactory('redis://127.0.0.1:6379', connectionFactory: static fn (): Redis => $redis);
    }

    private function service(MessengerWorkerHealth $messenger): HealthCheckService
    {
        $connection = $this->createStub(Connection::class);
        if ($this->databaseUp) {
            $result = $this->createStub(Result::class);
            $result->method('fetchOne')->willReturn(1);
            $connection->method('executeQuery')->willReturn($result);
        } else {
            $connection->method('executeQuery')->willThrowException(new \RuntimeException('Connection refused'));
        }

        return new HealthCheckService(
            connection: $connection,
            redisClientFactory: $this->redisFactory(),
            appEnv: 'prod',
            appSecret: str_repeat('secure-', 6),
            appUrl: 'https://baander.app',
            oauthPrivateKeyPath: '/missing-baander-oauth/private.key',
            oauthPublicKeyPath: '/missing-baander-oauth/public.key',
            vapidPublicKey: '',
            vapidPrivateKey: '',
            messengerWorkerHealth: $messenger,
        );
    }
}
