<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Application\CommandHandler\FilesDiscoveredHandler;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Library\Application\Message\FilesDiscovered;
use App\Library\Application\Port\LibraryPortInterface;
use App\Library\Domain\Model\Library;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Metadata\Application\Message\SyncAlbumMessage;
use App\Metadata\Application\MetadataSyncOrchestrator;
use App\Metadata\Application\Settings\MetadataSettingDefinitions;
use App\Shared\Application\Port\SystemSettingStoreInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\FilesystemType;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Runs a real scan through the console, ingests what it discovered, and checks
 * which album syncs reach the swoole_task queue, with the setting in PostgreSQL.
 */
final class NewAlbumMetadataSyncTest extends KernelTestCase
{
    private string $libraryDirectory;

    protected function setUp(): void
    {
        $this->libraryDirectory = sys_get_temp_dir() . '/baander-album-sync-' . bin2hex(random_bytes(4));
        mkdir($this->libraryDirectory . '/Album', 0o777, true);
        file_put_contents($this->libraryDirectory . '/Album/track.flac', 'not really audio');
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        static::getContainer()->get(SystemSettingStoreInterface::class)->delete(MetadataSettingDefinitions::AUTO_SYNC);
        (new Filesystem())->remove($this->libraryDirectory);
        static::ensureKernelShutdown();
        parent::tearDown();
    }

    public function testIngestQueuesOneSyncForTheAlbumAScanCreatedWhileAutoSyncIsOn(): void
    {
        $this->setAutoSync(true);
        $library = $this->createLibrary();

        $this->scanAndIngest($library);

        $album = $this->onlyAlbum($library);
        self::assertSame([$album->toString()], $this->queuedAlbumSyncs());
    }

    public function testRedeliveredDiscoveryFindsTheAlbumAndQueuesNoFurtherSync(): void
    {
        $this->setAutoSync(true);
        $library = $this->createLibrary();

        $discoveries = $this->scanAndIngest($library);
        self::assertNotSame([], $discoveries);
        $ingest = static::getContainer()->get(FilesDiscoveredHandler::class);
        foreach ($discoveries as $discovery) {
            $ingest($discovery);
        }

        $album = $this->onlyAlbum($library);
        self::assertSame([$album->toString()], $this->queuedAlbumSyncs());
    }

    public function testIngestQueuesNothingWhileAutoSyncIsOffAndARequestedSyncStillRuns(): void
    {
        $this->setAutoSync(false);
        $library = $this->createLibrary();

        $this->scanAndIngest($library);
        self::assertSame([], $this->queuedAlbumSyncs());

        $album = $this->onlyAlbum($library);
        static::getContainer()->get(MetadataSyncOrchestrator::class)->syncAlbum($album->toString());
        self::assertSame([$album->toString()], $this->queuedAlbumSyncs());
    }

    private function setAutoSync(bool $enabled): void
    {
        static::getContainer()->get(SystemSettingStoreInterface::class)->save([MetadataSettingDefinitions::AUTO_SYNC => $enabled]);
    }

    private function createLibrary(): Library
    {
        $library = Library::create(
            'Album sync',
            new LibrarySlug('album-sync-' . bin2hex(random_bytes(4))),
            new LibraryPath($this->libraryDirectory),
            LibraryType::Music,
            FilesystemType::Local,
        );
        static::getContainer()->get(LibraryPortInterface::class)->save($library);

        return $library;
    }

    /**
     * Scans through the console, then hands each new discovery to the ingest handler.
     *
     * @return list<FilesDiscovered>
     */
    private function scanAndIngest(Library $library): array
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:library:scan'));
        $exitCode = $tester->execute(['library' => $library->getSlug()->toString()]);
        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());

        $async = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $async);
        $ingest = static::getContainer()->get(FilesDiscoveredHandler::class);
        $discoveries = [];
        foreach ($async->get() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof FilesDiscovered && $message->libraryId->equals($library->getId())) {
                $ingest($message);
                $discoveries[] = $message;
            }
            $async->ack($envelope);
        }

        return $discoveries;
    }

    private function onlyAlbum(Library $library): Uuid
    {
        $albums = static::getContainer()->get(AlbumPortInterface::class)->findByLibrary($library->getId());
        self::assertCount(1, $albums);

        return $albums[0]->getId();
    }

    /** @return list<string> */
    private function queuedAlbumSyncs(): array
    {
        $transport = static::getContainer()->get('messenger.transport.swoole_task');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        $syncs = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof SyncAlbumMessage) {
                $syncs[] = $message->albumId->toString();
            }
        }

        return $syncs;
    }
}
