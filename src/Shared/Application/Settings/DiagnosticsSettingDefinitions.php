<?php

declare(strict_types=1);

namespace App\Shared\Application\Settings;

use App\Shared\Application\Port\SettingDefinitionProviderInterface;
use App\Shared\Domain\Model\Setting\SettingDefinition;
use App\Shared\Domain\Model\Setting\SettingScope;
use App\Shared\Domain\Model\Setting\SettingValueType;

final readonly class DiagnosticsSettingDefinitions implements SettingDefinitionProviderInterface
{
    public const string SPANS_ENABLED = 'diagnostics.spans_enabled';

    public function __construct(private string $environment)
    {
    }

    public function definitions(): iterable
    {
        yield new SettingDefinition(
            key: self::SPANS_ENABLED,
            type: SettingValueType::Boolean,
            scope: SettingScope::System,
            label: 'HTTP request spans',
            description: 'Capture HTTP request timing in diagnostics. Changes reach each worker within five seconds. Export also requires OTEL_ENABLED.',
            group: 'Diagnostics',
            default: $this->environment === 'dev',
        );
    }
}
