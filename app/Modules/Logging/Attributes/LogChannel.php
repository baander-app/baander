<?php

namespace App\Modules\Logging\Attributes;

use App\Modules\Logging\Channel;
use Attribute;

/**
 * Marks a property for automatic logger injection by LoggerServiceProvider
 *
 * @\Attribute(Attribute::TARGET_PROPERTY)
 * @psalm-suppress PossiblyUnusedProperty
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class LogChannel
{
    public function __construct(
        public readonly Channel $channel,
        public readonly bool $includeClass = true,
        public readonly bool $includeMethod = false,
        public readonly array $defaultContext = []
    ) {}
}
