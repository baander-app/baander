<?php

declare(strict_types=1);

namespace Tests\Unit\Transcode\Infrastructure\Transcode;

use App\Transcode\Infrastructure\Transcode\TranscodeStreamingService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests for pure-logic private helpers in TranscodeStreamingService.
 *
 * The service's public methods require heavy infrastructure (repositories,
 * storage, manifest generators), so we test the utility methods via reflection.
 * Since the class is final, we cannot mock it — we invoke private methods
 * through reflection on a real instance with null constructor deps.
 */
final class TranscodeStreamingServiceHelperTest extends TestCase
{
    private ?TranscodeStreamingService $service = null;

    //
    // audioCodecToRfc6381()
    //

    public function testAudioCodecToRfc6381MapsAac(): void
    {
        $this->assertSame('mp4a.40.2', $this->invokeAudioCodecToRfc6381('aac'));
    }

    public function testAudioCodecToRfc6381MapsAacLc(): void
    {
        $this->assertSame('mp4a.40.2', $this->invokeAudioCodecToRfc6381('aac-lc'));
        $this->assertSame('mp4a.40.2', $this->invokeAudioCodecToRfc6381('aac_lc'));
    }

    public function testAudioCodecToRfc6381MapsHeAac(): void
    {
        $this->assertSame('mp4a.40.5', $this->invokeAudioCodecToRfc6381('heaac'));
        $this->assertSame('mp4a.40.5', $this->invokeAudioCodecToRfc6381('he-aac'));
        $this->assertSame('mp4a.40.5', $this->invokeAudioCodecToRfc6381('heaacv1'));
    }

    public function testAudioCodecToRfc6381MapsHeAacV2(): void
    {
        $this->assertSame('mp4a.40.29', $this->invokeAudioCodecToRfc6381('heaacv2'));
        $this->assertSame('mp4a.40.29', $this->invokeAudioCodecToRfc6381('he-aacv2'));
    }

    public function testAudioCodecToRfc6381MapsOpus(): void
    {
        $this->assertSame('Opus', $this->invokeAudioCodecToRfc6381('opus'));
    }

    public function testAudioCodecToRfc6381FallsBackToAacLc(): void
    {
        $this->assertSame('mp4a.40.2', $this->invokeAudioCodecToRfc6381('unknown'));
        $this->assertSame('mp4a.40.2', $this->invokeAudioCodecToRfc6381('vorbis'));
    }

    //
    // extractAudioSegmentsForLanguage()
    //

    public function testExtractAudioSegmentsForLanguageFiltersByLanguage(): void
    {
        $map = [
            'en:0' => ['path' => '/en/0.m4s', 'size' => 100, 'duration' => 6.0],
            'en:1' => ['path' => '/en/1.m4s', 'size' => 200, 'duration' => 5.98],
            'fr:0' => ['path' => '/fr/0.m4s', 'size' => 150, 'duration' => 6.0],
            'fr:1' => ['path' => '/fr/1.m4s', 'size' => 160, 'duration' => 6.0],
        ];

        $result = $this->invokeExtractAudioSegmentsForLanguage($map, 'en');

        $this->assertCount(2, $result);
        $this->assertSame('/en/0.m4s', $result[0]['path']);
        $this->assertSame(6.0, $result[0]['duration']);
        $this->assertSame('/en/1.m4s', $result[1]['path']);
    }

    public function testExtractAudioSegmentsForLanguageReturnsSortedByIndex(): void
    {
        $map = [
            'en:3' => ['path' => '/en/3.m4s', 'size' => 100, 'duration' => 6.0],
            'en:1' => ['path' => '/en/1.m4s', 'size' => 100, 'duration' => 6.0],
            'en:0' => ['path' => '/en/0.m4s', 'size' => 100, 'duration' => 6.0],
            'en:2' => ['path' => '/en/2.m4s', 'size' => 100, 'duration' => 6.0],
        ];

        $result = $this->invokeExtractAudioSegmentsForLanguage($map, 'en');

        $keys = array_keys($result);
        $this->assertSame([0, 1, 2, 3], $keys);
    }

    public function testExtractAudioSegmentsForLanguageReturnsEmptyForMissingLanguage(): void
    {
        $map = [
            'en:0' => ['path' => '/en/0.m4s', 'size' => 100, 'duration' => 6.0],
        ];

        $result = $this->invokeExtractAudioSegmentsForLanguage($map, 'de');

        $this->assertSame([], $result);
    }

    public function testExtractAudioSegmentsForLanguageReturnsEmptyForEmptyMap(): void
    {
        $result = $this->invokeExtractAudioSegmentsForLanguage([], 'en');

        $this->assertSame([], $result);
    }

    public function testExtractAudioSegmentsForLanguageDoesNotCrossLanguagePrefixes(): void
    {
        // Language "en" should NOT match "en-US:0"
        $map = [
            'en:0' => ['path' => '/en/0.m4s', 'size' => 100, 'duration' => 6.0],
            'en-US:0' => ['path' => '/en-us/0.m4s', 'size' => 100, 'duration' => 6.0],
        ];

        $result = $this->invokeExtractAudioSegmentsForLanguage($map, 'en');

        $this->assertCount(1, $result);
        $this->assertArrayHasKey(0, $result);
        $this->assertSame('/en/0.m4s', $result[0]['path']);
    }

    // --- Reflection helpers ---

    private function invokeAudioCodecToRfc6381(string $codec): string
    {
        $method = new ReflectionMethod(TranscodeStreamingService::class, 'audioCodecToRfc6381');

        return $method->invoke($this->getService(), $codec);
    }

    private function invokeExtractAudioSegmentsForLanguage(array $map, string $language): array
    {
        $method = new ReflectionMethod(TranscodeStreamingService::class, 'extractAudioSegmentsForLanguage');

        return $method->invoke($this->getService(), $map, $language);
    }

    /**
     * Create a TranscodeStreamingService with all constructor dependencies set to null.
     * The private methods under test don't use any constructor deps, so null is safe.
     */
    private function getService(): TranscodeStreamingService
    {
        if ($this->service !== null) {
            return $this->service;
        }

        $ref = new \ReflectionClass(TranscodeStreamingService::class);
        $this->service = $ref->newLazyGhost(function (TranscodeStreamingService $object): void {
            // No initialization needed — private methods don't access constructor deps
        });

        return $this->service;
    }
}
