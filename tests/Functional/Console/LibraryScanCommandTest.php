<?php

declare(strict_types=1);

namespace App\Tests\Functional\Console;

use App\Library\Application\Message\FilesDiscovered;
use App\Library\Application\Port\LibraryPortInterface;
use App\Library\Domain\Model\Library;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Shared\Domain\ValueObject\FilesystemType;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class LibraryScanCommandTest extends KernelTestCase
{
    private string $libraryDirectory;

    protected function setUp(): void
    {
        $this->libraryDirectory = sys_get_temp_dir() . '/baander-cli-scan-' . bin2hex(random_bytes(4));
        mkdir($this->libraryDirectory . '/Album', 0o777, true);
        file_put_contents($this->libraryDirectory . '/Album/track.flac', 'not really audio');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->libraryDirectory);
        static::ensureKernelShutdown();
        parent::tearDown();
    }

    public function testScanRunsTheScanInTheConsoleProcess(): void
    {
        $kernel = self::bootKernel();
        $container = static::getContainer();
        $libraries = $container->get(LibraryPortInterface::class);
        $slug = 'cli-scan-' . bin2hex(random_bytes(4));
        $libraries->save(Library::create(
            'CLI scan',
            new LibrarySlug($slug),
            new LibraryPath($this->libraryDirectory),
            LibraryType::Music,
            FilesystemType::Local,
        ));

        $tester = new CommandTester((new Application($kernel))->find('app:library:scan'));
        $exitCode = $tester->execute(['slug' => $slug]);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        self::assertStringContainsString('Library scan completed successfully.', $tester->getDisplay());
        self::assertStringContainsString($slug, $tester->getDisplay());

        $scanned = $libraries->findBySlug(new LibrarySlug($slug));
        self::assertNotNull($scanned);
        self::assertSame('completed', $scanned->getDiscoveryStatus());

        $async = $container->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $async);
        $discovered = array_values(array_filter(
            $async->getSent(),
            static fn (Envelope $envelope): bool => $envelope->getMessage() instanceof FilesDiscovered,
        ));
        self::assertCount(1, $discovered);
    }
}
