<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notification;

use App\Auth\Domain\Event\EmailVerified;
use App\Auth\Domain\Event\PasswordChanged;
use App\Auth\Domain\Event\UserRegistered;
use App\Library\Domain\Event\LibraryScanCompleted;
use App\Shared\Infrastructure\Event\NotificationReplayDispatcher;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class NotificationReplayWiringTest extends KernelTestCase
{
    public function testCompiledReplayDispatcherProjectsNotificationsAndAdminAlertsOnly(): void
    {
        self::bootKernel();
        $dispatcher = self::getContainer()->get(NotificationReplayDispatcher::class);
        self::assertInstanceOf(NotificationReplayDispatcher::class, $dispatcher);

        // Notification projection first, then the admin alert, as replay has always ordered them.
        self::assertCount(2, $dispatcher->getListeners(UserRegistered::class));
        self::assertCount(1, $dispatcher->getListeners(PasswordChanged::class));
        self::assertCount(1, $dispatcher->getListeners(LibraryScanCompleted::class));
        self::assertSame([], $dispatcher->getListeners(EmailVerified::class));
    }
}
