<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Interface\Console;

use App\Metadata\Application\Command\SyncMetadataCommand;
use App\Metadata\Application\Port\MetadataAdminPortInterface;
use App\Metadata\Interface\Console\MetadataProvidersCommand;
use App\Metadata\Interface\Console\MetadataStatusCommand;
use App\Metadata\Interface\Console\MetadataSyncCommand;
use App\Metadata\Interface\Controller\MetadataAdminController;
use App\Shared\Application\DTO\InlineJobRun;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Port\JobMonitorAdministrationInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/** The app:metadata:* commands reach what /api/admin/metadata/* reaches and print the same data. */
final class MetadataCommandsTest extends TestCase
{
    private const array STATUS = [
        'lastSyncAt' => '2026-10-08T12:00:00+00:00',
        'totalTracks' => 120,
        'syncedTracks' => 80,
        'pendingTracks' => 38,
        'failedTracks' => 2,
        'sources' => [['name' => 'SyncAlbumMessage', 'synced' => 10, 'failed' => 2]],
    ];
    private const array PROVIDERS = [
        ['name' => 'MusicBrainz', 'enabled' => true, 'configured' => true],
        ['name' => 'Discogs', 'enabled' => true, 'configured' => false],
    ];

    /** @var list<object> */
    private array $dispatched = [];

    public function testStatusJsonIsTheDataTheEndpointReturns(): void
    {
        $port = $this->port();
        $endpoint = $this->data($this->controller($port)->syncStatus());

        $tester = new CommandTester(new MetadataStatusCommand($port));
        self::assertSame(Command::SUCCESS, $tester->execute(['--json' => true]));

        self::assertSame($endpoint, json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
        self::assertSame(self::STATUS, $endpoint);
    }

    public function testStatusTableShowsCoverageAndTheJobsByType(): void
    {
        $tester = new CommandTester(new MetadataStatusCommand($this->port()));
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        self::assertStringContainsString('Tracks with genres', $tester->getDisplay());
        self::assertStringContainsString('SyncAlbumMessage', $tester->getDisplay());
    }

    public function testProvidersJsonIsTheDataTheEndpointReturns(): void
    {
        $port = $this->port();
        $endpoint = $this->data($this->controller($port)->providers());

        $tester = new CommandTester(new MetadataProvidersCommand($port));
        self::assertSame(Command::SUCCESS, $tester->execute(['--json' => true]));

        self::assertSame($endpoint, json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
        self::assertSame(self::PROVIDERS, $endpoint);
    }

    public function testSyncWithoutASourceRunsTheSameCommandAsTheWebSyncAllInline(): void
    {
        $response = $this->controller($this->port(), result: 3)->triggerSync(Request::create('/api/admin/metadata/trigger-sync', 'POST', content: '{}'));
        self::assertSame(['jobsDispatched' => 3], $this->data($response));

        $inline = [];
        $tester = new CommandTester(new MetadataSyncCommand($this->jobs($inline, 3)));
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        self::assertEquals([new SyncMetadataCommand()], $this->dispatched);
        self::assertEquals($this->dispatched, $inline);
        self::assertStringContainsString('Queuing a metadata sync for every library', $tester->getDisplay());
        self::assertStringContainsString('Queued 3 metadata sync job(s). Job ID: inline-job-1', $tester->getDisplay());
    }

    public function testGenreSourceRunsOnlyTheGenreSyncOnBothPaths(): void
    {
        $this->controller($this->port(), result: 7)->triggerSync(Request::create('/', 'POST', content: '{"source":"genres"}'));

        $inline = [];
        $tester = new CommandTester(new MetadataSyncCommand($this->jobs($inline, 7)));
        self::assertSame(Command::SUCCESS, $tester->execute(['--source' => 'genres']));

        self::assertEquals([new SyncMetadataCommand('genres')], $this->dispatched);
        self::assertEquals($this->dispatched, $inline);
        self::assertStringContainsString('Queued 7 metadata sync job(s)', $tester->getDisplay());
    }

    public function testAnUnknownSourceExitsAsInvalidInput(): void
    {
        $jobs = $this->createStub(JobMonitorAdministrationInterface::class);
        $jobs->method('runInline')->willThrowException(new InvalidInputException('Unknown metadata sync source "spotify".'));

        $tester = new CommandTester(new MetadataSyncCommand($jobs));

        self::assertSame(Command::INVALID, $tester->execute(['--source' => 'spotify']));
        self::assertStringContainsString('Unknown metadata sync source', $tester->getDisplay());
    }

    public function testTheWebRejectsANonStringSourceAsInvalidInput(): void
    {
        $this->expectException(InvalidInputException::class);

        $this->controller($this->port())->triggerSync(Request::create('/', 'POST', content: '{"source":5}'));
    }

    private function port(): MetadataAdminPortInterface
    {
        $port = $this->createStub(MetadataAdminPortInterface::class);
        $port->method('getSyncStatus')->willReturn(self::STATUS);
        $port->method('getProviders')->willReturn(self::PROVIDERS);

        return $port;
    }

    private function controller(MetadataAdminPortInterface $port, int $result = 0): MetadataAdminController
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message) use ($result): Envelope {
            $this->dispatched[] = $message;

            return (new Envelope($message))->with(new HandledStamp($result, 'handler'));
        });

        return new MetadataAdminController($port, $bus);
    }

    /** @param list<object> $inline */
    private function jobs(array &$inline, int $result): JobMonitorAdministrationInterface
    {
        $jobs = $this->createStub(JobMonitorAdministrationInterface::class);
        $jobs->method('runInline')->willReturnCallback(static function (object $message) use (&$inline, $result): InlineJobRun {
            $inline[] = $message;

            return new InlineJobRun('inline-job-1', $result);
        });

        return $jobs;
    }

    /** @return mixed the response's `data` */
    private function data(JsonResponse $response): mixed
    {
        return json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
    }
}
