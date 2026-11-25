<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notification\Infrastructure\Push;

use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Notification\Application\DTO\PushSubscriptionRegistration;
use App\Notification\Application\DTO\PushSubscriptionRegistrationResult;
use App\Notification\Application\DTO\SendPushCommand;
use App\Notification\Application\DTO\SendPushToSubscriptionCommand;
use App\Notification\Application\Handler\SendPushHandler;
use App\Notification\Application\Handler\SendPushToSubscriptionHandler;
use App\Notification\Application\Port\PushSubscriptionRegistrationPortInterface;
use App\Notification\Domain\Repository\NotificationPreferenceRepositoryInterface;
use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Notification\Infrastructure\Doctrine\Entity\PushSubscriptionEntity;
use App\Notification\Infrastructure\Push\PushSubscriptionRepositoryInterface;
use App\Notification\Infrastructure\Push\WebPushDelivery;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\SubscriptionInterface;
use Minishlink\WebPush\WebPush;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class PushSubscriptionRegistrationRepositoryTest extends TestCase
{
    public function testOwnerRotationPreservesForeignRowsAndDeliveryUsesPersistedNewKeys(): void
    {
        $owner = new UserEntity(new PublicId(), 'Owner', 'push-register-owner@baander.app', 'test-only');
        $other = new UserEntity(new PublicId(), 'Other', 'push-register-other@baander.app', 'test-only');
        $this->entityManager->persist($owner);
        $this->entityManager->persist($other);
        $this->entityManager->flush();
        $port = static::getContainer()->get(PushSubscriptionRegistrationPortInterface::class);
        $endpoint = 'https://push.baander.app/register';
        $foreignEndpoint = 'https://push.baander.app/foreign';
        $this->assertSame(PushSubscriptionRegistrationResult::Created, $port->registerForUser($owner->getId(),
            new PushSubscriptionRegistration($endpoint, 'old-key', 'old-auth', 'aes128gcm', 'old-agent')));
        $this->assertSame(PushSubscriptionRegistrationResult::Created, $port->registerForUser($other->getId(),
            new PushSubscriptionRegistration($foreignEndpoint, 'foreign-key', 'foreign-auth', 'aes128gcm')));
        $connection = $this->entityManager->getConnection();
        $before = $connection->fetchAssociative('SELECT * FROM push_subscriptions WHERE endpoint = :endpoint', ['endpoint' => $endpoint]);
        $this->assertIsArray($before);
        $managed = $this->entityManager->find(PushSubscriptionEntity::class, Uuid::fromString($before['id']));
        $this->assertNotNull($managed);
        $this->assertSame(PushSubscriptionRegistrationResult::Conflict, $port->registerForUser($other->getId(),
            new PushSubscriptionRegistration($endpoint, 'stolen-key', 'stolen-auth', 'aesgcm', 'stolen-agent')));
        $this->assertTrue($this->entityManager->contains($managed));
        $this->assertSame($before, $connection->fetchAssociative('SELECT * FROM push_subscriptions WHERE endpoint = :endpoint', ['endpoint' => $endpoint]));
        $this->assertSame(PushSubscriptionRegistrationResult::Updated, $port->registerForUser($owner->getId(),
            new PushSubscriptionRegistration($endpoint, 'new-key', 'new-auth', 'aesgcm', 'new-agent')));
        $this->assertFalse($this->entityManager->contains($managed));
        $this->assertSame('foreign-key', $connection->fetchOne('SELECT public_key FROM push_subscriptions WHERE endpoint = :endpoint', ['endpoint' => $foreignEndpoint]));

        $preferences = $this->createStub(NotificationPreferenceRepositoryInterface::class);
        $preferences->method('isEnabled')->willReturn(true);
        $webPush = $this->createMock(WebPush::class);
        $webPush->expects($this->once())->method('sendOneNotification')->willReturnCallback(
            function (SubscriptionInterface $subscription, string $payload) use ($endpoint): MessageSentReport {
                $this->assertSame($endpoint, $subscription->getEndpoint());
                $this->assertSame('new-key', $subscription->getPublicKey());
                $this->assertSame('new-auth', $subscription->getAuthToken());
                $this->assertSame('aesgcm', $subscription->getContentEncoding());
                $this->assertSame('Title', json_decode($payload, true, flags: JSON_THROW_ON_ERROR)['title']);
                return new MessageSentReport(new Request('POST', $endpoint), new Response(201));
            },
        );
        $subscriptions = static::getContainer()->get(PushSubscriptionRepositoryInterface::class);
        $settings = static::getContainer()->get(SystemSettingsPortInterface::class);
        $queued = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')->willReturnCallback(static function (object $message) use (&$queued): Envelope {
            $queued[] = $message;

            return new Envelope($message);
        });
        $delivery = new WebPushDelivery($subscriptions, $webPush);
        (new SendPushHandler($preferences, $delivery, $bus, new NullLogger(), $settings))(
            new SendPushCommand($owner->getId(), NotificationCategory::Security, 'Title', 'Body', 'rotation'),
        );
        $this->assertCount(1, $queued);
        $this->assertInstanceOf(SendPushToSubscriptionCommand::class, $queued[0]);
        (new SendPushToSubscriptionHandler($delivery, new NullLogger(), 'baander.app', new JsonEncoder(), $settings))($queued[0]);
        $after = $connection->fetchAssociative('SELECT * FROM push_subscriptions WHERE endpoint = :endpoint', ['endpoint' => $endpoint]);
        $this->assertIsArray($after);
        $this->assertSame($before['id'], $after['id']);
        $this->assertSame($before['created_at'], $after['created_at']);
        $this->assertSame('new-agent', $after['user_agent']);
    }

    public function testThe21stRegistrationEvictsTheLongestRegisteredSubscriptionsAndDeliveryListsTheNewest(): void
    {
        $owner = new UserEntity(new PublicId(), 'Owner', 'push-cap-owner@baander.app', 'test-only');
        $other = new UserEntity(new PublicId(), 'Other', 'push-cap-other@baander.app', 'test-only');
        $this->entityManager->persist($owner);
        $this->entityManager->persist($other);
        $this->entityManager->flush();
        $connection = $this->entityManager->getConnection();
        // 22 stored before the cap existed, oldest first; the other user's row is out of reach.
        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO push_subscriptions (id, user_id, endpoint, public_key, auth_key, content_encoding, created_at)
                SELECT gen_random_uuid(), ?, 'https://push.baander.app/stored-' || i, 'key', 'auth', 'aes128gcm',
                       now() - make_interval(hours => 23 - i)
                FROM generate_series(1, 22) AS i
                SQL,
            [$owner->getId()->toString()],
        );
        $connection->executeStatement(
            "INSERT INTO push_subscriptions (id, user_id, endpoint, public_key, auth_key, content_encoding, created_at) VALUES (gen_random_uuid(), ?, 'https://push.baander.app/other', 'key', 'auth', 'aes128gcm', now() - interval '30 days')",
            [$other->getId()->toString()],
        );
        $subscriptions = static::getContainer()->get(PushSubscriptionRepositoryInterface::class);
        $this->assertSame(
            array_map(static fn (int $i): string => 'https://push.baander.app/stored-' . $i, range(22, 3, -1)),
            array_map(static fn (PushSubscriptionEntity $entity): string => $entity->getEndpoint(), $subscriptions->findByUser($owner->getId())),
            'Delivery lists the newest subscriptions up to the cap.',
        );
        $port = static::getContainer()->get(PushSubscriptionRegistrationPortInterface::class);

        $this->assertSame(PushSubscriptionRegistrationResult::Updated, $port->registerForUser($owner->getId(),
            new PushSubscriptionRegistration('https://push.baander.app/stored-1', 'rotated', 'auth', 'aes128gcm')));
        $this->assertSame(22, (int) $connection->fetchOne('SELECT count(*) FROM push_subscriptions WHERE user_id = ?', [$owner->getId()->toString()]));

        $this->assertSame(PushSubscriptionRegistrationResult::Created, $port->registerForUser($owner->getId(),
            new PushSubscriptionRegistration('https://push.baander.app/newest', 'key', 'auth', 'aes128gcm')));

        $kept = $connection->fetchFirstColumn('SELECT endpoint FROM push_subscriptions WHERE user_id = ? ORDER BY created_at, id', [$owner->getId()->toString()]);
        $this->assertSame(
            [...array_map(static fn (int $i): string => 'https://push.baander.app/stored-' . $i, range(4, 22)), 'https://push.baander.app/newest'],
            $kept,
        );
        $this->assertSame(1, (int) $connection->fetchOne('SELECT count(*) FROM push_subscriptions WHERE user_id = ?', [$other->getId()->toString()]));
    }
}
