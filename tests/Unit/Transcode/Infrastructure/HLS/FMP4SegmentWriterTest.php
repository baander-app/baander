<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\HLS;

use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Infrastructure\HLS\FMP4SegmentWriter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class FMP4SegmentWriterTest extends TestCase
{
    private string $directory;
    private string $binary;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-fmp4-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        $this->binary = $this->directory . '/ffmpeg';
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testHouseholdRejectsBeforeCreatingOutputOrRunningEncoder(): void
    {
        $output = $this->directory . '/forbidden/init.mp4';
        $policy = new \App\Transcode\Application\Service\MediaExecutionPolicy(new \App\Shared\Application\DTO\DeploymentProfile('household'));
        $writer = new FMP4SegmentWriter($policy, $this->binary);
        try {
            $writer->encodeInitSegment('/media/input.mkv', QualityTier::p720(), $output);
            self::fail('Household encoding was accepted.');
        } catch (\App\Transcode\Application\Exception\MediaEncodingProhibitedException $error) {
            self::assertSame('encoding_prohibited', $error->details['reason']);
            self::assertDirectoryDoesNotExist(dirname($output));
        }
    }

    public function testInitSegmentRunsTheConfiguredEncoderAndReturnsItsOutputPath(): void
    {
        file_put_contents($this->binary, <<<'SH'
            #!/bin/sh
            for output_path
            do
                :
            done
            printf 'fixture-init' > "$output_path"
            SH);
        chmod($this->binary, 0700);
        $writer = new FMP4SegmentWriter(new \App\Transcode\Application\Service\MediaExecutionPolicy(new \App\Shared\Application\DTO\DeploymentProfile('full')), $this->binary);
        $output = $this->directory . '/rendition/init.mp4';

        $result = $writer->encodeInitSegment('/media/input.mkv', QualityTier::p720(), $output);

        self::assertSame($output, $result);
        self::assertSame('fixture-init', file_get_contents($output));
    }

    public function testEncoderFailureIncludesTheCapturedDiagnostic(): void
    {
        file_put_contents($this->binary, <<<'SH'
            #!/bin/sh
            printf 'fixture encoder failure' >&2
            exit 7
            SH);
        chmod($this->binary, 0700);
        $writer = new FMP4SegmentWriter(new \App\Transcode\Application\Service\MediaExecutionPolicy(new \App\Shared\Application\DTO\DeploymentProfile('full')), $this->binary);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Init segment encoding failed: fixture encoder failure');

        $writer->encodeInitSegment('/media/input.mkv', QualityTier::p720(), $this->directory . '/init.mp4');
    }
}
