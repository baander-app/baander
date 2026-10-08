<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Application\CommandHandler;

use App\Library\Application\Port\LibraryPortInterface;
use App\Library\Domain\Model\Library;
use App\Shared\Domain\ValueObject\FilesystemType;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Metadata\Application\Command\SyncMetadataCommand;
use App\Metadata\Application\CommandHandler\SyncMetadataHandler;
use App\Metadata\Application\Message\SyncGenresMessage;
use App\Metadata\Application\Message\SyncLibraryMessage;
use App\Metadata\Application\MetadataSyncOrchestrator;
use App\Shared\Application\Exception\InvalidInputException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/** The sync the admin page's "Trigger Sync" and "Sync Genres" buttons and app:metadata:sync start. */
final class SyncMetadataHandlerTest extends TestCase
{
    /** @var list<object> */
    private array $dispatched = [];

    public function testWithoutASourceEveryLibraryIsSyncedAndTheLibrarySyncsAreCounted(): void
    {
        $libraries = [self::library('rock'), self::library('jazz'), self::library('audiobooks')];

        $queued = $this->handler($libraries)(new SyncMetadataCommand());

        self::assertSame(3, $queued);
        self::assertCount(3, $this->dispatched);
        foreach ($this->dispatched as $index => $message) {
            self::assertInstanceOf(SyncLibraryMessage::class, $message);
            self::assertTrue($message->libraryId->equals($libraries[$index]->getId()));
            self::assertFalse($message->forceUpdate);
            self::assertTrue($message->includeSongs);
        }
    }

    public function testTheGenreSourceDispatchesOnlyTheGenreSyncAndReportsTheJobsItQueued(): void
    {
        $queued = $this->handler([self::library('rock')], genreJobs: 42)(new SyncMetadataCommand('genres'));

        self::assertSame(42, $queued);
        self::assertCount(1, $this->dispatched);
        self::assertInstanceOf(SyncGenresMessage::class, $this->dispatched[0]);
        self::assertTrue($this->dispatched[0]->forceUpdate);
        self::assertTrue($this->dispatched[0]->includeSongs);
    }

    public function testAnUnknownSourceIsRejectedBeforeAnythingIsQueued(): void
    {
        try {
            $this->handler([self::library('rock')])(new SyncMetadataCommand('spotify'));
            self::fail('An unknown source must be rejected.');
        } catch (InvalidInputException $exception) {
            self::assertStringContainsString('"spotify"', $exception->getMessage());
        }

        self::assertSame([], $this->dispatched);
    }

    /** @param list<Library> $libraries */
    private function handler(array $libraries, int $genreJobs = 0): SyncMetadataHandler
    {
        $libraryPort = $this->createStub(LibraryPortInterface::class);
        $libraryPort->method('findAllOrdered')->willReturn($libraries);
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message) use ($genreJobs): Envelope {
            $this->dispatched[] = $message;
            $envelope = new Envelope($message);

            // SyncGenresMessage has no transport route, so its handler runs during the dispatch.
            return $message instanceof SyncGenresMessage
                ? $envelope->with(new HandledStamp($genreJobs, 'SyncGenresHandler::__invoke'))
                : $envelope;
        });

        return new SyncMetadataHandler(new MetadataSyncOrchestrator($libraryPort, $bus, new NullLogger()));
    }

    private static function library(string $slug): Library
    {
        return Library::create(ucfirst($slug), new LibrarySlug($slug), new LibraryPath('/music/' . $slug), LibraryType::Music, FilesystemType::Local);
    }
}
