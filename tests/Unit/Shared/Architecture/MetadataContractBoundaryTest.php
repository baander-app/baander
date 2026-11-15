<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Architecture;

use PHPUnit\Framework\TestCase;

final class MetadataContractBoundaryTest extends TestCase
{
    use AnalysesDeptracFixtures;

    public function testCatalogApplicationMayHandleTheSyncsReadTheSwitchAndTheMergeAnswerButNotUseOtherMetadataApplicationClasses(): void
    {
        $violations = self::deptracViolations(<<<'SOURCE'
<?php
namespace App\Metadata\Application\Message;
final class SyncAlbumMessage {}
final class SyncArtistsMessage {}
final class SyncArtistMessage {}
namespace App\Metadata\Application\Settings;
final class MetadataSettingDefinitions {}
namespace App\Metadata\Application\DTO\MusicBrainz;
final class MusicBrainzMerged {}
namespace App\Metadata\Application;
final class InternalSyncService {}
namespace App\Catalog\Application\MessageHandler;
final class BoundaryRequestHandler {
    public function __invoke(\App\Metadata\Application\Message\SyncAlbumMessage $message): void {}
    public function artists(\App\Metadata\Application\Message\SyncArtistsMessage $message): void {}
    public function artist(\App\Metadata\Application\Message\SyncArtistMessage $message): void {}
    public function merged(\App\Metadata\Application\DTO\MusicBrainz\MusicBrainzMerged $answer): void {}
    public function settings(\App\Metadata\Application\Settings\MetadataSettingDefinitions $settings): void {}
    public function internal(\App\Metadata\Application\InternalSyncService $service): void {}
}
SOURCE);

        self::assertSame(
            ['App\Catalog\Application\MessageHandler\BoundaryRequestHandler must not depend on App\Metadata\Application\InternalSyncService'],
            $violations,
        );
    }
}
