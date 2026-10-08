<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Interface\Controller;

use App\Transcode\Application\Command\CleanupOrphanedJobsCommand;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
use App\Transcode\Interface\Controller\TranscodeJobController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class TranscodeJobAuthorizationTest extends TestCase
{
    public function testCleanupIsGrantedOnlyToAdministrators(): void
    {
        // The security listener denies the request before the action runs; the
        // admin/CLI parity test reads the same attribute to classify the route.
        $grants = array_map(
            static fn (\ReflectionAttribute $attribute): IsGranted => $attribute->newInstance(),
            (new \ReflectionMethod(TranscodeJobController::class, 'cleanup'))->getAttributes(IsGranted::class),
        );

        self::assertCount(1, $grants);
        self::assertSame('ROLE_ADMIN', $grants[0]->attribute);
        self::assertSame([], $grants[0]->methods);
    }

    public function testAdministratorCanCleanup(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')->with($this->isInstanceOf(CleanupOrphanedJobsCommand::class))
            ->willReturn(new Envelope(new CleanupOrphanedJobsCommand(), [new HandledStamp(3, 'cleanup')]));
        $controller = new TranscodeJobController($bus, $this->createStub(TranscodeJobPortInterface::class));

        self::assertSame(['data' => ['cleaned' => 3]], json_decode($controller->cleanup()->getContent(), true, flags: JSON_THROW_ON_ERROR));
    }
}
