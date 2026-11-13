<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\FFmpeg;

use App\Shared\Application\DTO\DeploymentProfile;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Transcode\Application\Exception\MediaEncodingProhibitedException;
use App\Transcode\Application\Port\FFmpegPortInterface;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Application\Service\MediaExecutionPolicy;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Infrastructure\FFmpeg\FFmpegAdapter;
use App\Transcode\Infrastructure\FFmpeg\FFprobeAdapter;
use App\Transcode\Infrastructure\FFmpeg\SegmentEncoder;
use App\Transcode\Infrastructure\HLS\FMP4SegmentWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class MediaExecutionBoundaryTest extends TestCase
{
    #[DataProvider('adapterOperations')]
    public function testRemuxRejectsEveryTypedAdapterEncoderBeforeSourceOrOutputAccess(string $operation): void
    {
        $policy = new MediaExecutionPolicy(new DeploymentProfile('remux', 2, 4, 1, sharedCopySlots: 2));
        $adapter = new FFmpegAdapter($policy, new NullLogger(), new FFprobeAdapter(new JsonEncoder()), new FMP4SegmentWriter($policy), new JsonEncoder(), $this->createStub(SystemSettingsPortInterface::class));
        $this->expectException(MediaEncodingProhibitedException::class);
        match ($operation) {
            'video init' => $adapter->encodeInitSegment('/absent/source', QualityTier::p720(), '/absent/output'),
            'video segment' => $adapter->encodeSegment('/absent/source', 0, 6, QualityTier::p720(), [], '', '', '/absent/output'),
            'audio init' => $adapter->encodeAudioInitSegment('/absent/source', [], '/absent/output'),
            'audio segment' => $adapter->encodeAudioSegment('/absent/source', 0, 6, [], '', '/absent/output'),
            default => self::fail('Unknown adapter operation.'),
        };
    }

    /** @return iterable<string, array{string}> */
    public static function adapterOperations(): iterable
    {
        foreach (['video init', 'video segment', 'audio init', 'audio segment'] as $operation) {
            yield $operation => [$operation];
        }
    }

    public function testRemuxCannotBuildExecutableEncodedStreamArguments(): void
    {
        $encoder = new SegmentEncoder(new MediaExecutionPolicy(new DeploymentProfile('remux', 2, 4, 1, sharedCopySlots: 2)), $this->createStub(FFmpegPortInterface::class), $this->createStub(TranscodeStoragePortInterface::class));
        $this->expectException(MediaEncodingProhibitedException::class);
        $encoder->buildStreamArgs('/source', QualityTier::p720(), '', '/output');
    }
}
