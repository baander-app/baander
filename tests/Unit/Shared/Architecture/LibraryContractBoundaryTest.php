<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Architecture;

use PHPUnit\Framework\TestCase;

final class LibraryContractBoundaryTest extends TestCase
{
    use AnalysesDeptracFixtures;

    public function testCatalogMayUseLibraryContractsButNotOtherLibraryApplicationClasses(): void
    {
        $violations = self::deptracViolations(<<<'SOURCE'
<?php
namespace App\Library\Application\Port;
interface LibraryContentStatsInterface {}
namespace App\Library\Application\Message;
final class FilesDiscovered {}
final class DiscoveredFile {}
namespace App\Library\Application;
final class InternalApplicationService {}
namespace App\Catalog\Application\CommandHandler;
final class BoundaryFilesHandler {
    public function handle(\App\Library\Application\Message\FilesDiscovered $message, \App\Library\Application\Message\DiscoveredFile $file): void {}
    public function internal(\App\Library\Application\InternalApplicationService $service): void {}
}
namespace App\Catalog\Infrastructure\Doctrine\Query;
final class BoundaryStatsQuery implements \App\Library\Application\Port\LibraryContentStatsInterface {}
SOURCE);

        self::assertSame(
            ['App\Catalog\Application\CommandHandler\BoundaryFilesHandler must not depend on App\Library\Application\InternalApplicationService'],
            $violations,
        );
    }

    public function testCatalogApplicationMayUseTheProvisioningContractButNotOtherLibraryApplicationClasses(): void
    {
        $violations = self::deptracViolations(<<<'SOURCE'
<?php
namespace App\Library\Application\Message;
final class FilesDiscovered {}
namespace App\Library\Application\Port;
interface LibraryProvisioningInterface {
    public function provisionMovieLibrary(string $name, string $slug, string $path): ProvisionedLibraryScan;
}
final class ProvisionedLibraryScan {
    public function __construct(public \App\Library\Application\Message\FilesDiscovered $discovery) {}
}
namespace App\Library\Application;
final class InternalProvisioningHandler {}
final class BoundaryProvisioner implements \App\Library\Application\Port\LibraryProvisioningInterface {
    public function provisionMovieLibrary(string $name, string $slug, string $path): \App\Library\Application\Port\ProvisionedLibraryScan {}
}
namespace App\Catalog\Application\Service;
final class BoundaryIngest {
    public function ingest(\App\Library\Application\Port\LibraryProvisioningInterface $provisioning): \App\Library\Application\Port\ProvisionedLibraryScan {}
    public function internal(\App\Library\Application\InternalProvisioningHandler $handler): void {}
}
SOURCE);

        self::assertSame(
            ['App\Catalog\Application\Service\BoundaryIngest must not depend on App\Library\Application\InternalProvisioningHandler'],
            $violations,
        );
    }
}
