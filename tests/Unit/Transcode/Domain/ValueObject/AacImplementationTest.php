<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Domain\ValueObject;

use App\Transcode\Application\Settings\TranscodeSettingDefinitions;
use App\Transcode\Domain\ValueObject\AacImplementation;
use PHPUnit\Framework\TestCase;

class AacImplementationTest extends TestCase
{
    public function testEnumHasThreeCases(): void
    {
        $this->assertCount(3, AacImplementation::cases());
    }

    public function testEnumValues(): void
    {
        $this->assertSame('bac', AacImplementation::Bac->value);
        $this->assertSame('fdk_aac', AacImplementation::FdkAac->value);
        $this->assertSame('ffmpeg', AacImplementation::Ffmpeg->value);
    }

    public function testCodecNamesAndLabels(): void
    {
        $this->assertSame('libbaac', AacImplementation::Bac->encoderName());
        $this->assertSame('libbaac', AacImplementation::Bac->decoderName());
        $this->assertSame('libfdk_aac', AacImplementation::FdkAac->encoderName());
        $this->assertSame('libfdk_aac', AacImplementation::FdkAac->decoderName());
        $this->assertSame('aac', AacImplementation::Ffmpeg->encoderName());
        $this->assertSame('aac', AacImplementation::Ffmpeg->decoderName());
        $this->assertSame('Bånder Audio Core', AacImplementation::Bac->label());
        $this->assertSame('Fraunhofer fdk-aac', AacImplementation::FdkAac->label());
        $this->assertSame('FFmpeg built-in', AacImplementation::Ffmpeg->label());
    }

    public function testUnknownValueIsRejected(): void
    {
        $this->expectException(\ValueError::class);
        AacImplementation::from('xhe');
    }

    public function testTheSettingDefinitionAgreesWithTheEnum(): void
    {
        $definition = null;
        foreach ((new TranscodeSettingDefinitions())->definitions() as $candidate) {
            if ($candidate->key === TranscodeSettingDefinitions::AAC_IMPLEMENTATION) {
                $definition = $candidate;
            }
        }
        $this->assertNotNull($definition);
        $this->assertSame(array_map(static fn (AacImplementation $case): string => $case->value, AacImplementation::cases()), $definition->allowedValues);
        $this->assertSame(AacImplementation::Bac->value, $definition->default);
        foreach (AacImplementation::cases() as $case) {
            $this->assertSame($case->label(), $definition->valueLabels[$case->value]);
        }
    }
}
