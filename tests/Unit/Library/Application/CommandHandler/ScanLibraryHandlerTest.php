<?php

declare(strict_types=1);

namespace App\Tests\Unit\Library\Application\CommandHandler;

use App\Library\Application\Command\ScanLibraryCommand;
use App\Library\Application\CommandHandler\ScanLibraryHandler;
use App\Library\Application\Exception\LibraryBusyException;
use App\Library\Application\Exception\LibraryScanClaimLostException;
use App\Library\Application\LibraryDiscovery;
use App\Library\Application\Message\FilesDiscovered;
use App\Library\Application\MovieScanner;
use App\Library\Application\MusicScanner;
use App\Library\Application\Port\DirectoryScannerPortInterface;
use App\Library\Application\Service\LibraryLookup;
use App\Library\Application\Service\LibraryScanClaims;
use App\Library\Domain\Event\LibraryScanCompleted;
use App\Library\Domain\Model\Library;
use App\Library\Domain\Repository\LibraryFileIndexRepositoryInterface;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Library\Infrastructure\Scanner\MediaFile;
use App\Shared\Application\JobCancelledException;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\FilesystemType;
use App\Tests\Fixtures\Library\InMemoryLibraryRepository;
use App\Tests\Fixtures\Messaging\CancelAtCheckpoint;
use Closure;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/** A scan runs under its sender's claim, renews it as it goes, and ends it with the outcome. */
final class ScanLibraryHandlerTest extends TestCase
{
    private string $directory;
    private MockClock $clock;
    private InMemoryLibraryRepository $libraries;
    private Library $library;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-scan-handler-' . bin2hex(random_bytes(8));
        (new Filesystem())->dumpFile($this->directory . '/Fixture Movie/clip.mp4', 'fixture video bytes');
        (new Filesystem())->dumpFile($this->directory . '/Second Movie/clip.mp4', 'more fixture video bytes');
        $this->clock = new MockClock('2026-10-08 12:00:00 UTC');
        $this->libraries = new InMemoryLibraryRepository($this->clock);
        $this->library = $this->libraries->add(Library::create('Movies', new LibrarySlug('movies'), new LibraryPath($this->directory), LibraryType::Movie, FilesystemType::Local));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testPublishesDiscoveredDirectoriesBeforeMarkingTheScanCompleted(): void
    {
        $claim = $this->claims()->claim('movies');

        $result = ($this->handler())(new ScanLibraryCommand(new LibrarySlug('movies'), claimId: $claim->claimId));

        self::assertSame($this->library->getId()->toString(), $result->libraryId);
        self::assertSame('movies', $result->slug);
        self::assertSame(2, $result->filesDiscovered);
        self::assertSame(2, $result->directoriesQueued);
        self::assertSame([
            'claimed movies',
            'claimed movies',
            'published ' . $this->directory . '/Fixture Movie',
            'published ' . $this->directory . '/Second Movie',
            'completed movies',
            'event completed',
        ], $this->libraries->log);
        self::assertSame('completed', $this->library->getDiscoveryStatus());
        self::assertNull($this->libraries->claimOf($this->library));
    }

    public function testACancelledScanStopsBeforeItsNextDirectoryAndEndsItsClaim(): void
    {
        $claim = $this->claims()->claim('movies');

        try {
            ($this->handler(cancellation: new CancelAtCheckpoint(passes: 1)))(new ScanLibraryCommand(new LibrarySlug('movies'), claimId: $claim->claimId));
            self::fail('The cancellation must reach the job runner.');
        } catch (JobCancelledException) {
        }

        // The claim ends with the scan marked failed; the published directory stays queued.
        self::assertSame(['claimed movies', 'claimed movies', 'published ' . $this->directory . '/Fixture Movie', 'failed movies'], $this->libraries->log);
    }

    public function testFailedPublicationMarksTheScanFailedWithoutCompletionEvent(): void
    {
        $claim = $this->claims()->claim('movies');

        try {
            ($this->handler(publish: static fn () => throw new \RuntimeException('transport down')))(new ScanLibraryCommand(new LibrarySlug('movies'), claimId: $claim->claimId));
            self::fail('The publication failure must propagate.');
        } catch (\RuntimeException $error) {
            self::assertSame('transport down', $error->getMessage());
        }

        self::assertSame(['claimed movies', 'claimed movies', 'failed movies'], $this->libraries->log);
    }

    public function testAScanWhoseClaimAnotherScanHoldsStopsWithAConflictAndScansNothing(): void
    {
        $other = $this->claims()->claim('movies');

        try {
            ($this->handler(publish: static fn () => self::fail('Nothing may be published.')))(new ScanLibraryCommand(new LibrarySlug('movies'), claimId: new Uuid()));
            self::fail('The scan must conflict with the live claim.');
        } catch (LibraryBusyException) {
        }

        self::assertSame($other->claimId->toString(), $this->libraries->claimOf($this->library));
        self::assertSame('scanning', $this->library->getDiscoveryStatus());
    }

    public function testAQueuedScanWhoseClaimLapsedAndWasTakenOverDoesNotRunBesideTheNewScan(): void
    {
        $lost = $this->claims()->claim('movies');
        $this->clock->sleep(LibraryScanClaims::DEFAULT_LEASE_SECONDS);
        $current = $this->claims()->claim('movies');

        // The first scan starts late, after the library was claimed again.
        try {
            ($this->handler(publish: static fn () => self::fail('Nothing may be published.')))(new ScanLibraryCommand(new LibrarySlug('movies'), claimId: $lost->claimId));
            self::fail('The late scan must conflict with the live claim.');
        } catch (LibraryBusyException) {
        }

        $result = ($this->handler())(new ScanLibraryCommand(new LibrarySlug('movies'), claimId: $current->claimId));
        self::assertSame(2, $result->directoriesQueued);
    }

    public function testAQueuedScanWhoseClaimLapsedRunsWhenNoScanTookItOver(): void
    {
        $claim = $this->claims()->claim('movies');
        $this->clock->sleep(LibraryScanClaims::DEFAULT_LEASE_SECONDS * 2);

        $result = ($this->handler())(new ScanLibraryCommand(new LibrarySlug('movies'), claimId: $claim->claimId));

        self::assertSame(2, $result->directoriesQueued);
        self::assertSame('completed', $this->library->getDiscoveryStatus());
    }

    public function testARetriedScanWhoseClaimEndedClaimsTheFreeLibraryAgain(): void
    {
        // The job monitor retries the stored message of a failed scan, claim ID included.
        $claim = $this->claims()->claim('movies');
        $this->claims()->end($claim->claimId);

        $result = ($this->handler())(new ScanLibraryCommand(new LibrarySlug('movies'), claimId: $claim->claimId));

        self::assertSame(2, $result->directoriesQueued);
        self::assertSame(['claimed movies', 'failed movies', 'claimed movies'], array_slice($this->libraries->log, 0, 3));
    }

    public function testAMessageWithoutAClaimClaimsTheLibraryItself(): void
    {
        ($this->handler())(new ScanLibraryCommand(new LibrarySlug('movies')));

        self::assertSame('completed', $this->library->getDiscoveryStatus());
        self::assertSame('claimed movies', $this->libraries->log[0]);
    }

    public function testAFailedLibraryLookupEndsTheSendersClaim(): void
    {
        $claim = $this->claims()->claim('movies');
        $this->libraries->lookupFailure = new \RuntimeException('database gone');

        try {
            ($this->handler())(new ScanLibraryCommand(new LibrarySlug('movies'), claimId: $claim->claimId));
            self::fail('The lookup failure must propagate.');
        } catch (\RuntimeException $error) {
            self::assertSame('database gone', $error->getMessage());
        }

        self::assertNull($this->libraries->claimOf($this->library));
        self::assertSame('failed', $this->library->getDiscoveryStatus());
    }

    public function testTheScanRenewsItsLeaseAtMostOncePerRenewalInterval(): void
    {
        $claim = $this->claims()->claim('movies');
        // Each directory takes longer than the renewal interval.
        $slow = function (): void {
            $this->clock->sleep(LibraryScanClaims::RENEWAL_INTERVAL_SECONDS);
        };

        ($this->handler(publish: $slow))(new ScanLibraryCommand(new LibrarySlug('movies'), claimId: $claim->claimId));

        self::assertSame([
            'claimed movies',
            'claimed movies',
            'published ' . $this->directory . '/Fixture Movie',
            'renewed movies',
            'published ' . $this->directory . '/Second Movie',
            'completed movies',
            'event completed',
        ], $this->libraries->log);
    }

    public function testAScanThatLostItsClaimStopsAndLeavesTheNewClaimAlone(): void
    {
        $claim = $this->claims()->claim('movies');
        $taken = null;
        // The first directory outlasts the lease, and another scan takes the lapsed claim over.
        $stall = function () use (&$taken): void {
            if ($taken === null) {
                $this->clock->sleep(LibraryScanClaims::DEFAULT_LEASE_SECONDS);
                $taken = $this->claims()->claim('movies');
            }
        };

        try {
            ($this->handler(publish: $stall))(new ScanLibraryCommand(new LibrarySlug('movies'), claimId: $claim->claimId));
            self::fail('The scan must stop once it has lost its claim.');
        } catch (LibraryScanClaimLostException) {
        }

        self::assertNotNull($taken);
        self::assertSame($taken->claimId->toString(), $this->libraries->claimOf($this->library));
        self::assertSame('scanning', $this->library->getDiscoveryStatus());
        self::assertNotContains('published ' . $this->directory . '/Second Movie', $this->libraries->log);
    }

    private function claims(): LibraryScanClaims
    {
        return new LibraryScanClaims(new LibraryLookup($this->libraries), $this->libraries, $this->clock);
    }

    /** @param (Closure(FilesDiscovered): void)|null $publish runs as each directory is published */
    private function handler(?Closure $publish = null, CancelAtCheckpoint $cancellation = new CancelAtCheckpoint()): ScanLibraryHandler
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message) use ($publish): Envelope {
            self::assertInstanceOf(FilesDiscovered::class, $message);
            if ($publish !== null) {
                $publish($message);
            }
            $this->libraries->log[] = 'published ' . $message->directory;

            return new Envelope($message);
        });
        $events = $this->createStub(EventDispatcherInterface::class);
        $events->method('dispatch')->willReturnCallback(function (object $event): object {
            self::assertInstanceOf(LibraryScanCompleted::class, $event);
            $this->libraries->log[] = 'event completed';

            return $event;
        });
        $directoryScanner = $this->createStub(DirectoryScannerPortInterface::class);
        $directoryScanner->method('scan')->willReturn(array_map(function (string $relativePath): MediaFile {
            $path = $this->directory . '/' . $relativePath;

            return new MediaFile($path, $relativePath, 'mp4', (int) filesize($path), (int) filemtime($path));
        }, ['Fixture Movie/clip.mp4', 'Second Movie/clip.mp4']));
        $fileIndex = $this->createStub(LibraryFileIndexRepositoryInterface::class);
        $logger = new NullLogger();

        return new ScanLibraryHandler(
            $this->libraries,
            $this->claims(),
            new LibraryDiscovery(
                new MusicScanner($directoryScanner, $fileIndex, $logger, $cancellation),
                new MovieScanner($directoryScanner, $fileIndex, $logger, $cancellation),
                $events,
                $logger,
            ),
            $bus,
            $logger,
        );
    }
}
