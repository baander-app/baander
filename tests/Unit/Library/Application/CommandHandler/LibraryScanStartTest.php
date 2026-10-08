<?php

declare(strict_types=1);

namespace App\Tests\Unit\Library\Application\CommandHandler;

use App\Library\Application\Command\ScanAllLibrariesCommand;
use App\Library\Application\Command\ScanLibraryCommand;
use App\Library\Application\Command\StartLibraryScanCommand;
use App\Library\Application\CommandHandler\ScanAllLibrariesHandler;
use App\Library\Application\CommandHandler\StartLibraryScanHandler;
use App\Library\Application\Exception\LibraryScanAlreadyRunningException;
use App\Library\Application\Service\LibraryLookup;
use App\Library\Application\Service\LibraryScanClaims;
use App\Library\Domain\Model\Library;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\FilesystemType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/** The web starts scans through the claim and gives the claim back when it cannot queue the scan (KTD13). */
final class LibraryScanStartTest extends TestCase
{
    /** @var array<string, Library> */
    private array $libraries = [];
    /** @var array<string, bool> library ID => claimed */
    private array $claims = [];
    /** @var list<string> */
    private array $released = [];
    /** @var list<string> */
    private array $queued = [];

    public function testAStartQueuesTheScanOfTheClaimedLibrary(): void
    {
        $music = $this->library('music');

        $started = (new StartLibraryScanHandler($this->claims(), $this->bus()))(new StartLibraryScanCommand('music', rescan: true));

        self::assertSame($music, $started);
        self::assertSame(['music rescan'], $this->queued);
        self::assertTrue($this->claims[$music->getId()->toString()]);
    }

    public function testAStartOnAClaimedLibraryIsAConflictAndQueuesNothing(): void
    {
        $music = $this->library('music');
        $this->claims[$music->getId()->toString()] = true;

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
        self::assertSame([$music->getId()->toString()], $this->released);
    }

    public function testScanAllSkipsClaimedLibrariesAndQueuesTheOthers(): void
    {
        $busy = $this->library('busy');
        $this->library('idle');
        $this->claims[$busy->getId()->toString()] = true;

        $result = (new ScanAllLibrariesHandler($this->claims(), $this->bus()))(new ScanAllLibrariesCommand());

        self::assertSame(['idle'], array_map(static fn (Library $library): string => $library->getSlug()->toString(), $result->claimed));
        self::assertSame(['busy'], array_map(static fn (Library $library): string => $library->getSlug()->toString(), $result->skipped));
        self::assertSame(['idle'], $this->queued);
    }

    private function library(string $slug): Library
    {
        $library = Library::create(ucfirst($slug), new LibrarySlug($slug), new LibraryPath('/media/' . $slug), LibraryType::Music, FilesystemType::Local);
        $this->libraries[$library->getId()->toString()] = $library;

        return $library;
    }

    private function claims(): LibraryScanClaims
    {
        $repository = $this->createStub(LibraryRepositoryInterface::class);
        $byId = fn (Uuid $id): ?Library => $this->libraries[$id->toString()] ?? null;
        $repository->method('findVisibleByUuid')->willReturnCallback($byId);
        $repository->method('findByUuid')->willReturnCallback($byId);
        $repository->method('findVisibleBySlug')->willReturnCallback(fn (LibrarySlug $slug): ?Library => array_find(
            $this->libraries,
            static fn (Library $library): bool => $library->getSlug()->toString() === $slug->toString(),
        ));
        $repository->method('findAllOrdered')->willReturnCallback(fn (): array => array_values($this->libraries));
        $repository->method('claimScan')->willReturnCallback(function (Uuid $id): bool {
            if ($this->claims[$id->toString()] ?? false) {
                return false;
            }

            return $this->claims[$id->toString()] = true;
        });
        $repository->method('releaseScanClaim')->willReturnCallback(function (Uuid $id): bool {
            $this->released[] = $id->toString();
            $this->claims[$id->toString()] = false;

            return true;
        });

        return new LibraryScanClaims(new LibraryLookup($repository), $repository);
    }

    private function bus(bool $failing = false): MessageBusInterface
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message) use ($failing): Envelope {
            if ($failing) {
                throw new \RuntimeException('transport down');
            }
            self::assertInstanceOf(ScanLibraryCommand::class, $message);
            $this->queued[] = $message->getLibrarySlug()->toString() . ($message->isRescan() ? ' rescan' : '');

            return new Envelope($message);
        });

        return $bus;
    }
}
