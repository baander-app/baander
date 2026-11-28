<?php

declare(strict_types=1);

namespace App\Metadata\Application\Exception;

use App\Metadata\Application\Settings\MetadataProvider;
use App\Shared\Application\Exception\ServiceUnavailableException;

final class MetadataProviderDisabled extends ServiceUnavailableException
{
    public function __construct(public readonly MetadataProvider $provider)
    {
        parent::__construct(sprintf('%s is switched off.', $provider->label()), [
            'reason' => 'provider_disabled',
            'provider' => $provider->value,
        ]);
    }
}
