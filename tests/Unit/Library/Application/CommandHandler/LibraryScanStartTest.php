<?php

declare(strict_types=1);

namespace App\Tests\Unit\Library\Application\CommandHandler;

use App\Library\Application\Command\ScanAllLibrariesCommand;
use App\Library\Application\Command\ScanLibraryCommand;
use App\Library\Application\Command\StartLibraryScanCommand;
use App\Library\Application\CommandHandler\ScanAllLibrariesHandler;
use App\Library\Application\CommandHandler\StartLibraryScanHandler;
use App\Library\Application\DTO\LibraryScanClaim;
use App\Library\Application\Exception\LibraryScanAlreadyRunningException;
use App\Library\Application\Service\LibraryLookup;
use App\Library\Application\Service\LibraryScanClaims;
use App\Library\Domain\Model\Library;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Shared\Domain\ValueObject\FilesystemType;
use App\Tests\Fixtures\Library\InMemoryLibraryRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/** The web starts scans through the claim, hands the claim to the queued scan, and gives it back when it cannot queue the scan. */
final class LibraryScanStartTest extends TestCase
{
    private MockClock $clock;
    private InMemoryLibraryRepository $libraries;
    /** @var list<ScanLibraryCommand> */
    private array $queued = [];

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-10-08 12:00:00 UTC');
        $this->libraries = new InMemoryLibraryRepository($this->clock);
    }

    public function testAStartQueuesTheScanOfTheClaimedLibraryWithItsClaim(): void
    {
        $music = $this->library('music');

        $started = (new StartLibraryScanHandler($this->claims(), $this->bus()))(new StartLibraryScanCommand('music', rescan: true));

        self::assertSame($music, $started);
        self::assertSame('scanning', $music->getDiscoveryStatus());
        self::assertCount(1, $this->queued);
        self::assertSame('music', $this->queued[0]->getLibrarySlug()->toString());
        self::assertTrue($this->queued[0]->isRescan());
        self::assertSame($this->libraries->claimOf($music), $this->queued[0]->getClaimId()?->toString());
    }

    public function testAStartOnAClaimedLibraryIsAConflictAndQueuesNothing(): void
    {
        $music = $this->library('music');
        $this->claims()->claim('music');

        try {
            (new StartLibraryScanHandler($this->claims(), $this->bus()))(new StartLibraryScanCommand($music->getId()->toString()));
            self::fail('The second start must conflict.');
        } catch (LibraryScanAlreadyRunningException $conflict) {
            self::assertSame(['reason' => 'scan_in_progress'], $conflict->details);
        }
        self::assertSame([], $this->queued);
    }

    public function testAScanThatCannotBeQueuedReleasesItsClaim(): void
    {
        $music = $this->library('music');

        try {
            (new StartLibraryScanHandler($this->claims(), $this->bus(failing: true)))(new StartLibraryScanCommand('music'));
            self::fail('The queueing failure must propagate.');
        } catch (\RuntimeException $failure) {
            self::assertSame('transport down', $failure->getMessage());
        }
        self::assertNull($this->libraries->claimOf($music));
        self::assertSame('failed', $music->getDiscoveryStatus());
    }

    public function testALostQueuedScanBlocksNewScansOnlyUntilItsLeaseLapses(): void
    {
        $music = $this->library('music');
        $start = new StartLibraryScanHandler($this->claims(), $this->bus());
        // The queued scan is lost, say in a server restart; nothing renews or ends its claim.
        $start(new StartLibraryScanCommand('music'));
        $lost = $this->libraries->claimOf($music);

        $this->clock->sleep(LibraryScanClaims::DEFAULT_LEASE_SECONDS - 1);
        try {
            $start(new StartLibraryScanCommand('music'));
            self::fail('A live claim must refuse the start.');
        } catch (LibraryScanAlreadyRunningException) {
        }

        $this->clock->sleep(1);
        $start(new StartLibraryScanCommand('music'));

        self::assertCount(2, $this->queued);
        self::assertNotSame($lost, $this->libraries->claimOf($music));
        self::assertSame($this->libraries->claimOf($music), $this->queued[1]->getClaimId()?->toString());
    }

    public function testScanAllSkipsClaimedLibrariesAndQueuesTheOthersWithTheirClaims(): void
    {
        $this->library('busy');
        $idle = $this->library('idle');
        $this->claims()->claim('busy');

        $result = (new ScanAllLibrariesHandler($this->claims(), $this->bus()))(new ScanAllLibrariesCommand());

        self::assertSame(['idle'], array_map(static fn (LibraryScanClaim $claim): string => $claim->library->getSlug()->toString(), $result->claimed));
        self::assertSame(['busy'], array_map(static fn (Library $library): string => $library->getSlug()->toString(), $result->skipped));
        self::assertCount(1, $this->queued);
        self::assertSame('idle', $this->queued[0]->getLibrarySlug()->toString());
        self::assertSame($this->libraries->claimOf($idle), $this->queued[0]->getClaimId()?->toString());
    }

    public function testScanAllReleasesTheClaimsOfScansItCouldNotQueue(): void
    {
        $first = $this->library('first');
        $second = $this->library('second');

        try {
            (new ScanAllLibrariesHandler($this->claims(), $this->bus(failing: true)))(new ScanAllLibrariesCommand());
            self::fail('The queueing failure must propagate.');
        } catch (\RuntimeException) {
        }

        self::assertNull($this->libraries->claimOf($first));
        self::assertNull($this->libraries->claimOf($second));
    }

    private function library(string $slug): Library
    {
        return $this->libraries->add(Library::create(ucfirst($slug), new LibrarySlug($slug), new LibraryPath('/media/' . $slug), LibraryType::Music, FilesystemType::Local));
    }

    private function claims(): LibraryScanClaims
    {
        return new LibraryScanClaims(new LibraryLookup($this->libraries), $this->libraries, $this->clock);
    }

    private function bus(bool $failing = false): MessageBusInterface
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message) use ($failing): Envelope {
            if ($failing) {
                throw new \RuntimeException('transport down');
            }
            self::assertInstanceOf(ScanLibraryCommand::class, $message);
            $this->queued[] = $message;

            return new Envelope($message);
        });

        return $bus;
    }
}
