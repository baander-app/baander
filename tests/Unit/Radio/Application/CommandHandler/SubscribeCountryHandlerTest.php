<?php

declare(strict_types=1);

namespace App\Tests\Unit\Radio\Application\CommandHandler;

use App\Radio\Application\Command\SubscribeCountryCommand;
use App\Radio\Application\CommandHandler\SubscribeCountryHandler;
use App\Radio\Application\Port\CountrySubscriptionPortInterface;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class SubscribeCountryHandlerTest extends TestCase
{
    private CountrySubscriptionPortInterface&MockObject $subscriptionPort;
    private SubscribeCountryHandler $handler;

    protected function setUp(): void
    {
        $this->subscriptionPort = $this->createMock(CountrySubscriptionPortInterface::class);
        $this->handler = new SubscribeCountryHandler($this->subscriptionPort);
    }

    public function testSubscribeCallsPortAndReturnsResult(): void
    {
        $userId = Uuid::v7();
        $sourceId = Uuid::v7();
        $countryCode = 'DE';

        $expectedResult = [
            'id' => Uuid::v7()->toString(),
            'userId' => $userId->toString(),
            'sourceId' => $sourceId->toString(),
            'countryCode' => $countryCode,
        ];

        $this->subscriptionPort
            ->expects($this->once())
            ->method('subscribe')
            ->with(
                $this->callback(fn (Uuid $uid) => $uid->equals($userId)),
                $this->callback(fn (Uuid $sid) => $sid->equals($sourceId)),
                $this->identicalTo($countryCode),
            )
            ->willReturn($expectedResult);

        $command = new SubscribeCountryCommand($userId, $sourceId, $countryCode);
        $result = ($this->handler)($command);

        $this->assertSame($expectedResult, $result);
    }

    public function testSubscribePropagatesExceptionFromPort(): void
    {
        $userId = Uuid::v7();
        $sourceId = Uuid::v7();

        $this->subscriptionPort
            ->method('subscribe')
            ->willThrowException(new \RuntimeException('Already subscribed.'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Already subscribed.');

        $command = new SubscribeCountryCommand($userId, $sourceId, 'DE');
        ($this->handler)($command);
    }
}
