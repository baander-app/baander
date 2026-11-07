<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Transcode\Application\DTO\AudioRenditionClaim;
use App\Transcode\Application\DTO\AudioRenditionInput;
use App\Transcode\Application\Port\AudioRenditionFormat;
use App\Transcode\Domain\ValueObject\AacImplementation;
use App\Transcode\Infrastructure\Doctrine\AudioRenditionRuntime;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\TestCase;

final class AudioRenditionFailedResetTest extends TestCase
{
    use MediaRuntimePersistenceHarness;

    private function failed(AudioRenditionRuntime $runtime, ?AacImplementation $implementation): AudioRenditionClaim
    {
        $row = $runtime->admit(new AudioRenditionInput('baander-track', 'track-123.m4a', '/fixture/source.flac', AudioRenditionFormat::Aac, 256000, '/fixture/derived/track'));
        $claim = $runtime->claim($row->key, $this->worker());
        self::assertNotNull($claim);
        $directory = $claim->runtime->generationDirectory();
        self::assertTrue($runtime->start($claim, $directory . '/audio.partial', $directory . '/audio.m4a'));
        self::assertTrue($runtime->fail($claim, 'the encoder failed', $implementation));
        return $claim;
    }

    public function testFailureUnderAnotherImplementationReopensTheObservedGeneration(): void
    {
        $runtime = new AudioRenditionRuntime($this->first);
        $other = new AudioRenditionRuntime($this->second);
        $claim = $this->failed($runtime, AacImplementation::Baac);
        $key = $claim->runtime->key;
        $failed = $other->find($key);
        self::assertNotNull($failed);
        self::assertSame('failed', $failed->state);
        self::assertSame(AacImplementation::Baac, $failed->failedAacImplementation);

        self::assertTrue($other->resetFailed($key, $failed->generation, AacImplementation::Ffmpeg));
        $pending = $runtime->find($key);
        self::assertNotNull($pending);
        self::assertSame('pending', $pending->state);
        self::assertSame($failed->generation, $pending->generation);
        self::assertNull($pending->partialPath);
        self::assertNull($pending->finalPath);
        self::assertNull($pending->error);
        self::assertNull($pending->failedAacImplementation);
        self::assertFalse($pending->ownershipLive);
        self::assertSame([$key], array_map(static fn ($row): string => $row->key, $runtime->pending()));
        self::assertNull($this->second->fetchOne('SELECT claim_token FROM audio_rendition_runtime WHERE rendition_key = ?', [$key]));

        self::assertFalse($runtime->resetFailed($key, $failed->generation, AacImplementation::Ffmpeg), 'A reopened row is no longer failed.');
        self::assertFalse($runtime->renew($claim), 'The failed generation owner cannot act on the reopened row.');
        $next = $runtime->claim($key, $this->worker('b'));
        self::assertNotNull($next);
        self::assertGreaterThan($failed->generation, $next->runtime->generation);
    }

    public function testFailureUnderTheCurrentImplementationStaysFailed(): void
    {
        $runtime = new AudioRenditionRuntime($this->first);
        $claim = $this->failed($runtime, AacImplementation::Baac);
        self::assertFalse($runtime->resetFailed($claim->runtime->key, $claim->runtime->generation, AacImplementation::Baac));
        $row = $runtime->find($claim->runtime->key);
        self::assertSame('failed', $row?->state);
        self::assertSame('the encoder failed', $row->error);
        self::assertSame(AacImplementation::Baac, $row->failedAacImplementation);
    }

    public function testFailureWithoutAnImplementationStaysFailed(): void
    {
        $runtime = new AudioRenditionRuntime($this->first);
        $claim = $this->failed($runtime, null);
        foreach (AacImplementation::cases() as $current) {
            self::assertFalse($runtime->resetFailed($claim->runtime->key, $claim->runtime->generation, $current), $current->value);
        }
        $row = $runtime->find($claim->runtime->key);
        self::assertSame('failed', $row?->state);
        self::assertNull($row->failedAacImplementation);
    }

    public function testStaleGenerationDoesNotReopen(): void
    {
        $runtime = new AudioRenditionRuntime($this->first);
        $claim = $this->failed($runtime, AacImplementation::FdkAac);
        $key = $claim->runtime->key;
        self::assertFalse($runtime->resetFailed($key, $claim->runtime->generation - 1, AacImplementation::Baac));
        self::assertFalse($runtime->resetFailed($key, $claim->runtime->generation + 1, AacImplementation::Baac));
        self::assertSame('failed', $runtime->find($key)?->state);
        self::assertFalse($runtime->resetFailed('unknown-rendition', $claim->runtime->generation, AacImplementation::Baac));
    }

    public function testCompletedOutputIsNotReopenedAsAFailure(): void
    {
        $runtime = new AudioRenditionRuntime($this->first);
        $row = $runtime->admit(new AudioRenditionInput('baander-track', 'track-123.m4a', '/fixture/source.flac', AudioRenditionFormat::Aac, 256000, '/fixture/derived/track'));
        $claim = $runtime->claim($row->key, $this->worker());
        self::assertNotNull($claim);
        $directory = $claim->runtime->generationDirectory();
        self::assertTrue($runtime->start($claim, $directory . '/audio.partial', $directory . '/audio.m4a'));
        self::assertTrue($runtime->complete($claim));
        self::assertFalse($runtime->resetFailed($row->key, $claim->runtime->generation, AacImplementation::Ffmpeg));
        self::assertSame('complete', $runtime->find($row->key)?->state);
    }

    public function testTheCatalogRejectsAnImplementationOutsideAFailureOrTheKnownValues(): void
    {
        $runtime = new AudioRenditionRuntime($this->first);
        $claim = $this->failed($runtime, AacImplementation::Baac);
        foreach ([
            "UPDATE audio_rendition_runtime SET failed_aac_implementation = 'libfdk_aac'",
            "UPDATE audio_rendition_runtime SET state = 'pending'",
        ] as $statement) {
            try {
                $this->second->executeStatement($statement);
                self::fail($statement . ' was accepted.');
            } catch (DriverException $error) {
                self::assertSame('23514', $error->getSQLState(), $statement);
                self::assertStringContainsString('chk_audio_rendition_runtime_failed_aac_implementation', $error->getMessage());
            }
        }
        self::assertSame(AacImplementation::Baac, $runtime->find($claim->runtime->key)?->failedAacImplementation);
    }
}
