<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notification\Infrastructure\Push;

use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Notification\Application\DTO\PushSubscriptionRegistration;
use App\Notification\Application\DTO\PushSubscriptionRegistrationResult;
use App\Notification\Application\DTO\SendPushCommand;
use App\Notification\Application\Handler\SendPushHandler;
use App\Notification\Application\Port\PushSubscriptionRegistrationPortInterface;
use App\Notification\Domain\Repository\NotificationPreferenceRepositoryInterface;
use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Notification\Infrastructure\Doctrine\Entity\PushSubscriptionEntity;
use App\Notification\Infrastructure\Push\PushSubscriptionRepositoryInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\SubscriptionInterface;
use Minishlink\WebPush\WebPush;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use Psr\Log\NullLogger;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class PushSubscriptionRegistrationRepositoryTest extends TestCase
{
    public function testOwnerRotationPreservesForeignRowsAndDeliveryUsesPersistedNewKeys(): void
    {
        $owner = new UserEntity(new PublicId(), 'Owner', 'push-register-owner@baander.app', 'test-only', '');
        $other = new UserEntity(new PublicId(), 'Other', 'push-register-other@baander.app', 'test-only', '');
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
        $handler = new SendPushHandler($preferences, static::getContainer()->get(PushSubscriptionRepositoryInterface::class),
            $webPush, new NullLogger(), 'baander.app', new JsonEncoder());
        $handler(new SendPushCommand($owner->getId(), NotificationCategory::Security, 'Title', 'Body', 'rotation'));
        $after = $connection->fetchAssociative('SELECT * FROM push_subscriptions WHERE endpoint = :endpoint', ['endpoint' => $endpoint]);
        $this->assertIsArray($after);
        $this->assertSame($before['id'], $after['id']);
        $this->assertSame($before['created_at'], $after['created_at']);
        $this->assertSame('new-agent', $after['user_agent']);
    }
}
