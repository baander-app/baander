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
}
