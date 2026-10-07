<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Domain\Event;

use App\Auth\Domain\Event\OAuth\DeviceCodeApproved;
use App\Auth\Domain\Event\Passkey\PasskeyDeleted;
use App\Auth\Domain\Event\Passkey\PasskeyRegistered;
use App\Auth\Domain\Event\PasswordChanged;
use App\Auth\Domain\Event\OAuth\TokenRevoked;
use App\Auth\Domain\Event\UserRegistered;
use App\Catalog\Domain\Event\AlbumCreated;
use App\Library\Domain\Event\LibraryScanCompleted;
use App\Playlist\Domain\Event\PlaylistCreated;
use App\Playlist\Domain\Event\SmartPlaylistSynced;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that all domain events used in the notification mapping
 * have toPayload()/fromPayload() methods and userId enrichment.
 */
final class EventWiringTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable();
    }

    /**
     * @return list<array{string}>
     */
    public static function mappedEventProvider(): array
    {
        return [
            [PasswordChanged::class],
            [PasskeyRegistered::class],
            [PasskeyDeleted::class],
            [TokenRevoked::class],
            [DeviceCodeApproved::class],
            [UserRegistered::class],
            [AlbumCreated::class],
            [LibraryScanCompleted::class],
            [PlaylistCreated::class],
            [SmartPlaylistSynced::class],
        ];
    }

    /**
     * Every event that maps to a notification category must be serializable.
     */
    #[DataProvider('mappedEventProvider')]
    public function testMappedEventHasPayloadSerialization(string $eventClass): void
    {
        $this->assertTrue(
            method_exists($eventClass, 'toPayload'),
            sprintf('Event %s must have toPayload() for async notification processing.', $eventClass),
        );

        $this->assertTrue(
            method_exists($eventClass, 'fromPayload'),
            sprintf('Event %s must have fromPayload() for async notification processing.', $eventClass),
        );
    }

    public function testTokenRevokedToPayloadWithUserId(): void
    {
        $userId = Uuid::v4();
        $event = new TokenRevoked('token-123', 'access_token', $userId, $this->now);
        $payload = $event->toPayload();

        $this->assertArrayHasKey('user_id', $payload);
        $this->assertSame($userId->toString(), $payload['user_id']);
    }

    public function testTokenRevokedToPayloadWithoutUserId(): void
    {
        $event = new TokenRevoked('token-123', 'access_token', null, $this->now);
        $payload = $event->toPayload();

        $this->assertNull($payload['user_id']);

        $restored = TokenRevoked::fromPayload($payload);
        $this->assertNull($restored->getUserId());
    }

    public function testLibraryScanCompletedToPayloadAndBack(): void
    {
        $libraryId = Uuid::v4();
        $event = new LibraryScanCompleted($libraryId, 150, 120, $this->now);
        $payload = $event->toPayload();

        $this->assertSame($libraryId->toString(), $payload['library_id']);
        $this->assertSame(150, $payload['files_discovered']);
        $this->assertSame(120, $payload['files_processed']);

        $restored = LibraryScanCompleted::fromPayload($payload);
        $this->assertSame($libraryId->toString(), $restored->libraryId->toString());
        $this->assertSame(150, $restored->filesDiscovered);
        $this->assertSame(120, $restored->filesProcessed);
    }

    public function testDeviceCodeApprovedToPayloadAndBack(): void
    {
        $event = new DeviceCodeApproved('dc-123', 'user-uuid-456', $this->now);
        $payload = $event->toPayload();

        $restored = DeviceCodeApproved::fromPayload($payload);
        $this->assertSame('dc-123', $restored->getDeviceCodeId());
        $this->assertSame('user-uuid-456', $restored->getUserId());
    }

    public function testPlaylistCreatedToPayloadWithUserId(): void
    {
        $userId = Uuid::v4();
        $event = new PlaylistCreated(Uuid::v4(), 'My Playlist', false, $userId, $this->now);
        $payload = $event->toPayload();

        $this->assertSame($userId->toString(), $payload['user_id']);

        $restored = PlaylistCreated::fromPayload($payload);
        $this->assertSame($userId->toString(), $restored->getUserId()->toString());
    }

    public function testPlaylistCreatedToPayloadWithoutUserId(): void
    {
        $event = new PlaylistCreated(Uuid::v4(), 'My Playlist', false, null, $this->now);
        $payload = $event->toPayload();

        $this->assertNull($payload['user_id']);

        $restored = PlaylistCreated::fromPayload($payload);
        $this->assertNull($restored->getUserId());
    }

    public function testSmartPlaylistSyncedToPayloadWithUserId(): void
    {
        $userId = Uuid::v4();
        $event = new SmartPlaylistSynced(Uuid::v4(), 42, ['genre' => 'rock'], $userId, $this->now);
        $payload = $event->toPayload();

        $this->assertSame($userId->toString(), $payload['user_id']);

        $restored = SmartPlaylistSynced::fromPayload($payload);
        $this->assertSame($userId->toString(), $restored->getUserId()->toString());
    }

    public function testSmartPlaylistSyncedToPayloadWithoutUserId(): void
    {
        $event = new SmartPlaylistSynced(Uuid::v4(), 42, ['genre' => 'rock'], null, $this->now);
        $payload = $event->toPayload();

        $this->assertNull($payload['user_id']);

        $restored = SmartPlaylistSynced::fromPayload($payload);
        $this->assertNull($restored->getUserId());
    }
}
