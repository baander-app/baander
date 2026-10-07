<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Architecture;

use PHPUnit\Framework\TestCase;

final class MetadataContractBoundaryTest extends TestCase
{
    use AnalysesDeptracFixtures;

    public function testCatalogApplicationMayRequestAlbumSyncsButNotUseOtherMetadataApplicationClasses(): void
    {
        $violations = self::deptracViolations(<<<'SOURCE'
<?php
namespace App\Metadata\Application\Port;
interface AlbumMetadataSyncRequestInterface {}
namespace App\Metadata\Application;
final class InternalSyncService {}
final class BoundaryRequester implements \App\Metadata\Application\Port\AlbumMetadataSyncRequestInterface {
    public function __construct(private InternalSyncService $service) {}
}
namespace App\Catalog\Application\CommandHandler;
final class BoundaryIngestHandler {
    public function __construct(private \App\Metadata\Application\Port\AlbumMetadataSyncRequestInterface $albumSync) {}
    public function internal(\App\Metadata\Application\InternalSyncService $service): void {}
}
SOURCE);

        self::assertSame(
            ['App\Catalog\Application\CommandHandler\BoundaryIngestHandler must not depend on App\Metadata\Application\InternalSyncService'],
            $violations,
        );
    }
}
