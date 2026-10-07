<?php

declare(strict_types=1);

namespace App\Shared\Domain\Model\Setting;

/**
 * Why a value was rejected for one setting key.
 */
final readonly class SettingViolation
{
    public function __construct(
        public string $key,
        public string $message,
    ) {
    }
}
