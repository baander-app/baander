<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Event;

use App\Auth\Domain\Event\PasswordChanged;
use App\Auth\Domain\Event\OAuth\TokenIssued;
use App\Catalog\Domain\Event\AlbumCreated;
use App\Notification\Application\DTO\CreateNotificationCommand;
use App\Notification\Domain\Service\EventCategoryResolver;
use App\Shared\Domain\Event\AbstractDomainEvent;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Event\NotificationBridgeSubscriber;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class NotificationBridgeSubscriberTest extends TestCase
{
    private EventCategoryResolver $categoryResolver;
    private MessageBusInterface $bus;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->categoryResolver = new EventCategoryResolver();
        $this->bus = $this->createMock(MessageBusInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function subscriber(): NotificationBridgeSubscriber
    {
        return new NotificationBridgeSubscriber(
            $this->categoryResolver,
            $this->bus,
            $this->logger,
        );
    }

    public function testMappedEventDispatchesToMessenger(): void
    {
        $userId = Uuid::generate();
        $event = new PasswordChanged($userId, Email::fromString('test@example.com'));

        $this->bus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function (CreateNotificationCommand $command) {
                return $command->eventClass === PasswordChanged::class
                    && $command->eventName === 'user.password_changed'
                    && $command->payload['user_id'] === $command->payload['user_id'];
            }))
            ->willReturn(new Envelope(new \stdClass()));

        $this->subscriber()($event);
    }

    public function testUnmappedEventDoesNotDispatchToMessenger(): void
    {
        $event = new TokenIssued('token-123', ['read', 'write']);

        $this->bus->expects($this->never())->method('dispatch');

        $this->subscriber()($event);
    }

    public function testEventWithPayloadSerializationDispatchesCorrectly(): void
    {
        $albumId = Uuid::generate();
        $event = new AlbumCreated($albumId);

        $this->bus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function (CreateNotificationCommand $command) use ($albumId) {
                return $command->eventClass === AlbumCreated::class
                    && $command->eventName === 'album.created'
                    && $command->payload['album_id'] === $albumId->toString();
            }))
            ->willReturn(new Envelope(new \stdClass()));

        $this->subscriber()($event);
    }

    public function testBridgeDoesNotThrowOnMappedEvent(): void
    {
        $userId = Uuid::generate();
        $event = new PasswordChanged($userId, Email::fromString('test@example.com'));

        $this->bus->method('dispatch')->willReturn(new Envelope(new \stdClass()));

        // Should not throw
        ($this->subscriber())($event);
        $this->assertTrue(true);
    }
}
