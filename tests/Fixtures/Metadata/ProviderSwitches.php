<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Metadata;

use App\Metadata\Application\Settings\MetadataProvider;
use App\Metadata\Application\Settings\MetadataProviderSwitches;
use App\Shared\Application\Port\SystemSettingsPortInterface;

final class ProviderSwitches
{
    public static function allEnabled(): MetadataProviderSwitches
    {
        return self::withDisabled();
    }

    public static function withDisabled(MetadataProvider ...$providers): MetadataProviderSwitches
    {
        $keys = array_map(static fn (MetadataProvider $provider): string => $provider->settingKey(), $providers);
        return new MetadataProviderSwitches(new readonly class ($keys) implements SystemSettingsPortInterface {
            /** @param array<array-key, string> $disabledKeys */
            public function __construct(private array $disabledKeys) {}

            public function get(string $key): bool
            {
                return !in_array($key, $this->disabledKeys, true);
            }
        });
    }
}
