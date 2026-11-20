<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\OpenTelemetry;

use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Shared\Application\Settings\DiagnosticsSettingDefinitions;
use Closure;
use Throwable;

final class HttpSpanCapturePolicy
{
    private const float REFRESH_SECONDS = 5.0;

    private readonly Closure $clock;
    private float $refreshAt = 0.0;
    private bool $refreshing = false;
    private bool $enabled = false;

    /** @param (Closure(): float)|null $clock */
    public function __construct(private readonly SystemSettingsPortInterface $settings, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
    }

    public function enabled(): bool
    {
        if ($this->refreshing) {
            return false;
        }

        $now = ($this->clock)();
        if ($now < $this->refreshAt) {
            return $this->enabled;
        }

        // Publish both guards before a settings read can yield to another request.
        $this->refreshing = true;
        $this->refreshAt = $now + self::REFRESH_SECONDS;
        try {
            $this->enabled = $this->settings->get(DiagnosticsSettingDefinitions::SPANS_ENABLED) === true;
        } catch (Throwable) {
            $this->enabled = false;
        } finally {
            $this->refreshAt = ($this->clock)() + self::REFRESH_SECONDS;
            $this->refreshing = false;
        }

        return $this->enabled;
    }
}
