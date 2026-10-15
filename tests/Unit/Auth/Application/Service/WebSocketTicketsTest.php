<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\Service;

use App\Auth\Application\DTO\StoredWebSocketTicket;
use App\Auth\Application\DTO\WebSocketIdentity;
use App\Auth\Application\Exception\WebSocketOriginRejectedException;
use App\Auth\Application\Exception\WebSocketTicketRejectedException;
use App\Auth\Application\Port\WebSocketTicketStoreInterface;
use App\Auth\Application\Port\WebSocketTokenStatusInterface;
use App\Auth\Application\Service\WebSocketOriginPolicy;
use App\Auth\Application\Service\WebSocketTickets;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

final class WebSocketTicketsTest extends TestCase
{
    private const string ORIGIN = 'https://baander.app';

    public function testIssuanceIsRandomAndCappedByThirtySecondsAndTheTokenExpiry(): void
    {
        $now = new \DateTimeImmutable('2026-10-15T12:00:00Z');
        foreach (['+1 hour' => '+30 seconds', '+4 seconds' => '+4 seconds'] as $expiry => $expected) {
            $store = $this->createMock(WebSocketTicketStoreInterface::class);
            $store->expects($this->once())->method('put')->with(
                self::matchesRegularExpression('/\A[0-9a-f]{64}\z/'),
                self::callback(static fn(StoredWebSocketTicket $record): bool => $record->identity->userId === 'user'
                    && $record->identity->accessTokenId === 'token' && $record->origin === self::ORIGIN
                    && $record->path === '/api/ws' && $record->expiresAt == $now->modify($expected)),
            );
            $tokens = $this->createStub(WebSocketTokenStatusInterface::class);
            $tokens->method('expiresAt')->willReturn($now->modify($expiry));
            $ticket = $this->service($store, $tokens, $now)->issue('user', 'token', self::ORIGIN);
            self::assertEquals($now->modify($expected), $ticket->expiresAt);
        }
    }

    public function testForeignOriginDoesNotReadAuthorizationOrStoreATicket(): void
    {
        $store = $this->createMock(WebSocketTicketStoreInterface::class);
        $store->expects($this->never())->method('put');
        $tokens = $this->createMock(WebSocketTokenStatusInterface::class);
        $tokens->expects($this->never())->method('expiresAt');
        $this->expectException(WebSocketOriginRejectedException::class);
        $this->service($store, $tokens)->issue('user', 'token', 'https://foreign.baander.app');
    }

    public function testInvalidIdentityNeverReceivesATicket(): void
    {
        $store = $this->createMock(WebSocketTicketStoreInterface::class);
        $store->expects($this->never())->method('put');
        $tokens = $this->createStub(WebSocketTokenStatusInterface::class);
        $tokens->method('expiresAt')->willReturn(null);
        $this->expectException(WebSocketTicketRejectedException::class);
        $this->service($store, $tokens)->issue('user', 'token', self::ORIGIN);
    }

    public function testMalformedTicketOriginAndPathDoNotConsumeARecord(): void
    {
        $store = $this->createMock(WebSocketTicketStoreInterface::class);
        $store->expects($this->never())->method('consume');
        $tokens = $this->createMock(WebSocketTokenStatusInterface::class);
        $tokens->expects($this->never())->method('expiresAt');
        $service = $this->service($store, $tokens);
        self::assertNull($service->redeem('invalid', self::ORIGIN, '/api/ws'));
        self::assertNull($service->redeem(str_repeat('a', 64), 'https://foreign.baander.app', '/api/ws'));
        self::assertNull($service->redeem(str_repeat('a', 64), self::ORIGIN, '/api/other'));
    }

    public function testRedemptionRechecksAuthorizationInsteadOfTrustingStoredIdentity(): void
    {
        $now = new \DateTimeImmutable('2026-10-15T12:00:00Z');
        $identity = new WebSocketIdentity('user', 'token');
        $store = $this->createStub(WebSocketTicketStoreInterface::class);
        $store->method('consume')->willReturn(new StoredWebSocketTicket($identity, self::ORIGIN, '/api/ws', $now->modify('+30 seconds')));
        $tokens = $this->createMock(WebSocketTokenStatusInterface::class);
        $tokens->expects($this->once())->method('expiresAt')->with('user', 'token')->willReturn(null);
        self::assertNull($this->service($store, $tokens, $now)->redeem(str_repeat('a', 64), self::ORIGIN, '/api/ws'));
    }

    public function testExpiredRecordIsRejectedEvenIfStorageReturnsIt(): void
    {
        $now = new \DateTimeImmutable('2026-10-15T12:00:00Z');
        $store = $this->createStub(WebSocketTicketStoreInterface::class);
        $store->method('consume')->willReturn(new StoredWebSocketTicket(new WebSocketIdentity('user', 'token'), self::ORIGIN, '/api/ws', $now));
        $tokens = $this->createMock(WebSocketTokenStatusInterface::class);
        $tokens->expects($this->never())->method('expiresAt');
        self::assertNull($this->service($store, $tokens, $now)->redeem(str_repeat('a', 64), self::ORIGIN, '/api/ws'));
    }

    private function service(WebSocketTicketStoreInterface $store, WebSocketTokenStatusInterface $tokens, ?\DateTimeImmutable $now = null): WebSocketTickets
    {
        $clock = new class($now ?? new \DateTimeImmutable()) implements ClockInterface {
            public function __construct(private \DateTimeImmutable $now) {}
            public function now(): \DateTimeImmutable { return $this->now; }
        };

        return new WebSocketTickets($store, $tokens, new WebSocketOriginPolicy(self::ORIGIN), $clock);
    }
}
