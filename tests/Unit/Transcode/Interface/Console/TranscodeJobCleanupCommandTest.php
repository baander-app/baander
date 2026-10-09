<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Interface\Console;

use App\Shared\Interface\Console\AdminCommandSupport;
use App\Transcode\Application\Command\CleanupOrphanedJobsCommand;
use App\Transcode\Application\CommandHandler\CleanupOrphanedJobsHandler;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
use App\Transcode\Interface\Console\TranscodeJobCleanupCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;

final class TranscodeJobCleanupCommandTest extends TestCase
{
    public function testJsonIsTheDataTheCleanupEndpointReturns(): void
    {
        $jobs = $this->createStub(TranscodeJobPortInterface::class);
        $jobs->method('cleanupOrphanedJobs')->willReturn(3);
        $support = new AdminCommandSupport(new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            CleanupOrphanedJobsCommand::class => [new CleanupOrphanedJobsHandler($jobs)],
        ]))]));

        $tester = new CommandTester(new TranscodeJobCleanupCommand($support));

        self::assertSame(Command::SUCCESS, $tester->execute(['--json' => true]));
        self::assertSame(['cleaned' => 3], json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
    }
}
