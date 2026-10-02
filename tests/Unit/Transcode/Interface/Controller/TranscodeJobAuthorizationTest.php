<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Interface\Controller;

use App\Transcode\Application\Command\CleanupOrphanedJobsCommand;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
use App\Transcode\Interface\Controller\TranscodeJobController;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class TranscodeJobAuthorizationTest extends TestCase
{
    public function testCleanupDeniesNonAdministratorBeforeDispatch(): void
    {
        $security = $this->createMock(Security::class);
        $security->expects($this->once())->method('isGranted')->with('ROLE_ADMIN')->willReturn(false);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');
        $controller = new TranscodeJobController($bus, $this->createStub(TranscodeJobPortInterface::class), $security);

        self::assertSame(403, $controller->cleanup()->getStatusCode());
    }

    public function testAdministratorCanCleanup(): void
    {
        $security = $this->createMock(Security::class);
        $security->expects($this->once())->method('isGranted')->with('ROLE_ADMIN')->willReturn(true);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')->with($this->isInstanceOf(CleanupOrphanedJobsCommand::class))
            ->willReturn(new Envelope(new CleanupOrphanedJobsCommand(), [new HandledStamp(3, 'cleanup')]));
        $controller = new TranscodeJobController($bus, $this->createStub(TranscodeJobPortInterface::class), $security);

        self::assertSame(['data' => ['cleaned' => 3]], json_decode($controller->cleanup()->getContent(), true, flags: JSON_THROW_ON_ERROR));
    }
}
