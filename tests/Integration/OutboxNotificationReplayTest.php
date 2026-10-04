<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Fixtures\Messaging\MessageCodecFactory;
use App\Auth\Domain\Event\UserRegistered;
use App\Notification\Application\DTO\CreateNotificationCommand;
use App\Notification\Application\DTO\SendEmailCommand;
use App\Notification\Application\DTO\SendPushCommand;
use App\Notification\Application\DTO\SendWebhookCommand;
use App\Notification\Domain\Service\EventCategoryResolver;
use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Shared\Application\Port\AdminAlertPortInterface;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Event\AdminAlertSubscriber;
use App\Shared\Infrastructure\Event\NotificationBridgeSubscriber;
use App\Shared\Infrastructure\Event\NotificationDeliveryBus;
use App\Shared\Infrastructure\Event\NotificationDeliveryRepository;
use App\Shared\Infrastructure\Event\OutboxEventDispatcher;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;

/** Tests replay atomicity with small DB projections, without calling network delivery handlers. */
final class OutboxNotificationReplayTest extends TestCase
{
    private Connection $first;
    private Connection $second;
    private string $schema;

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to a disposable PostgreSQL database.');
        }
        $params = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
        $this->first = DriverManager::getConnection($params);
        $this->second = DriverManager::getConnection($params);
        $this->schema = 'outbox_replay_' . bin2hex(random_bytes(8));
        $this->first->executeStatement('CREATE SCHEMA ' . $this->schema);
        foreach ([$this->first, $this->second] as $connection) {
            $connection->executeStatement('SET search_path TO ' . $this->schema);
        }
        $this->first->executeStatement('CREATE TABLE domain_event_outbox (id BIGSERIAL PRIMARY KEY,
            relayed_at TIMESTAMPTZ, dead_lettered_at TIMESTAMPTZ, lease_token TEXT, lease_until TIMESTAMPTZ)');
        $this->first->executeStatement('INSERT INTO domain_event_outbox DEFAULT VALUES');
        $this->first->executeStatement('CREATE TABLE replay_notifications (id TEXT PRIMARY KEY, kind TEXT NOT NULL)');
        require_once dirname(__DIR__, 2) . '/migrations/Version20261002120000.php';
        $migration = new \DoctrineMigrations\Version20261002120000($this->first, new NullLogger());
        $migration->up(new \Doctrine\DBAL\Schema\Schema());
        foreach ($migration->getSql() as $query) {
            $this->first->executeStatement($query->getStatement());
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->schema)) {
            $this->first->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
            $this->first->close();
            $this->second->close();
        }
    }

    public function testConcreteReplayCommitsNotificationsAndIntentsOnceAcrossConnections(): void
    {
        $listeners = $this->notificationListeners($this->first);
        $listeners->addListener(UserRegistered::class, function (): void {
            self::assertTrue($this->first->isTransactionActive());
            $this->assertCounts($this->first, 2, 3, 1);
            $this->assertCounts($this->second, 0, 0, 0);
        }, -100);
        $event = $this->event();
        $this->replayer($this->first, $listeners)->dispatch($event, 1);
        $this->assertCounts($this->second, 2, 3, 1);

        $this->replayer($this->second, $this->notificationListeners($this->second))->dispatch($event, 1);
        $this->assertCounts($this->first, 2, 3, 1);
        foreach ($this->first->fetchAllAssociative('SELECT payload FROM domain_event_outbox_delivery') as $row) {
            $message = (MessageCodecFactory::create())->decode($row['payload'])->message;
            self::assertContains($message::class, [SendEmailCommand::class, SendPushCommand::class, SendWebhookCommand::class]);
        }
    }

    public function testFailureRollsBackPartialEffectsAndReceiptThenRetryRecovers(): void
    {
        $listeners = $this->notificationListeners($this->first);
        $consumer = new class {
            public bool $fail = true;
        };
        $listeners->addListener(UserRegistered::class, static function () use ($consumer): void {
            if ($consumer->fail) {
                throw new RuntimeException('Later consumer failed');
            }
        }, -100);
        $replayer = $this->replayer($this->first, $listeners, true);
        try {
            $replayer->dispatch($this->event(), 1);
            self::fail('Consumer failures must escape replay.');
        } catch (RuntimeException $error) {
            self::assertSame('Later consumer failed', $error->getMessage());
        }
        self::assertFalse($this->first->isTransactionActive());
        $this->assertCounts($this->second, 0, 0, 0);
        $consumer->fail = false;
        $replayer->dispatch($this->event(), 1);
        $this->assertCounts($this->second, 2, 3, 1);
    }

    public function testConcurrentReceiptCannotRunSecondConsumerAndRetrySkipsCommittedEvent(): void
    {
        $this->second->executeStatement("SET lock_timeout = '100ms'");
        $secondListeners = new EventDispatcher();
        $secondListeners->addListener(UserRegistered::class, static function (): void {
            self::fail('A conflicting receipt must not run duplicate consumers.');
        });
        $secondReplayer = $this->replayer($this->second, $secondListeners, true);
        $firstListeners = $this->notificationListeners($this->first);
        $event = $this->event();
        $firstListeners->addListener(UserRegistered::class, function () use ($secondReplayer, $event): void {
            try {
                $secondReplayer->dispatch($event, 1);
                self::fail('An uncommitted receipt must block the competing claim.');
            } catch (DriverException $error) {
                self::assertSame('55P03', $error->getSQLState());
                self::assertFalse($this->second->isTransactionActive());
            }
        }, -100);
        $this->replayer($this->first, $firstListeners)->dispatch($event, 1);
        $secondReplayer->dispatch($event, 1);
        $this->assertCounts($this->second, 2, 3, 1);
    }

    public function testDeliveryIntentInsertFailureRollsBackNotificationAndEarlierIntents(): void
    {
        $this->first->executeStatement("ALTER TABLE domain_event_outbox_delivery ADD CONSTRAINT reject_test_webhook CHECK (channel <> 'webhook')");
        $replayer = $this->replayer($this->first, $this->notificationListeners($this->first), true);
        $event = $this->event();
        try {
            $replayer->dispatch($event, 1);
            self::fail('Delivery intent insertion failures must escape replay.');
        } catch (HandlerFailedException $error) {
            $cause = array_values($error->getWrappedExceptions())[0];
            self::assertInstanceOf(DriverException::class, $cause);
            self::assertSame('23514', $cause->getSQLState());
        }
        self::assertFalse($this->first->isTransactionActive());
        $this->assertCounts($this->second, 0, 0, 0);
        $this->first->executeStatement('ALTER TABLE domain_event_outbox_delivery DROP CONSTRAINT reject_test_webhook');
        $replayer->dispatch($event, 1);
        $this->assertCounts($this->second, 2, 3, 1);
    }

    public function testMigrationQuarantinesOnlyPendingLegacyEventsAndPreservesThemOnRollback(): void
    {
        $migrationSchema = 'outbox_legacy_' . bin2hex(random_bytes(8));
        $this->first->executeStatement('CREATE SCHEMA ' . $migrationSchema);
        $this->first->executeStatement('SET search_path TO ' . $migrationSchema);
        try {
            $this->first->executeStatement('CREATE TABLE domain_event_outbox (
                id BIGSERIAL PRIMARY KEY, payload TEXT NOT NULL, relayed_at TIMESTAMPTZ,
                dead_lettered_at TIMESTAMPTZ, lease_token TEXT, lease_until TIMESTAMPTZ
            )');
            $timestamp = '2026-01-01T00:00:00+00:00';
            foreach (['pending', 'relayed', 'deadletter'] as $status) {
                $this->first->insert('domain_event_outbox', [
                    'payload' => json_encode(['legacy_status' => $status], JSON_THROW_ON_ERROR),
                    'relayed_at' => $status === 'relayed' ? $timestamp : null,
                    'dead_lettered_at' => $status === 'deadletter' ? $timestamp : null,
                    'lease_token' => 'legacy-' . $status,
                    'lease_until' => $timestamp,
                ]);
            }
            $originalRows = $this->first->fetchAllAssociative('SELECT * FROM domain_event_outbox ORDER BY id');
            $migration = new \DoctrineMigrations\Version20261002120000($this->first, new NullLogger());
            $migration->up(new \Doctrine\DBAL\Schema\Schema());
            foreach ($migration->getSql() as $query) {
                $this->first->executeStatement($query->getStatement());
            }

            self::assertSame(1, (int) $this->first->fetchOne('SELECT COUNT(*) FROM domain_event_outbox WHERE legacy_review_required'));
            $quarantined = $this->first->fetchAssociative('SELECT * FROM domain_event_outbox WHERE id = 1');
            self::assertNotFalse($quarantined);
            self::assertSame($originalRows[0]['payload'], $quarantined['payload']);
            self::assertNull($quarantined['relayed_at']);
            self::assertNotNull($quarantined['dead_lettered_at']);
            self::assertNull($quarantined['lease_token']);
            self::assertNull($quarantined['lease_until']);
            $preservedRows = $this->first->fetchAllAssociative('SELECT id, payload, relayed_at, dead_lettered_at, lease_token, lease_until FROM domain_event_outbox WHERE id > 1 ORDER BY id');
            self::assertSame(array_slice($originalRows, 1), $preservedRows);

            $rollback = new \DoctrineMigrations\Version20261002120000($this->first, new NullLogger());
            $rollback->down(new \Doctrine\DBAL\Schema\Schema());
            foreach ($rollback->getSql() as $query) {
                $this->first->executeStatement($query->getStatement());
            }
            self::assertSame(3, (int) $this->first->fetchOne('SELECT COUNT(*) FROM domain_event_outbox'));
            self::assertSame($originalRows[0]['payload'], $this->first->fetchOne('SELECT payload FROM domain_event_outbox WHERE id = 1'));
            self::assertNull($this->first->fetchOne('SELECT relayed_at FROM domain_event_outbox WHERE id = 1'));
            self::assertNotNull($this->first->fetchOne('SELECT dead_lettered_at FROM domain_event_outbox WHERE id = 1'));
            self::assertNull($this->first->fetchOne("SELECT to_regclass('domain_event_outbox_delivery')"));
            self::assertNull($this->first->fetchOne("SELECT to_regclass('domain_event_outbox_receipt')"));
        } finally {
            $this->first->executeStatement('SET search_path TO ' . $this->schema);
            $this->first->executeStatement('DROP SCHEMA ' . $migrationSchema . ' CASCADE');
        }
    }

    private function notificationListeners(Connection $connection): EventDispatcher
    {
        $deliveryBus = new NotificationDeliveryBus(new NotificationDeliveryRepository($connection), MessageCodecFactory::create());
        $createNotification = static function (CreateNotificationCommand $command) use ($connection, $deliveryBus): void {
            $notificationId = 'aaaaaaaaaaaaaaaaaaaaa';
            $connection->insert('replay_notifications', ['id' => $notificationId, 'kind' => $command->eventName]);
            $userId = Uuid::fromString($command->payload['user_id']);
            $category = NotificationCategory::Security;
            $deliveryBus->dispatch(new SendEmailCommand($userId, $command->payload['email'], $category, 'Title', 'Body', new \DateTimeImmutable(), $notificationId));
            $deliveryBus->dispatch(new SendPushCommand($userId, $category, 'Title', 'Body', $notificationId));
            $deliveryBus->dispatch(new SendWebhookCommand($userId, $category, 'Title', 'Body', $notificationId));
        };
        $forbiddenDelivery = static function (): void {
            throw new RuntimeException('Network delivery must not execute inside notification replay.');
        };
        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            CreateNotificationCommand::class => [$createNotification],
            SendEmailCommand::class => [$forbiddenDelivery],
            SendPushCommand::class => [$forbiddenDelivery],
            SendWebhookCommand::class => [$forbiddenDelivery],
        ]))]);
        $adminPort = new readonly class($connection) implements AdminAlertPortInterface {
            public function __construct(private Connection $connection) {}

            public function alertAdmins(string $title, string $body, string $eventType, ?array $referenceData = null): void
            {
                $this->connection->insert('replay_notifications', ['id' => 'admin-notification', 'kind' => $eventType]);
            }
        };
        $bridge = new NotificationBridgeSubscriber(new EventCategoryResolver(), $bus, new NullLogger());
        $admin = new AdminAlertSubscriber($adminPort, new NullLogger());
        $listeners = new EventDispatcher();
        $listeners->addListener(UserRegistered::class, $bridge);
        $listeners->addListener(UserRegistered::class, [$admin, 'onUserRegistered']);

        return $listeners;
    }

    private function replayer(Connection $connection, EventDispatcher $listeners, bool $expectsRollback = false): OutboxEventDispatcher
    {
        $manager = $expectsRollback ? $this->createMock(EntityManagerInterface::class) : $this->createStub(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        $manager->method('isOpen')->willReturn(true);
        if ($expectsRollback) {
            self::assertInstanceOf(\PHPUnit\Framework\MockObject\MockObject::class, $manager);
            $manager->expects($this->once())->method('clear');
        }
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($manager);

        return new OutboxEventDispatcher($registry, $listeners);
    }

    private function assertCounts(Connection $connection, int $notifications, int $intents, int $receipts): void
    {
        self::assertSame($notifications, (int) $connection->fetchOne('SELECT COUNT(*) FROM replay_notifications'));
        self::assertSame($intents, (int) $connection->fetchOne('SELECT COUNT(*) FROM domain_event_outbox_delivery'));
        self::assertSame($receipts, (int) $connection->fetchOne('SELECT COUNT(*) FROM domain_event_outbox_receipt'));
    }

    private function event(): UserRegistered
    {
        return new UserRegistered(Uuid::v4(), PublicId::fromString('bbbbbbbbbbbbbbbbbbbbb'), Email::fromString('replay@baander.app'), 'Replay user');
    }
}
