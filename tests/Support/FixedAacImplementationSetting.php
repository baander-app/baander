<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Transcode\Application\Settings\TranscodeSettingDefinitions;
use App\Transcode\Domain\ValueObject\AacImplementation;

/** Settings that answer only the AAC implementation, for harnesses without a settings table. */
final readonly class FixedAacImplementationSetting implements SystemSettingsPortInterface
{
    public function __construct(private AacImplementation $implementation = AacImplementation::Bac)
    {
    }

    public function get(string $key): string
    {
        return $key === TranscodeSettingDefinitions::AAC_IMPLEMENTATION
            ? $this->implementation->value
            : throw new \LogicException(sprintf('Setting %s is not available in this harness', $key));
    }
}
