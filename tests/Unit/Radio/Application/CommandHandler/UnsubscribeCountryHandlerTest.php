<?php

declare(strict_types=1);

namespace App\Tests\Unit\Radio\Application\CommandHandler;

use App\Radio\Application\Command\UnsubscribeCountryCommand;
use App\Radio\Application\CommandHandler\UnsubscribeCountryHandler;
use App\Radio\Application\Port\CountrySubscriptionPortInterface;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

final class UnsubscribeCountryHandlerTest extends TestCase
{
    private CountrySubscriptionPortInterface $subscriptionPort;
    private UnsubscribeCountryHandler $handler;

    protected function setUp(): void
    {
        $this->subscriptionPort = $this->createStub(CountrySubscriptionPortInterface::class);
        $this->handler = $this->createUnsubscribeCountryHandlerFixture();
    }

    private function createUnsubscribeCountryHandlerFixture(): UnsubscribeCountryHandler
    {
        $fixture = new UnsubscribeCountryHandler($this->subscriptionPort);
        return $fixture;
    }

    public function testUnsubscribeCallsPort(): void
    {
        $this->subscriptionPort = $this->createMock(CountrySubscriptionPortInterface::class);
        $this->handler = $this->createUnsubscribeCountryHandlerFixture();

        $userId = Uuid::v7();
        $sourceId = Uuid::v7();
        $countryCode = 'DE';

        $this->subscriptionPort
            ->expects($this->once())
            ->method('unsubscribe')
            ->with(
                $this->callback(fn (Uuid $uid) => $uid->equals($userId)),
                $this->callback(fn (Uuid $sid) => $sid->equals($sourceId)),
                $this->identicalTo($countryCode),
            );

        $command = new UnsubscribeCountryCommand($userId, $sourceId, $countryCode);
        ($this->handler)($command);
    }

    public function testUnsubscribePropagatesExceptionFromPort(): void
    {
        $userId = Uuid::v7();
        $sourceId = Uuid::v7();

        $this->subscriptionPort
            ->method('unsubscribe')
            ->willThrowException(new \RuntimeException('Not subscribed.'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Not subscribed.');

        $command = new UnsubscribeCountryCommand($userId, $sourceId, 'DE');
        ($this->handler)($command);
    }
}
