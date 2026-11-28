<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Interface\Console;

use App\Catalog\Application\Service\VideoMatch\LibraryProviderOrderService;
use App\Catalog\Domain\Model\LibraryProviderOrder;
use App\Catalog\Domain\Repository\LibraryProviderOrderRepositoryInterface;
use App\Catalog\Interface\Console\VideoProviderOrderCommand;
use App\Library\Application\Port\LibraryTypeLookupInterface;
use App\Library\Domain\ValueObject\LibraryType;
use App\Metadata\Application\DTO\Video\VideoProviderId;
use App\Metadata\Application\Port\VideoMetadataPortInterface;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class VideoProviderOrderCommandTest extends TestCase
{
    public function testWithoutReplacementTheCommandReadsTheDefaultAsATable(): void
    {
        $orders = $this->createMock(LibraryProviderOrderRepositoryInterface::class);
        $orders->method('findByLibrary')->willReturn(null);
        $orders->expects(self::never())->method('save');
        $tester = new CommandTester(new VideoProviderOrderCommand($this->service($orders)));

        self::assertSame(Command::SUCCESS, $tester->execute(['library' => 'movies']));
        self::assertStringContainsString('Provider', $tester->getDisplay());
        self::assertStringContainsString('tmdb', $tester->getDisplay());
        self::assertStringContainsString('local', $tester->getDisplay());
    }

    public function testExplicitReplacementWritesTheSameJsonResourceAsTheRoute(): void
    {
        $orders = $this->createMock(LibraryProviderOrderRepositoryInterface::class);
        $orders->expects(self::once())->method('save')->willReturnCallback(static function (LibraryProviderOrder $order): void {
            self::assertSame(['local', 'tmdb'], $order->getProviderIds());
        });
        $tester = new CommandTester(new VideoProviderOrderCommand($this->service($orders)));

        self::assertSame(Command::SUCCESS, $tester->execute(['library' => 'movies', '--providers' => 'local, tmdb', '--json' => true]));
        self::assertSame(['providers' => ['local', 'tmdb']], json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testInvalidPermutationReportsExitTwoWithoutWriting(): void
    {
        $orders = $this->createMock(LibraryProviderOrderRepositoryInterface::class);
        $orders->expects(self::never())->method('save');
        $tester = new CommandTester(new VideoProviderOrderCommand($this->service($orders)));

        self::assertSame(Command::INVALID, $tester->execute(['library' => 'movies', '--providers' => 'tmdb,tmdb'], ['capture_stderr_separately' => true]));
        self::assertStringContainsString('exactly once', $tester->getErrorOutput());
        self::assertSame('', $tester->getDisplay());
    }

    public function testUnknownLibraryReportsExitOne(): void
    {
        $libraries = $this->createStub(LibraryTypeLookupInterface::class);
        $libraries->method('resolveId')->willThrowException(new NotFoundException('Library not found.'));
        $service = new LibraryProviderOrderService($libraries, $this->createStub(VideoMetadataPortInterface::class), $this->createStub(LibraryProviderOrderRepositoryInterface::class));
        $tester = new CommandTester(new VideoProviderOrderCommand($service));

        self::assertSame(Command::FAILURE, $tester->execute(['library' => 'missing'], ['capture_stderr_separately' => true]));
        self::assertStringContainsString('Library not found.', $tester->getErrorOutput());
        self::assertSame('', $tester->getDisplay());
    }

    private function service(LibraryProviderOrderRepositoryInterface $orders): LibraryProviderOrderService
    {
        $libraries = $this->createStub(LibraryTypeLookupInterface::class);
        $libraries->method('resolveId')->willReturn(Uuid::v7());
        $libraries->method('findType')->willReturn(LibraryType::Movie);
        $providers = $this->createStub(VideoMetadataPortInterface::class);
        $providers->method('providers')->willReturn([VideoProviderId::Tmdb, VideoProviderId::Local]);

        return new LibraryProviderOrderService($libraries, $providers, $orders);
    }
}
