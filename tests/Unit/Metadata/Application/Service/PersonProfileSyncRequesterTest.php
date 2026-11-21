<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Application\Service;

use App\Metadata\Application\Message\SyncPeopleMessage;
use App\Metadata\Application\Service\PersonProfileSyncRequester;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class PersonProfileSyncRequesterTest extends TestCase
{
    public function testQueuesTheBoundedPendingProfileHandler(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->with(self::isInstanceOf(SyncPeopleMessage::class))
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        new PersonProfileSyncRequester($bus, new NullLogger())->request();
    }

    public function testQueueFailureLeavesTheCommittedCreditsUsable(): void
    {
        $failure = new RuntimeException('Transport unavailable');
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException($failure);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')
            ->with(self::isString(), ['exception' => $failure]);

        new PersonProfileSyncRequester($bus, $logger)->request();
    }
}
