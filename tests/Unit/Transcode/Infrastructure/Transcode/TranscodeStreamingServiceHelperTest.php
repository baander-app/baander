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

    // --- Reflection helpers ---

    private function invokeAudioCodecToRfc6381(string $codec): string
    {
        $method = new ReflectionMethod(TranscodeStreamingService::class, 'audioCodecToRfc6381');

        return $method->invoke($this->getService(), $codec);
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
