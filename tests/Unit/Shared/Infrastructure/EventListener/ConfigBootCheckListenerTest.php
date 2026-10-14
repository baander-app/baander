<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\EventListener;

use App\Shared\Infrastructure\EventListener\ConfigBootCheckListener;
use App\Shared\Infrastructure\Health\HealthCheckService;
use App\Shared\Infrastructure\Health\MessengerWorkerHealth;
use App\Shared\Infrastructure\Health\WorkerHeartbeatSightingInterface;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ConfigBootCheckListenerTest extends TestCase
{
    public function testProductionWarningsIncludeDetailsAndRunOncePerWorker(): void
    {
        $keyPath = tempnam(sys_get_temp_dir(), 'baander-config-check-');
        self::assertNotFalse($keyPath);
        $environment = [
            'DATABASE_URL' => 'postgresql://baander:test-only@postgres.baander.app:5432/config_check',
            'REDIS_URL' => 'redis://redis.baander.app:6379',
            'APP_SECRET' => null,
            'APP_URL' => 'https://baander.app',
            'APP_DOMAIN' => 'baander.app',
            'REDIS_PASSWORD' => 'test-only',
            'MAILER_DSN' => 'smtp://mail.baander.app',
            'OAUTH_PRIVATE_KEY_PATH' => $keyPath,
            'OAUTH_PUBLIC_KEY_PATH' => $keyPath,
            'MESSENGER_TRANSPORT_DSN' => 'in-memory://',
        ];
        $previousEnvironment = [];
        foreach ($environment as $name => $value) {
            $previousEnvironment[$name] = getenv($name);
        }
        $checked = new \ReflectionProperty(ConfigBootCheckListener::class, 'checked');
        $previousChecked = $checked->getValue();
        try {
            foreach ($environment as $name => $value) {
                putenv($value === null ? $name : $name . '=' . $value);
            }
            $checked->setValue(null, false);
            $connection = $this->createMock(Connection::class);
            $connection->expects(self::never())->method('executeQuery');
            $redis = $this->createMock(RedisClientFactory::class);
            $redis->expects(self::never())->method('borrow');
            $health = new HealthCheckService(
                connection: $connection,
                redisClientFactory: $redis,
                appEnv: 'prod',
                appSecret: str_repeat('secure-', 6),
                appUrl: 'https://baander.app',
                oauthPrivateKeyPath: $keyPath,
                oauthPublicKeyPath: $keyPath,
                vapidPublicKey: '',
                vapidPrivateKey: '',
                messengerWorkerHealth: new MessengerWorkerHealth(
                    $redis,
                    new MockClock(),
                    $this->createStub(WorkerHeartbeatSightingInterface::class),
                ),
            );
            $logger = $this->createMock(LoggerInterface::class);
            $logger->expects(self::once())->method('warning')->with('[Config] {message}', [
                'component' => 'env',
                'message' => 'Required environment variable APP_SECRET is missing.',
                'suggestion' => 'Add APP_SECRET to your .env file.',
            ]);
            $listener = new ConfigBootCheckListener($health, $logger, 'prod');
            $event = new RequestEvent(
                $this->createStub(HttpKernelInterface::class),
                Request::create('https://baander.app'),
                HttpKernelInterface::MAIN_REQUEST,
            );

            $listener->onKernelRequest($event);
            self::assertTrue($checked->getValue());
            $listener->onKernelRequest($event);
        } finally {
            foreach ($previousEnvironment as $name => $value) {
                putenv($value === false ? $name : $name . '=' . $value);
            }
            $checked->setValue(null, $previousChecked);
            unlink($keyPath);
        }
    }
}
