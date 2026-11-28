<?php

declare(strict_types=1);

namespace App\Metadata\Application\Settings;

use App\Metadata\Application\Exception\MetadataProviderDisabled;
use App\Shared\Application\Port\SystemSettingsPortInterface;

final readonly class MetadataProviderSwitches
{
    public function __construct(private SystemSettingsPortInterface $settings) {}

    /** @phpstan-impure */
    public function enabled(MetadataProvider $provider): bool
    {
        return $this->settings->get($provider->settingKey()) === true;
    }

    public function requireEnabled(MetadataProvider $provider): void
    {
        if (!$this->enabled($provider)) {
            throw new MetadataProviderDisabled($provider);
        }
    }
}
