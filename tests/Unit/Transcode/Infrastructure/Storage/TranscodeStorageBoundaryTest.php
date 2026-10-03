<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\Storage;

use App\Shared\Domain\Model\Uuid;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Infrastructure\Storage\SegmentFileResolver;
use App\Transcode\Infrastructure\Storage\TranscodeFileStorage;
use Closure;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

final class TranscodeStorageBoundaryTest extends TestCase
{
    private string $fixture;
    private string $root;
    private string $outside;
    private Uuid $videoId;
    private SegmentFileResolver $resolver;
    private TranscodeFileStorage $storage;

    protected function setUp(): void
    {
        $this->fixture = sys_get_temp_dir() . '/baander-transcode-boundary-' . bin2hex(random_bytes(8));
        $this->root = $this->fixture . '/cache';
        $this->outside = $this->fixture . '/cache-other';
        mkdir($this->root, 0700, true);
        mkdir($this->outside, 0700);
        file_put_contents($this->outside . '/sentinel', 'outside sentinel');
        $this->videoId = Uuid::generate();
        $this->resolver = new SegmentFileResolver($this->root);
        $this->storage = new TranscodeFileStorage($this->resolver);
    }

    protected function tearDown(): void
    {
        $this->removeFixture($this->fixture);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidComponents(): iterable
    {
        foreach (['', '.', '..', '../other', 'en/US', 'en\\US', "en\0US"] as $component) {
            foreach (['audio-directory', 'audio-init', 'audio-segment', 'subtitle-directory', 'subtitle-segment', 'subtitle-name', 'job-tier', 'init-tier', 'segment-tier', 'rendition-tier', 'prefix-tier'] as $builder) {
                yield $builder . '/' . bin2hex($component) => [$builder, $component];
            }
        }
    }

    #[DataProvider('invalidComponents')]
    public function testPathComponentsCannotChangeDirectoryBoundaries(string $builder, string $component): void
    {
        $this->assertRejected(function () use ($builder, $component): void {
            $tier = QualityTier::p720();
            if (str_ends_with($builder, '-tier')) {
                $tier = new QualityTier($component, $tier->height, $tier->width, $tier->videoBitrate, $tier->maxBitrate, $tier->bufferSize, $tier->codec, $tier->rfc6381Codec);
            }
            match ($builder) {
                'audio-directory' => $this->resolver->resolveAudioDirectory($this->videoId, $component),
                'audio-init' => $this->resolver->resolveAudioInitSegmentPath($this->videoId, $component),
                'audio-segment' => $this->resolver->resolveAudioSegmentPath($this->videoId, $component, 0),
                'subtitle-directory' => $this->resolver->resolveSubtitleDirectory($this->videoId, $component),
                'subtitle-segment' => $this->resolver->resolveSubtitleSegmentPath($this->videoId, $component, 'full'),
                'subtitle-name' => $this->resolver->resolveSubtitleSegmentPath($this->videoId, 'en', $component),
                'job-tier' => $this->resolver->resolveJobDirectory($this->videoId, $tier),
                'init-tier' => $this->resolver->resolveInitSegmentPath($this->videoId, $tier),
                'segment-tier' => $this->resolver->resolveSegmentPath($this->videoId, $tier, 0),
                'rendition-tier' => $this->resolver->resolveSegmentPathForRendition($this->videoId, 0, 0, $tier, 0),
                'prefix-tier' => $this->resolver->segmentPrefix(0, 0, $tier),
                default => throw new LogicException('Unknown path builder fixture.'),
            };
        });
        self::assertSame('outside sentinel', file_get_contents($this->outside . '/sentinel'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function linkedVideoBuilders(): iterable
    {
        foreach (['job', 'init', 'segment', 'rendition', 'audio-directory', 'audio-init', 'audio-segment', 'subtitle-directory', 'subtitle-segment'] as $builder) {
            foreach (['existing-outside', 'missing-outside', 'dangling'] as $target) {
                yield $builder . '/' . $target => [$builder, $target];
            }
        }
    }

    #[DataProvider('linkedVideoBuilders')]
    public function testEveryGeneratedPathRejectsExternalVideoSymlinks(string $builder, string $target): void
    {
        if ($target === 'existing-outside') {
            mkdir($this->outside . '/720p');
            mkdir($this->outside . '/audio/en', 0700, true);
            mkdir($this->outside . '/subtitles/en', 0700, true);
            file_put_contents($this->outside . '/720p/init.mp4', 'init');
            file_put_contents($this->outside . '/720p/v0_a0_720p_0.m4s', 'segment');
            file_put_contents($this->outside . '/audio/en/init.mp4', 'audio init');
            file_put_contents($this->outside . '/audio/en/seg_0.m4s', 'audio segment');
            file_put_contents($this->outside . '/subtitles/en/full.vtt', 'subtitle');
        }
        symlink($target === 'dangling' ? $this->outside . '/missing' : $this->outside, $this->root . '/' . $this->videoId->toString());

        $this->assertRejected(function () use ($builder): void {
            $this->buildPath($builder);
        });
    }

    public function testValidLanguagesAndSubtitleNamesPreserveGeneratedLayout(): void
    {
        $video = $this->root . '/' . $this->videoId->toString();
        self::assertSame($video . '/audio/en-US/init.mp4', $this->resolver->resolveAudioInitSegmentPath($this->videoId, 'en-US'));
        self::assertSame($video . '/audio/eng/seg_12.m4s', $this->resolver->resolveAudioSegmentPath($this->videoId, 'eng', 12));
        self::assertSame($video . '/subtitles/en-US/full.vtt', $this->resolver->resolveSubtitleSegmentPath($this->videoId, 'en-US', 'full'));
        self::assertSame($video . '/subtitles/eng/seg_0.vtt', $this->resolver->resolveSubtitleSegmentPath($this->videoId, 'eng', 'seg_0'));
        self::assertSame($video . '/subtitles/und/part.1.vtt', $this->resolver->resolveSubtitleSegmentPath($this->videoId, 'und', 'part.1'));
        self::assertSame($this->root . '/new/deep/file', $this->resolver->resolvePath($this->root . '/new/deep/file'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function storageEscapeOperations(): iterable
    {
        foreach (['resolvePath', 'exists', 'size'] as $operation) {
            foreach (['outside', 'traversal', 'external-link', 'external-missing', 'dangling'] as $path) {
                yield $operation . '/' . $path => [$operation, $path];
            }
        }
    }

    #[DataProvider('storageEscapeOperations')]
    public function testAbsoluteStorageOperationsRejectPathsBeyondCacheRoot(string $operation, string $path): void
    {
        symlink($this->outside, $this->root . '/external');
        symlink($this->outside . '/missing', $this->root . '/dangling');
        $candidate = match ($path) {
            'outside' => $this->outside,
            'traversal' => $this->root . '/../cache-other',
            'external-link' => $this->root . '/external',
            'external-missing' => $this->root . '/external/new/deep',
            'dangling' => $this->root . '/dangling/deep',
            default => throw new LogicException('Unknown storage path fixture.'),
        };

        $this->assertRejected(function () use ($operation, $candidate): void {
            match ($operation) {
                'resolvePath' => $this->resolver->resolvePath($candidate),
                'exists' => $this->storage->exists($candidate),
                'size' => $this->storage->getDirectorySize($candidate),
                default => throw new LogicException('Unknown storage operation fixture.'),
            };
        });
    }

    public function testDirectorySizeCountsRegularCacheFilesWithoutLinkedTargets(): void
    {
        mkdir($this->root . '/ordinary/nested', 0700, true);
        file_put_contents($this->root . '/ordinary/nested/segment', 'cache bytes');
        symlink($this->outside . '/sentinel', $this->root . '/ordinary/file-link');
        symlink($this->outside, $this->root . '/ordinary/directory-link');
        symlink($this->outside . '/missing', $this->root . '/ordinary/dangling-link');

        self::assertSame(11, $this->storage->getDirectorySize($this->root . '/ordinary'));
        self::assertSame(0, $this->storage->getDirectorySize($this->root . '/missing/deep'));
        self::assertFalse($this->storage->exists($this->root . '/missing/deep'));
    }

    public function testVideoEnumerationSkipsAllDirectoryLinksAndPreservesOrdinaryDirectories(): void
    {
        mkdir($this->root . '/ordinary');
        symlink($this->outside, $this->root . '/external');
        symlink($this->root . '/ordinary', $this->root . '/internal');
        symlink($this->outside . '/missing', $this->root . '/dangling');
        file_put_contents($this->root . '/regular-file', 'file');

        self::assertSame(['ordinary'], $this->resolver->getVideoDirectories());
        self::assertSame(['ordinary'], $this->storage->getVideoDirectories());
    }

    public function testConfiguredRootAliasProducesPathsCompatibleWithDeletion(): void
    {
        $alias = $this->fixture . '/cache-alias';
        symlink($this->root, $alias);
        $resolver = new SegmentFileResolver($alias);
        $storage = new TranscodeFileStorage($resolver);
        $directory = $resolver->resolveJobDirectory($this->videoId, QualityTier::p720());
        self::assertSame($alias . '/' . $this->videoId->toString() . '/720p', $directory);
        mkdir($directory, 0700, true);
        file_put_contents($directory . '/segment', 'cache');
        self::assertTrue($storage->exists($directory . '/segment'));
        self::assertSame(5, $storage->getDirectorySize($directory));

        $storage->deleteDirectory($directory);

        self::assertDirectoryDoesNotExist($directory);
        self::assertTrue(is_link($alias));
        self::assertDirectoryExists($this->root);
        self::assertSame('outside sentinel', file_get_contents($this->outside . '/sentinel'));
    }

    private function buildPath(string $builder): string
    {
        $tier = QualityTier::p720();
        return match ($builder) {
            'job' => $this->resolver->resolveJobDirectory($this->videoId, $tier),
            'init' => $this->resolver->resolveInitSegmentPath($this->videoId, $tier),
            'segment' => $this->resolver->resolveSegmentPath($this->videoId, $tier, 0),
            'rendition' => $this->resolver->resolveSegmentPathForRendition($this->videoId, 0, 0, $tier, 0),
            'audio-directory' => $this->resolver->resolveAudioDirectory($this->videoId, 'en'),
            'audio-init' => $this->resolver->resolveAudioInitSegmentPath($this->videoId, 'en'),
            'audio-segment' => $this->resolver->resolveAudioSegmentPath($this->videoId, 'en', 0),
            'subtitle-directory' => $this->resolver->resolveSubtitleDirectory($this->videoId, 'en'),
            'subtitle-segment' => $this->resolver->resolveSubtitleSegmentPath($this->videoId, 'en', 'full'),
            default => throw new LogicException('Unknown generated path fixture.'),
        };
    }

    private function assertRejected(Closure $operation): void
    {
        try {
            $operation();
        } catch (Throwable $error) {
            self::assertInstanceOf(InvalidArgumentException::class, $error);
            return;
        }
        self::fail('The transcode storage operation accepted a path outside its boundary.');
    }

    private function removeFixture(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
            unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeFixture($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
