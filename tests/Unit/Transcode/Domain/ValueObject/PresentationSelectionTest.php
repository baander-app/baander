<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Domain\ValueObject;

use App\Shared\Domain\Model\Uuid;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\ValueObject\PresentationSelection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PresentationSelectionTest extends TestCase
{
    public function testIdentityIncludesSourceStreamsContainerAndEncodingConfiguration(): void
    {
        $copy = new PresentationSelection('copy', 'source-hash', 2, 4, 'mp4');
        self::assertSame($copy->canonicalKey(), PresentationSelection::fromArray($copy->jsonSerialize())->canonicalKey());
        foreach ([
            new PresentationSelection('copy', 'different-source', 2, 4, 'mp4'),
            new PresentationSelection('copy', 'source-hash', 3, 4, 'mp4'),
            new PresentationSelection('copy', 'source-hash', 2, null, 'mp4'),
            new PresentationSelection('copy', 'source-hash', 2, 4, 'webm'),
            new PresentationSelection('encode', 'source-hash', 2, 4, 'mp4', 'config-one'),
            new PresentationSelection('encode', 'source-hash', 2, 4, 'mp4', 'config-two'),
        ] as $selection) {
            self::assertNotSame($copy->canonicalKey(), $selection->canonicalKey());
        }
        self::assertNotSame(
            (new PresentationSelection('encode', 'source-hash', 2, 4, 'mp4', 'config-one'))->canonicalKey(),
            (new PresentationSelection('encode', 'source-hash', 2, 4, 'mp4', 'config-two'))->canonicalKey(),
        );
    }

    public function testCopyJobRetainsSourceDimensionsWithoutAQualityTier(): void
    {
        $selection = new PresentationSelection('copy', 'source-hash', 2, null, 'mp4');
        $job = TranscodeJob::createCopy(Uuid::generate(), '/cache', Uuid::generate(), $selection, 1920, 1080);
        self::assertSame('copy', $job->getQualityTierName());
        self::assertSame($selection, $job->getPresentationSelection());
        self::assertSame(1920, $job->getWidth());
        self::assertSame(1080, $job->getHeight());
    }

    #[DataProvider('invalidSelections')]
    public function testInvalidSelectionsAreRejected(string $mode, string $hash, ?int $video, ?int $audio, string $container, ?string $configuration): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PresentationSelection($mode, $hash, $video, $audio, $container, $configuration);
    }

    /** @return list<array{string, string, ?int, ?int, string, ?string}> */
    public static function invalidSelections(): array
    {
        return [
            ['invalid', 'hash', 0, null, 'mp4', null],
            ['copy', '', 0, null, 'mp4', null],
            ['copy', 'hash', -1, null, 'mp4', null],
            ['copy', 'hash', null, null, 'mp4', null],
            ['copy', 'hash', 0, null, '', null],
            ['encode', 'hash', 0, null, 'mp4', null],
            ['copy', 'hash', 0, null, 'mp4', 'configuration'],
        ];
    }
}
